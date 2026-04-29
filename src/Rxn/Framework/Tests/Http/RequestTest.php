<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Config;
use Rxn\Framework\Http\Collector;
use Rxn\Framework\Http\Request;

/**
 * Drives the convention-routing parsing layer in Request without
 * booting the full framework. Each case sets the request globals,
 * builds a real Collector + Config, then asserts on the request's
 * derived controller/action coordinates.
 */
final class RequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $previousGet;
    /** @var array<string, mixed> */
    private array $previousPost;
    /** @var string|false */
    private string|false $previousNamespace;

    protected function setUp(): void
    {
        $this->previousGet       = $_GET;
        $this->previousPost      = $_POST;
        $this->previousNamespace = getenv('APP_NAMESPACE');
        // Force a known product namespace so the controller-ref
        // assertion is independent of the host process env.
        putenv('APP_NAMESPACE=Sample');
    }

    protected function tearDown(): void
    {
        $_GET  = $this->previousGet;
        $_POST = $this->previousPost;
        if ($this->previousNamespace === false) {
            putenv('APP_NAMESPACE');
        } else {
            putenv('APP_NAMESPACE=' . $this->previousNamespace);
        }
    }

    private function buildRequest(array $get): Request
    {
        $_GET  = $get;
        $_POST = [];
        $config    = new Config();
        $collector = new Collector($config);
        return new Request($collector, $config);
    }

    public function testParsesControllerActionAndVersionFromConventionParams(): void
    {
        // Convention is "v{N}.{M}" — split on '.' for controller / action.
        $request = $this->buildRequest([
            'version'    => 'v1.2',
            'controller' => 'product_catalog',
            'action'     => 'list',
        ]);

        $this->assertTrue($request->isValidated());
        $this->assertSame('product_catalog', $request->getControllerName());
        $this->assertSame('v1', $request->getControllerVersion());
        $this->assertSame('list', $request->getActionName());
        $this->assertSame('v2', $request->getActionVersion());
    }

    public function testControllerRefFollowsMakeControllerLayout(): void
    {
        $request = $this->buildRequest([
            'version'    => 'v3.0',
            'controller' => 'product_catalog',
            'action'     => 'show',
        ]);

        $this->assertSame(
            'Sample\\Http\\Controller\\v3\\Product_CatalogController',
            $request->getControllerRef()
        );
    }

    public function testControllerRefIsNullWhenControllerNameMissing(): void
    {
        // Absent controller param → createControllerName returns null,
        // which short-circuits createControllerRef to null.
        $request = $this->buildRequest([
            'version' => 'v1.0',
            'action'  => 'list',
        ]);

        // validateRequiredParams should already have flipped this off.
        $this->assertFalse($request->isValidated());
        $this->assertNull($request->getControllerName());
        // Even without validation passing, the ref derivation runs and
        // tolerates a missing name.
        $this->assertNull($request->getControllerRef());
    }

    public function testMissingRequiredParamCapturesValidationException(): void
    {
        $request = $this->buildRequest([
            'version'    => 'v1.0',
            'controller' => 'orders',
            // 'action' is absent
        ]);

        $this->assertFalse($request->isValidated());
        $exception = $request->getException();
        $this->assertNotNull($exception);
        $this->assertStringContainsString('action', $exception->getMessage());
    }

    public function testStringToUpperCamelHandlesDelimited(): void
    {
        $request = $this->buildRequest([
            'version'    => 'v1.0',
            'controller' => 'orders',
            'action'     => 'list',
        ]);

        $this->assertSame('Foo_Bar', $request->stringToUpperCamel('foo_bar', '_'));
        $this->assertSame('Foo', $request->stringToUpperCamel('foo'));
        // No delimiter present → falls through to plain ucfirst.
        $this->assertSame('Foobar', $request->stringToUpperCamel('foobar', '_'));
    }

    public function testCollectorAccessorRoundTrips(): void
    {
        $request = $this->buildRequest([
            'version'    => 'v1.0',
            'controller' => 'orders',
            'action'     => 'list',
        ]);

        $this->assertSame($request->getCollector()->getFromGet(), [
            'version'    => 'v1.0',
            'controller' => 'orders',
            'action'     => 'list',
        ]);
    }
}
