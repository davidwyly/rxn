<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Resource;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\RequestDto;
use Rxn\Framework\Http\Binding\ValidationException;
use Rxn\Framework\Http\RouteGroup;
use Rxn\Framework\Http\Router;

/**
 * Wire a `CrudHandler` to a five-route URL family in one call.
 *
 *   $routes = ResourceRegistrar::register(
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
 * The returned `ResourceRoutes` carries the five `Route` handles
 * so callers can wire middleware after registration:
 *
 *   $routes->middleware($bearerAuth);             // all five
 *   $routes->update->middleware($adminOnly);      // PATCH only
 *
 * Both `Router` and `RouteGroup` are accepted as the first arg —
 * registering through a group inherits the group's prefix and
 * middleware stack, so the natural shape for protected APIs is:
 *
 *   $router->group('/v1', function (RouteGroup $g) use ($auth) {
 *       $g->middleware($auth);
 *       ResourceRegistrar::register($g, '/products', $crud, ...);
 *   });
 *
 * Each closure handles the wrapping the framework expects:
 *
 *   - `create` binds the create DTO via Binder (query + body,
 *     body wins) from the request, calls the
 *     handler, returns `{data, meta: {status: 201}}` on success.
 *     Validation failure returns a PSR-7 Problem Details
 *     response (422 + `application/problem+json` with
 *     `errors[]`); the array envelope mapper isn't involved
 *     because the response is built directly.
 *   - `search` optionally binds a filter DTO from the query
 *     string — registrations without a `search` DTO call the
 *     handler with `null`. Result list is wrapped as
 *     `{data: [...]}`. Validation failure → PSR-7 Problem
 *     Details (422) directly, same as create.
 *   - `read` calls the handler; `null` from the handler →
 *     `{meta: {status: 404, title: 'Not Found'}}` envelope
 *     (mapped to 404 application/problem+json by `App::serve`).
 *   - `update` binds + validates the DTO via Binder (query +
 *     body, body wins); `null` from the
 *     handler → 404 envelope. Validation failure → 422 PSR-7
 *     Problem Details.
 *   - `delete` returns a true PSR-7 204 response (empty body)
 *     on success — bypassing the array envelope mapper because
 *     HTTP requires 204 to have no content. False from the
 *     handler → 404 envelope.
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
     * @param Router|RouteGroup        $on      Where to register. RouteGroup
     *                                          inherits its parent's prefix +
     *                                          middleware stack on every route
     *                                          this registrar adds.
     * @param class-string<RequestDto> $create  request body shape for POST
     * @param class-string<RequestDto> $update  request body shape for PATCH
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
        Router|RouteGroup $on,
        string $path,
        CrudHandler $handler,
        string $create,
        string $update,
        ?string $search = null,
        string $idType = 'int',
    ): ResourceRoutes {
        // Normalise: single leading slash, no trailing slash, so that
        // Router and RouteGroup (which prepends a prefix) behave identically
        // regardless of whether the caller includes the leading slash.
        $path = '/' . ltrim(rtrim($path, '/'), '/');

        // Validate idType against Router::compile()'s placeholder-type
        // grammar before interpolation. Without this guard, a label
        // containing '-' or anything outside the grammar surfaces as
        // a generic "Malformed route placeholder" from Router::compile,
        // pointing at the wrong layer. Identifier-shape regex matches
        // PHP's own valid-identifier check.
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $idType)) {
            throw new \InvalidArgumentException(
                "ResourceRegistrar: idType must match [a-zA-Z_][a-zA-Z0-9_]* (got '{$idType}')",
            );
        }
        $itemPath = $path . '/{id:' . $idType . '}';

        // Validate DTO class-strings at registration time so mis-wired
        // calls fail immediately rather than on first request with a
        // misleading 500. Three distinct failure modes, three distinct
        // error messages — generic "must implement RequestDto" hides
        // typos and empty inputs behind a check that wasn't even
        // reached for those cases. $create and $update are checked
        // unconditionally; $search only when non-null.
        $dtoClasses = ['create' => $create, 'update' => $update];
        if ($search !== null) {
            $dtoClasses['search'] = $search;
        }
        foreach ($dtoClasses as $arg => $dtoClass) {
            if ($dtoClass === '') {
                throw new \InvalidArgumentException(
                    "ResourceRegistrar: \${$arg} DTO class name cannot be empty",
                );
            }
            if (!class_exists($dtoClass)) {
                throw new \InvalidArgumentException(
                    "ResourceRegistrar: \${$arg} DTO class '{$dtoClass}' does not exist",
                );
            }
            if (!is_subclass_of($dtoClass, RequestDto::class)) {
                throw new \InvalidArgumentException(
                    "ResourceRegistrar: \${$arg} DTO '{$dtoClass}' must implement " . RequestDto::class,
                );
            }
        }

        // POST /path — create
        $createRoute = $on->post(
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
        $searchRoute = $on->get(
            $path,
            static function (array $params, ServerRequestInterface $request) use ($handler, $search): array|ResponseInterface {
                $filter = null;
                if ($search !== null) {
                    try {
                        /** @var RequestDto $filter */
                        $filter = Binder::bind($search, $request->getQueryParams());
                    } catch (ValidationException $e) {
                        return self::problem(422, 'Unprocessable Entity', $e->errors());
                    }
                }
                return ['data' => $handler->search($filter)];
            },
        );

        // GET /path/{id:type} — read
        $readRoute = $on->get(
            $itemPath,
            static function (array $params, ServerRequestInterface $request) use ($handler, $idType): array {
                $row = $handler->read(self::coerceId($params['id'], $idType));
                if ($row === null) {
                    return ['meta' => ['status' => 404, 'title' => 'Not Found']];
                }
                return ['data' => $row];
            },
        );

        // PATCH /path/{id:type} — update
        $updateRoute = $on->patch(
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
        $deleteRoute = $on->delete(
            $itemPath,
            static function (array $params, ServerRequestInterface $request) use ($handler, $idType): array|ResponseInterface {
                if ($handler->delete(self::coerceId($params['id'], $idType))) {
                    // 204 No Content — empty body, per HTTP spec.
                    // Return a true PSR-7 response so the array
                    // envelope mapper doesn't synthesise a body.
                    return new Response(204);
                }
                return ['meta' => ['status' => 404, 'title' => 'Not Found']];
            },
        );

        return new ResourceRoutes(
            create: $createRoute,
            search: $searchRoute,
            read:   $readRoute,
            update: $updateRoute,
            delete: $deleteRoute,
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
