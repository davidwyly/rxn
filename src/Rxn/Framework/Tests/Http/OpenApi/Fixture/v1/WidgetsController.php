<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\OpenApi\Fixture\v1;

use Rxn\Framework\Tests\Http\OpenApi\Fixture\BaseController;
use Rxn\Framework\Tests\Http\OpenApi\Fixture\CreateWidget;

final class WidgetsController extends BaseController
{
    public function create_v1(CreateWidget $input): array
    {
        return [];
    }

    public function list_v1(int $page = 1): array
    {
        return [];
    }
}
