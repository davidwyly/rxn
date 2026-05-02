<?php declare(strict_types=1);

namespace Rxn\Framework\Concurrency;

/**
 * Minimal Promise — settle once with a value or an exception, then
 * any number of `wait()` calls return / throw the same outcome.
 *
 * Built for the fiber-await prototype: producers (HttpClient) call
 * `settle()` from inside the Scheduler's main loop; consumers
 * (handler code inside a Fiber) call `wait()`, which suspends the
 * current fiber until the producer settles. Outside a fiber
 * context, `wait()` throws — there's no event loop to drive the
 * settle, so a sync caller would deadlock.
 *
 * @template T
 */
final class Promise
{
    private const PENDING = 0;
    private const FULFILLED = 1;
    private const REJECTED = 2;

    private int $state = self::PENDING;

    /** @var T|null */
    private mixed $value = null;
    private ?\Throwable $error = null;

    /** @var list<\Fiber> Fibers parked on this promise's settle. */
    private array $waiters = [];

    public function __construct(private readonly Scheduler $scheduler) {}

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    /** @param T $value */
    public function fulfill(mixed $value): void
    {
        if ($this->state !== self::PENDING) {
            throw new \LogicException('Promise already settled');
        }
        $this->state = self::FULFILLED;
        $this->value = $value;
        $this->resumeWaiters();
    }

    public function reject(\Throwable $error): void
    {
        if ($this->state !== self::PENDING) {
            throw new \LogicException('Promise already settled');
        }
        $this->state = self::REJECTED;
        $this->error = $error;
        $this->resumeWaiters();
    }

    /**
     * Register $fiber to be enqueued for resumption when this promise
     * settles. Unlike wait(), this does NOT suspend the fiber — the
     * caller is responsible for suspending itself. If the same fiber
     * is registered with multiple promises (e.g. awaitAny), the
     * scheduler's isSuspended guard in tick() ensures extra enqueues
     * after the first resume become no-ops.
     */
    public function notifyFiberOnSettle(\Fiber $fiber): void
    {
        if ($this->state !== self::PENDING) {
            $this->scheduler->enqueueResume($fiber);
            return;
        }
        $this->waiters[] = $fiber;
    }

    /**
     * Suspend the current fiber until this promise settles, then
     * return the value (or rethrow the rejection). Must be called
     * from inside `Scheduler::run()` — outside a fiber, there's
     * no loop to drive the settle.
     *
     * @return T
     */
    public function wait(): mixed
    {
        if ($this->state === self::FULFILLED) {
            return $this->value;
        }
        if ($this->state === self::REJECTED) {
            throw $this->error;
        }
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            throw new \LogicException(
                'Promise::wait() called outside a fiber. '
                . 'Wrap the call in Scheduler::run().',
            );
        }
        $this->waiters[] = $fiber;
        // Hand control back to the scheduler. The scheduler will
        // resume this fiber via the queued waiter list once a
        // settle arrives.
        \Fiber::suspend();
        // Resumed — must be settled now.
        if ($this->state === self::REJECTED) {
            throw $this->error;
        }
        return $this->value;
    }

    private function resumeWaiters(): void
    {
        $waiters = $this->waiters;
        $this->waiters = [];
        foreach ($waiters as $fiber) {
            $this->scheduler->enqueueResume($fiber);
        }
    }
}
