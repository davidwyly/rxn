<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Observability;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Codegen\Profile\BindProfile;
use Rxn\Framework\Event\EventDispatcher;
use Rxn\Framework\Event\ListenerProvider;
use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\ValidationException;
use Rxn\Framework\Observability\Event\BinderInvoked;
use Rxn\Framework\Observability\Event\FrameworkEvent;
use Rxn\Framework\Observability\Event\ValidationCompleted;
use Rxn\Framework\Observability\Events;
use Rxn\Framework\Tests\Http\Binding\Fixture\CreateProduct;

/**
 * `Binder::bind()` emits two events per call: `BinderInvoked`
 * (with path tag) and `ValidationCompleted`. Both fire on the
 * runtime walker AND the compiled fast path; the path tag tells
 * a listener which fired so they can compute compile-cache hit
 * ratio.
 */
final class BinderEmitsObservabilityEventsTest extends TestCase
{
    /** @var list<FrameworkEvent> */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->captured = [];
        BindProfile::reset();
        Binder::clearCache();
        $provider = new ListenerProvider();
        $provider->listen(FrameworkEvent::class, function (object $e): void {
            $this->captured[] = $e;
        });
        Events::useDispatcher(new EventDispatcher($provider));
    }

    protected function tearDown(): void
    {
        Events::useDispatcher(null);
        BindProfile::reset();
        Binder::clearCache();
    }

    public function testRuntimePathEmitsBinderInvokedAndValidationCompleted(): void
    {
        Binder::bind(CreateProduct::class, ['name' => 'Widget', 'price' => 9]);

        $this->assertCount(2, $this->captured);

        /** @var BinderInvoked $invoked */
        $invoked = $this->captured[0];
        $this->assertInstanceOf(BinderInvoked::class, $invoked);
        $this->assertSame(CreateProduct::class, $invoked->class);
        $this->assertSame(BinderInvoked::PATH_RUNTIME, $invoked->path);

        /** @var ValidationCompleted $valid */
        $valid = $this->captured[1];
        $this->assertInstanceOf(ValidationCompleted::class, $valid);
        $this->assertFalse($valid->isFailure());
        $this->assertSame([], $valid->failures);
    }

    public function testCompiledPathEmitsBinderInvokedWithCompiledTag(): void
    {
        Binder::compileFor(CreateProduct::class);
        // First bind() now goes through the in-memory compiled
        // closure — the BinderInvoked event must reflect that.
        $this->captured = []; // discard compileFor's own event noise (none)

        Binder::bind(CreateProduct::class, ['name' => 'X', 'price' => 1]);

        $this->assertCount(2, $this->captured);
        /** @var BinderInvoked $invoked */
        $invoked = $this->captured[0];
        $this->assertSame(BinderInvoked::PATH_COMPILED, $invoked->path);
    }

    public function testValidationFailureGroupsByFieldName(): void
    {
        try {
            Binder::bind(CreateProduct::class, ['name' => '', 'price' => 'not-a-number']);
            $this->fail('expected ValidationException');
        } catch (ValidationException) {
            // expected
        }

        $valid = null;
        foreach ($this->captured as $ev) {
            if ($ev instanceof ValidationCompleted) {
                $valid = $ev;
            }
        }
        $this->assertNotNull($valid);
        $this->assertTrue($valid->isFailure());
        // Both failed fields are present and grouped:
        $this->assertArrayHasKey('name', $valid->failures);
        $this->assertArrayHasKey('price', $valid->failures);
        $this->assertContainsOnly('string', $valid->failures['name']);
        $this->assertContainsOnly('string', $valid->failures['price']);
    }

    public function testCompiledPathStillEmitsValidationCompletedOnFailure(): void
    {
        // The compiled binder throws ValidationException directly;
        // the framework wraps it so ValidationCompleted still fires
        // before the exception escapes to the caller. Otherwise
        // metrics for the compiled path would silently lose
        // failures.
        Binder::compileFor(CreateProduct::class);
        $this->captured = [];

        try {
            Binder::bind(CreateProduct::class, ['name' => '', 'price' => -1]);
            $this->fail('expected ValidationException');
        } catch (ValidationException) {
            // expected
        }

        $sawInvoked = false;
        $sawValid   = false;
        foreach ($this->captured as $ev) {
            if ($ev instanceof BinderInvoked && $ev->path === BinderInvoked::PATH_COMPILED) {
                $sawInvoked = true;
            }
            if ($ev instanceof ValidationCompleted && $ev->isFailure()) {
                $sawValid = true;
            }
        }
        $this->assertTrue($sawInvoked, 'compiled-path BinderInvoked must fire even on failure');
        $this->assertTrue($sawValid, 'ValidationCompleted must fire even when the compiled binder throws');
    }
}
