<?php declare(strict_types=1);

namespace Rxn\Framework\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * PSR-14 ListenerProvider with class-hierarchy lookup. Listeners
 * are registered against an event class (or interface); when an
 * event is dispatched, the provider yields every listener whose
 * registered type the event satisfies — concrete class, parents,
 * and implemented interfaces all match.
 *
 *   $provider->listen(RequestEvent::class, fn ($e) => ...);
 *   $provider->listen(LoggableEvent::class, $logger);     // interface
 *
 *   // RequestReceived extends RequestEvent implements LoggableEvent
 *   $provider->getListenersForEvent(new RequestReceived(...));
 *   // → [the RequestEvent listener, $logger]
 *
 * Listeners run in registration order: a hierarchy walk yields
 * the event's exact class first, then parent classes, then
 * implemented interfaces. Within a single class bucket they fire
 * in the order they were registered.
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /**
     * Listener buckets keyed by the registered event class /
     * interface name. Order within a bucket is registration order.
     *
     * @var array<class-string, list<callable>>
     */
    private array $listeners = [];

    /**
     * Register a listener for events of $eventType (which can be a
     * concrete class, an abstract class, or an interface).
     *
     * @param class-string $eventType
     * @param callable(object): void $listener
     */
    public function listen(string $eventType, callable $listener): void
    {
        $this->listeners[$eventType][] = $listener;
    }

    /**
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        // Walk the class hierarchy: own class first, then parents,
        // then every implemented interface. `class_parents` /
        // `class_implements` return associative arrays keyed by FQN
        // — we only need the keys.
        $event_class = $event::class;
        if (isset($this->listeners[$event_class])) {
            yield from $this->listeners[$event_class];
        }
        $parents    = class_parents($event) ?: [];
        $interfaces = class_implements($event) ?: [];
        foreach (array_keys($parents) as $type) {
            if (isset($this->listeners[$type])) {
                yield from $this->listeners[$type];
            }
        }
        foreach (array_keys($interfaces) as $type) {
            if (isset($this->listeners[$type])) {
                yield from $this->listeners[$type];
            }
        }
    }
}
