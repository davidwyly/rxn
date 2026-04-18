<?php declare(strict_types=1);

namespace Rxn\Framework;

/**
 * Framework-level defaults. App-level overrides go on the concrete
 * Config subclass. Only the fields actually read at runtime live
 * here; every other knob the old BaseConfig carried had zero
 * callers.
 */
class BaseConfig extends Service
{
    /**
     * Maximum session lifetime in seconds. Drives both the
     * server-side `session.gc_maxlifetime` ini and the client-side
     * cookie lifetime set by `Rxn\Framework\Http\Router\Session`.
     */
    public int $session_lifetime = 2400;

    /**
     * URL segments that make up a convention-routed request:
     * `version/controller/action/key/value/...`.
     *
     * @var string[]
     */
    public array $endpoint_parameters = ['version', 'controller', 'action'];
}
