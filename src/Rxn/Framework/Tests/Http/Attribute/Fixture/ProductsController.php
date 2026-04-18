<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Attribute\Fixture;

use Rxn\Framework\Http\Attribute\Middleware;
use Rxn\Framework\Http\Attribute\Route;

#[Middleware(SampleMiddleware::class)]
final class ProductsController
{
    #[Route('GET', '/products/{id:int}', name: 'products.show')]
    public function show(int $id): array
    {
        return ['id' => $id];
    }

    #[Route('POST', '/products')]
    #[Middleware(OtherMiddleware::class)]
    public function create(): array
    {
        return [];
    }

    #[Route('GET', '/products')]
    #[Route('HEAD', '/products')]
    public function index(): array
    {
        return [];
    }

    public function notARoute(): array
    {
        return [];
    }
}
