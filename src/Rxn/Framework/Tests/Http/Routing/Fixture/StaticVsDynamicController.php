<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Routing\Fixture;

use Rxn\Framework\Http\Attribute\Route;

/**
 * Static-vs-dynamic overlap. `/users/me` matches `{name:any}` and
 * `{name:slug}` and `{name:alpha}` (the literal "me" matches all
 * three constraint regexes), so these collide.
 *
 * `/users/me` does NOT match `{id:int}` (digits-only) — that pair
 * lives in `CleanController`.
 */
final class StaticVsDynamicController
{
    #[Route('GET', '/users/me')]
    public function showSelf(): array { return []; }

    #[Route('GET', '/users/{name:any}')]
    public function showByName(): array { return []; }
}
