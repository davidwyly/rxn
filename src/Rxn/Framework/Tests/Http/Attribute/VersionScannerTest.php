<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Attribute;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Container;
use Rxn\Framework\Http\Attribute\Scanner;
use Rxn\Framework\Http\Router;
use Rxn\Framework\Http\Versioning\Deprecation;
use Rxn\Framework\Tests\Http\Attribute\Fixture\ClassVersionedController;
use Rxn\Framework\Tests\Http\Attribute\Fixture\DeprecatedVersionController;
use Rxn\Framework\Tests\Http\Attribute\Fixture\VersionedController;

/**
 * Scanner integration tests for the `#[Version]` attribute.
 *
 *   1. Method-level `#[Version]` prefixes the route path.
 *   2. Class-level `#[Version]` applies to every route in the class.
 *   3. Method-level `#[Version]` wins over class-level.
 *   4. Routes without `#[Version]` are registered as-is — no
 *      magic prefixing of unannotated routes in a class that
 *      doesn't have a class-level version either.
 *   5. `#[Version]` with `deprecatedAt` / `sunsetAt` auto-attaches
 *      a `Versioning\Deprecation` middleware that emits the
 *      RFC 8594 headers on the route's responses.
 *   6. Path-prefix idempotence: a route already prefixed with
 *      `/v1` and decorated with `#[Version('v1')]` doesn't
 *      become `/v1/v1/...`.
 */
final class VersionScannerTest extends TestCase
{
    public function testMethodLevelVersionPrefixesRoute(): void
    {
        $router = $this->scan([VersionedController::class]);

        // Both versions register at distinct paths and route to
        // different methods on the same controller.
        $v1 = $router->match('GET', '/v1/widgets/42');
        $v2 = $router->match('GET', '/v2/widgets/42');

        $this->assertNotNull($v1);
        $this->assertNotNull($v2);
        $this->assertSame([VersionedController::class, 'showV1'], $v1['handler']);
        $this->assertSame([VersionedController::class, 'showV2'], $v2['handler']);
    }

    public function testUnversionedRoutesInVersionedControllerStayUnprefixed(): void
    {
        // The `health()` method in `VersionedController` has no
        // `#[Version]` and the class has no class-level version.
        // It must register at `/widgets/health`, not `/v1/...` or
        // anything else inferred.
        $router = $this->scan([VersionedController::class]);

        $this->assertNotNull($router->match('GET', '/widgets/health'));
        $this->assertNull($router->match('GET', '/v1/widgets/health'));
    }

    public function testClassLevelVersionAppliesToEveryRoute(): void
    {
        $router = $this->scan([ClassVersionedController::class]);

        $this->assertNotNull($router->match('GET', '/v3/sprockets'));
        $this->assertNotNull($router->match('GET', '/v3/sprockets/42'));
    }

    public function testMethodLevelVersionOverridesClassLevel(): void
    {
        $router = $this->scan([ClassVersionedController::class]);

        // `legacy()` is method-level v9 — class-level v3 is
        // ignored for this route. Look it up at /v9/...; /v3/...
        // must not match the legacy handler.
        $hit = $router->match('GET', '/v9/sprockets/legacy');
        $this->assertNotNull($hit);
        $this->assertSame([ClassVersionedController::class, 'legacy'], $hit['handler']);

        $this->assertNull(
            $router->match('GET', '/v3/sprockets/legacy'),
            'class-level v3 must not register the legacy route at /v3/...',
        );
    }

    public function testNoCrossVersionConflict(): void
    {
        // /v1/widgets/{id} and /v2/widgets/{id} are different
        // paths — they must not interfere. The detector test
        // (ConflictDetector) covers this from the static-analysis
        // side; this test covers the runtime side.
        $router = $this->scan([VersionedController::class]);

        $this->assertNotNull($router->match('GET', '/v1/widgets/1'));
        $this->assertNotNull($router->match('GET', '/v2/widgets/1'));
    }

    public function testDeprecatedVersionAttachesDeprecationMiddleware(): void
    {
        $router = $this->scan([DeprecatedVersionController::class]);

        $hit = $router->match('GET', '/v1/old/42');
        $this->assertNotNull($hit);

        // Exactly one middleware on the route — the auto-attached
        // Deprecation. (The fixture has no other class-level or
        // method-level middlewares.)
        $this->assertCount(1, $hit['middlewares']);
        $this->assertInstanceOf(Deprecation::class, $hit['middlewares'][0]);
    }

    public function testDeprecationMiddlewareEmitsRfc8594Headers(): void
    {
        $router = $this->scan([DeprecatedVersionController::class]);
        $hit    = $router->match('GET', '/v1/old/42');

        // Drive the middleware end-to-end via a stub terminal so
        // the test asserts the actual response shape (header
        // formatting, presence of both headers).
        $terminal = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}');
            }
        };

        $mw       = $hit['middlewares'][0];
        $response = $mw->process(new ServerRequest('GET', 'http://example.test/v1/old/42'), $terminal);

        // RFC 7231 IMF-fixdate: "Day, DD Mon YYYY HH:MM:SS GMT"
        $this->assertSame('Thu, 01 Jan 2026 00:00:00 GMT', $response->getHeaderLine('Deprecation'));
        $this->assertSame('Thu, 31 Dec 2026 00:00:00 GMT', $response->getHeaderLine('Sunset'));
    }

    public function testNonDeprecatedVersionDoesNotAttachMiddleware(): void
    {
        // VersionedController has plain `#[Version('v1')]` /
        // `#[Version('v2')]` — no deprecatedAt or sunsetAt. The
        // Scanner must NOT attach a Deprecation middleware in
        // that case (it'd be an empty no-op slot, but it'd still
        // wrap a request with no signal value).
        $router = $this->scan([VersionedController::class]);
        $hit    = $router->match('GET', '/v1/widgets/42');
        $this->assertSame([], $hit['middlewares']);
    }

    public function testPathAlreadyPrefixedIsNotDoublePrefixed(): void
    {
        // Apps that hand-write `/v1/foo` in #[Route] AND mark the
        // method `#[Version('v1')]` should still register at
        // `/v1/foo`, not `/v1/v1/foo`. Defensive idempotence.
        $controller = new class {
            #[\Rxn\Framework\Http\Attribute\Route('GET', '/v1/already/{id:int}')]
            #[\Rxn\Framework\Http\Attribute\Version('v1')]
            public function show(int $id): array { return []; }
        };

        $router = $this->scan([$controller::class]);
        $this->assertNotNull($router->match('GET', '/v1/already/42'));
        $this->assertNull(
            $router->match('GET', '/v1/v1/already/42'),
            'route path that already starts with /v1 must not be re-prefixed',
        );
    }

    /** @param list<class-string> $controllers */
    private function scan(array $controllers): Router
    {
        $router = new Router();
        (new Scanner(new Container()))->register($router, $controllers);
        return $router;
    }
}
