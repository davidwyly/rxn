<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Resource;

use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Handler shape that `ResourceRegistrar::register()` wires to a
 * five-route URL family. Implement once; get
 *
 *   POST   /path            → create($dto)
 *   GET    /path            → search($filter)
 *   GET    /path/{id:type}  → read($id)
 *   PATCH  /path/{id:type}  → update($id, $dto)
 *   DELETE /path/{id:type}  → delete($id)
 *
 * for free, with DTO binding + validation + RFC 7807 failure
 * shapes already wired by the registrar.
 *
 * The interface is storage-agnostic. Implementations can use
 * `davidwyly/rxn-orm` (the natural fit — `RxnOrmCrudHandler`
 * abstract class lives there and reduces boilerplate to the
 * point of "extend a class, set TABLE constant, done"), Doctrine,
 * raw PDO, in-memory arrays for tests, or even a remote API
 * gateway. The framework's job is the HTTP layer; storage is the
 * caller's choice.
 *
 * Return-shape contract (so the registrar's wrapping is uniform):
 *
 *   - `create()` always returns the created row's representation
 *     as an `array<string, mixed>`. The registrar wraps it as
 *     201 + `{data: ..., meta: {status: 201}}`.
 *   - `read()` / `update()` return the row's representation, or
 *     `null` when no row matches the id. Null becomes 404
 *     Problem Details.
 *   - `delete()` returns `true` (the row was deleted, 204 with
 *     empty body) or `false` (no such row, 404 Problem Details).
 *   - `search()` always returns a list (possibly empty). The
 *     registrar wraps it as 200 + `{data: [...]}` — there is no
 *     top-level `meta` slot. Pagination metadata (total count,
 *     next-page link) is the `Http\Middleware\Pagination`
 *     middleware's concern: stack it on the resource's GET
 *     route via `$routes->search->middleware(new Pagination(...))`
 *     and it adds `X-Total-Count` + RFC 8288 `Link` headers
 *     without changing the response body shape.
 *
 * IDs are typed `int|string` — the registrar coerces the URL
 * placeholder according to the `idType` argument it received
 * (`'int'` casts to int; everything else stays string for UUID
 * / slug / opaque-token shapes).
 */
interface CrudHandler
{
    /**
     * Create a new resource from a hydrated + validated DTO.
     *
     * @return array<string, mixed>
     */
    public function create(RequestDto $dto): array;

    /**
     * Read the resource by id, or `null` when no such resource
     * exists. The registrar turns null into a 404 Problem
     * Details response.
     *
     * @return array<string, mixed>|null
     */
    public function read(int|string $id): ?array;

    /**
     * Apply a partial update to the resource. Returns the
     * updated row, or `null` when no such id exists.
     *
     * @return array<string, mixed>|null
     */
    public function update(int|string $id, RequestDto $dto): ?array;

    /**
     * Delete the resource. `true` on success (registrar emits
     * 204), `false` when no such id exists (registrar emits
     * 404).
     */
    public function delete(int|string $id): bool;

    /**
     * List / filter / search resources. `$filter` is null when
     * no search DTO was registered for this resource — apps
     * that want unfiltered listing can ignore it; apps that
     * registered a filter receive a hydrated + validated DTO.
     *
     * @return list<array<string, mixed>>
     */
    public function search(?RequestDto $filter): array;
}
