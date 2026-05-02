<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Concurrency;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Concurrency\Promise;
use Rxn\Framework\Concurrency\Scheduler;

use function Rxn\Framework\Concurrency\awaitAll;
use function Rxn\Framework\Concurrency\awaitAny;

/**
 * Pure-fiber tests — no curl, no network. Exercises the
 * Scheduler / Promise / await* contract via in-fiber `Fiber::suspend()`
 * and external `Promise::fulfill()`. The HttpClient happy path
 * is covered by the bench (`bench/fiber/run.php`) which boots
 * real backends.
 */
final class SchedulerTest extends TestCase
{
    public function testRunReturnsBodyResult(): void
    {
        $scheduler = new Scheduler();
        $result = $scheduler->run(fn () => 42);
        $this->assertSame(42, $result);
    }

    public function testRunPropagatesExceptions(): void
    {
        $scheduler = new Scheduler();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('boom');
        $scheduler->run(function (): void { throw new \DomainException('boom'); });
    }

    public function testPromiseWaitOutsideFiberThrows(): void
    {
        $scheduler = new Scheduler();
        $promise   = new Promise($scheduler);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('outside a fiber');
        $promise->wait();
    }

    public function testPromiseAlreadySettledReturnsImmediately(): void
    {
        $scheduler = new Scheduler();
        $p1 = new Promise($scheduler);
        $p1->fulfill('hello');
        // Even outside a fiber: already-settled promises don't suspend.
        $this->assertSame('hello', $p1->wait());
    }

    public function testPromiseAlreadyRejectedRethrowsImmediately(): void
    {
        $scheduler = new Scheduler();
        $p1 = new Promise($scheduler);
        $p1->reject(new \RuntimeException('nope'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nope');
        $p1->wait();
    }

    public function testFiberSuspendsAndResumesOnExternalFulfill(): void
    {
        $scheduler = new Scheduler();
        $promise   = new Promise($scheduler);

        // Settle the promise from a separate fiber that runs first.
        // The root fiber waits on $promise; the producer fiber
        // fulfills it; the scheduler resumes the waiter on the
        // next tick.
        $result = $scheduler->run(function () use ($scheduler, $promise) {
            $producer = new \Fiber(function () use ($promise): void {
                $promise->fulfill('ready');
            });
            $producer->start();
            return $promise->wait();
        });

        $this->assertSame('ready', $result);
    }

    public function testAwaitAllReturnsKeyedMap(): void
    {
        $scheduler = new Scheduler();
        $a = new Promise($scheduler); $a->fulfill('A');
        $b = new Promise($scheduler); $b->fulfill('B');
        $c = new Promise($scheduler); $c->fulfill('C');

        $result = $scheduler->run(fn () => awaitAll(['x' => $a, 'y' => $b, 'z' => $c]));
        $this->assertSame(['x' => 'A', 'y' => 'B', 'z' => 'C'], $result);
    }

    public function testAwaitAllPropagatesFirstRejection(): void
    {
        $scheduler = new Scheduler();
        $a = new Promise($scheduler); $a->fulfill('A');
        $b = new Promise($scheduler); $b->reject(new \RuntimeException('B failed'));
        $c = new Promise($scheduler); $c->fulfill('C');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('B failed');
        $scheduler->run(fn () => awaitAll([$a, $b, $c]));
    }

    public function testAwaitAnyReturnsFirstFulfilment(): void
    {
        $scheduler = new Scheduler();
        $a = new Promise($scheduler); $a->reject(new \RuntimeException('a no'));
        $b = new Promise($scheduler); $b->fulfill('B wins');
        $c = new Promise($scheduler); $c->fulfill('C');

        $result = $scheduler->run(fn () => awaitAny([$a, $b, $c]));
        $this->assertSame('B wins', $result);
    }

    public function testAwaitAnyAllRejectionsRethrowsLast(): void
    {
        $scheduler = new Scheduler();
        $a = new Promise($scheduler); $a->reject(new \RuntimeException('a'));
        $b = new Promise($scheduler); $b->reject(new \DomainException('b'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('b');
        $scheduler->run(fn () => awaitAny([$a, $b]));
    }

    public function testPromiseDoubleSettleThrows(): void
    {
        $scheduler = new Scheduler();
        $p = new Promise($scheduler);
        $p->fulfill('once');
        $this->expectException(\LogicException::class);
        $p->fulfill('twice');
    }
}
