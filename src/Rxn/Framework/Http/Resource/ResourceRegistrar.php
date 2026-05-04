<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Resource;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\RequestDto;
use Rxn\Framework\Http\Binding\ValidationException;
use Rxn\Framework\Http\Router;

/**
 * Wire a `CrudHandler` to a five-route URL family in one call.
 *
 *   ResourceRegistrar::register(
 *       $router,
 *       '/products',
 *       new ProductsCrud($repo),
 *       create: CreateProduct::class,
 *       update: UpdateProduct::class,
 *       search: SearchProducts::class,
 *   );
 *
 * Registers:
 *
 *   POST   /products                       → create
 *   GET    /products                       → search
 *   GET    /products/{id:int}              → read
 *   PATCH  /products/{id:int}              → update
 *   DELETE /products/{id:int}              → delete
 *
 * Each closure handles the wrapping the framework expects:
 *
 *   - `create` binds the create DTO from the request, calls the
 *     handler, returns `{data, meta: {status: 201}}` on success
 *     or `{meta: {status: 422, errors: [...]}}` on validation
 *     failure (RFC 7807 Problem Details via `App::serve`'s
 *     envelope mapper).
 *   - `search` optionally binds a filter DTO from the query
 *     string — registrations without a `search` DTO call the
 *     handler with `null`. Result list is wrapped as
 *     `{data: [...]}`.
 *   - `read` calls the handler; `null` → 404 Problem Details.
 *   - `update` binds + validates the DTO, then `null` from the
 *     handler → 404, 422 on validation failure.
 *   - `delete` returns a true PSR-7 204 (empty body) on success;
 *     `false` from the handler → 404.
 *
 * Apps can stack route-level middleware on the resource as a
 * group via `Router::group()` before calling `register()`, or
 * post-hoc by mutating each registered Route through the Router
 * (the registrar doesn't return Route handles — middleware
 * composition happens at the group level).
 *
 * Storage-agnostic: the registrar only knows the `CrudHandler`
 * interface. `davidwyly/rxn-orm`'s `RxnOrmCrudHandler` base
 * class reduces the implementation boilerplate for the common
 * relational case; apps with bespoke storage write their own
 * 5-method class.
 */
final class ResourceRegistrar
{
    /**
     * @param class-string<RequestDto>  $create  request body shape for POST
     * @param class-string<RequestDto>  $update  request body shape for PATCH
     * @param class-string<RequestDto>|null $search query shape for GET (no body) — null
     *                                              means "no filter, hand the handler null"
     * @param string $idType Router placeholder type — `'int'` (default), `'uuid'`,
     *                       `'slug'`, `'any'`, or any custom constraint that
     *                       `Router::compile` knows about. Determines the URL
     *                       shape of the per-resource routes (`/{id:int}` etc.)
     *                       AND how the placeholder is coerced before the
     *                       handler sees it (int vs. string).
     */
    public static function register(
        Router $router,
        string $path,
        CrudHandler $handler,
        string $create,
        string $update,
        ?string $search = null,
        string $idType = 'int',
    ): void {
        $itemPath = rtrim($path, '/') . '/{id:' . $idType . '}';

        // POST /path — create
        $router->post(
            $path,
            static function (array $params, ServerRequestInterface $request) use ($handler, $create): array|ResponseInterface {
                try {
                    /** @var RequestDto $dto */
                    $dto = Binder::bindRequest($create, $request);
                } catch (ValidationException $e) {
                    return self::problem(422, 'Unprocessable Entity', $e->errors());
                }
                return ['data' => $handler->create($dto), 'meta' => ['status' => 201]];
            },
        );

        // GET /path — search (optionally filtered)
        $router->get(
            $path,
            static function (array $params, ServerRequestInterface $request) use ($handler, $search): array|ResponseInterface {
                $filter = null;
                if ($search !== null) {
                    try {
                        /** @var RequestDto $filter */
                        $filter = Binder::bindRequest($search, $request);
                    } catch (ValidationException $e) {
                        return self::problem(422, 'Unprocessable Entity', $e->errors());
                    }
                }
                return ['data' => $handler->search($filter)];
            },
        );

        // GET /path/{id:type} — read
        $router->get(
            $itemPath,
            static function (array $params) use ($handler, $idType): array {
                $row = $handler->read(self::coerceId($params['id'], $idType));
                if ($row === null) {
                    return ['meta' => ['status' => 404, 'title' => 'Not Found']];
                }
                return ['data' => $row];
            },
        );

        // PATCH /path/{id:type} — update
        $router->patch(
            $itemPath,
            static function (array $params, ServerRequestInterface $request) use ($handler, $update, $idType): array|ResponseInterface {
                try {
                    /** @var RequestDto $dto */
                    $dto = Binder::bindRequest($update, $request);
                } catch (ValidationException $e) {
                    return self::problem(422, 'Unprocessable Entity', $e->errors());
                }
                $row = $handler->update(self::coerceId($params['id'], $idType), $dto);
                if ($row === null) {
                    return ['meta' => ['status' => 404, 'title' => 'Not Found']];
                }
                return ['data' => $row];
            },
        );

        // DELETE /path/{id:type} — delete
        $router->delete(
            $itemPath,
            static function (array $params) use ($handler, $idType): array|ResponseInterface {
                if ($handler->delete(self::coerceId($params['id'], $idType))) {
                    // 204 No Content — empty body, per HTTP spec.
                    // Return a true PSR-7 response so the array
                    // envelope mapper doesn't synthesise a body.
                    return new Response(204);
                }
                return ['meta' => ['status' => 404, 'title' => 'Not Found']];
            },
        );
    }

    /**
     * Coerce a route placeholder match into the type the
     * `CrudHandler` expects. `'int'` casts; every other type
     * (`'uuid'`, `'slug'`, `'any'`, custom) stays as the raw
     * captured string.
     */
    private static function coerceId(string $raw, string $idType): int|string
    {
        return $idType === 'int' ? (int) $raw : $raw;
    }

    /**
     * Build a Problem Details PSR-7 response with optional
     * `errors[]` extension. Used by the create / update / search
     * paths to surface validation failures uniformly.
     *
     * @param list<array{field: string, message: string}> $errors
     */
    private static function problem(int $status, string $title, array $errors = []): ResponseInterface
    {
        $payload = ['type' => 'about:blank', 'title' => $title, 'status' => $status];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }
        return new Response(
            $status,
            ['Content-Type' => 'application/problem+json'],
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
