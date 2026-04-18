<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Attribute\Fixture;

use Rxn\Framework\Http\Middleware as MiddlewareContract;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

final class SampleMiddleware implements MiddlewareContract
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request);
    }
}
