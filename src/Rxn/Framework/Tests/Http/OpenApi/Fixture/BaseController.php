<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\OpenApi\Fixture;

/**
 * Base fixture for OpenApi tests; the `parentOnly_v1` method lets the
 * test assert that inherited actions do NOT leak into the generated
 * spec (we want per-subclass operations only).
 */
class BaseController
{
    public function parentOnly_v1(): array
    {
        return [];
    }
}
