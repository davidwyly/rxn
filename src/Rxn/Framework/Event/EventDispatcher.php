<?php declare(strict_types=1);

namespace Rxn\Framework\Event;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * PSR-14 EventDispatcher. Iterates the listener provider's
 * listeners for the dispatched event, calls each one with the
 * event, and stops early when a `StoppableEventInterface` reports
 * `isPropagationStopped()`. The same event instance is returned —
 * listeners typically mutate it in place.
 *
 *   $provider   = new ListenerProvider();
 *   $dispatcher = new EventDispatcher($provider);
 *
 *   $provider->listen(IdempotencyHit::class, $logger);
 *   $dispatcher->dispatch(new IdempotencyHit('k-42', 200, 'fp'));
 *
 * No-op when the provider has no listeners for the event.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private readonly ListenerProviderInterface $provider,
    ) {}

    public function dispatch(object $event): object
    {
        $stoppable = $event instanceof StoppableEventInterface;
        foreach ($this->provider->getListenersForEvent($event) as $listener) {
            if ($stoppable && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }
        return $event;
    }
}
