<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\OpenApi\Fixture\v1;

use Rxn\Framework\Tests\Http\OpenApi\Fixture\BaseController;

final class ProductsController extends BaseController
{
    /**
     * Show a product by id.
     */
    public function show_v1(int $id, string $filter = 'all', bool $verbose = false): array
    {
        return [];
    }
}
