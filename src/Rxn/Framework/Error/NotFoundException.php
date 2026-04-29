<?php declare(strict_types=1);

namespace Rxn\Framework\Error;

/**
 * "No route matches this request." Raised when the convention
 * router can't resolve a URL into a controller — either because
 * the convention parameters (version / controller / action) are
 * missing from the request, or the resolved controller class
 * doesn't exist.
 *
 * Defaults to HTTP 404. App::renderFailure picks the code up via
 * Response::getErrorCode and emits a clean Problem Details
 * envelope with `status: 404`.
 *
 * Distinct from `RequestException`'s 500 default — the framework
 * treats an unrouteable URL as a client-visible "resource doesn't
 * exist", which is what 404 means in HTTP. Other RequestException
 * shapes (malformed input, validation, etc.) keep the 500 default
 * unless raised with an explicit code.
 */
class NotFoundException extends RequestException
{
    public function __construct(string $message = 'No route matches this request', int $code = 404, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
