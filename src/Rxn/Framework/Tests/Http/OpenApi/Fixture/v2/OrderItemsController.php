<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\OpenApi\Fixture\v2;

use Rxn\Framework\Tests\Http\OpenApi\Fixture\BaseController;

final class OrderItemsController extends BaseController
{
    public function ship_v3(int $order_id): array
    {
        return [];
    }
}
