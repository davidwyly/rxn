<?php declare(strict_types=1);

namespace Rxn\Framework\Observability\Event;

/**
 * Marker for events emitted by the framework's request lifecycle.
 * Listeners that want to receive every framework event subscribe
 * once on this interface; the PSR-14 ListenerProvider walks the
 * implemented interfaces of each dispatched event so a single
 * registration covers every concrete event type.
 *
 * Conventions for implementers:
 *
 *   - Events are immutable value objects. Listeners that need to
 *     pair "entered" with "exited" rely on a `$pairId` carried by
 *     both events — the dispatcher emits the same id for the
 *     entered/exited boundary of one execution.
 *   - Events carry only what a listener can't otherwise reach.
 *     The PSR-7 request is included on the entry events; the
 *     response on the exit events. Stack-bound state (the
 *     currently-active span, the request-scoped logger) belongs in
 *     the listener.
 *   - No OpenTelemetry / Prometheus / vendor types appear here.
 *     The events are the substrate; vendor listeners adapt them.
 */
interface FrameworkEvent
{
}
