<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Pagination\Pagination as PaginationParams;

/**
 * Parse-and-emit pagination middleware. Two responsibilities:
 *
 *  1. **Parse** — read `?limit=&offset=` (or `?page=&per_page=`)
 *     from the query string, clamp to the configured bounds,
 *     expose via `Pagination::current()`.
 *  2. **Emit** — after the downstream handler runs, inspect the
 *     response body for `meta.total` (set by controllers that
 *     know the total row count). When present, emit
 *     `X-Total-Count` and `Link: rel=first|prev|next|last`
 *     headers per RFC 8288.
 *
 * Controllers don't need to do pagination math:
 *
 *   $page  = Pagination::current();    // limit/offset/page/perPage
 *   $rows  = $repo->fetch(limit: $page->limit, offset: $page->offset);
 *   $total = $repo->count();
 *   return ['data' => $rows, 'meta' => ['total' => $total]];
 *   //                                  ^^^^^^^^^^^^^^^^^
 *   // The middleware reads this and emits headers automatically.
 *
 * Defaults: limit=25, max=100, offset=0. Configurable per-instance.
 */
final class Pagination implements MiddlewareInterface
{
    public function __construct(
        private readonly int $defaultLimit = 25,
        private readonly int $maxLimit     = 100,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $parsed = $this->parse($request->getQueryParams());
        PaginationParams::setCurrent($parsed);
        try {
            $response = $handler->handle($request);
            return $this->withLinkHeaders($response, $parsed, $request->getUri()->getPath());
        } finally {
            PaginationParams::setCurrent(null);
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function parse(array $query): PaginationParams
    {
        // Page-based shape wins when explicit. Otherwise default to
        // offset-based — clients passing neither still get a sane
        // (limit, offset) = (default, 0).
        if (isset($query['page']) || isset($query['per_page'])) {
            $perPage = $this->clamp((int)($query['per_page'] ?? $this->defaultLimit));
            $page    = max(1, (int)($query['page'] ?? 1));
            return new PaginationParams(
                limit:   $perPage,
                offset:  ($page - 1) * $perPage,
                page:    $page,
                perPage: $perPage,
            );
        }
        $limit  = $this->clamp((int)($query['limit'] ?? $this->defaultLimit));
        $offset = max(0, (int)($query['offset'] ?? 0));
        return new PaginationParams(
            limit:   $limit,
            offset:  $offset,
            page:    $limit > 0 ? intdiv($offset, $limit) + 1 : 1,
            perPage: $limit,
        );
    }

    private function clamp(int $n): int
    {
        if ($n < 1) {
            return 1;
        }
        return min($n, $this->maxLimit);
    }

    private function withLinkHeaders(ResponseInterface $response, PaginationParams $page, string $basePath): ResponseInterface
    {
        $total = $this->extractTotal($response);
        if ($total === null) {
            return $response;
        }
        $response = $response->withHeader('X-Total-Count', (string)$total);

        $links = $this->buildLinks($page, $total, $basePath);
        if ($links !== '') {
            $response = $response->withHeader('Link', $links);
        }
        return $response;
    }

    /**
     * Read `meta.total` out of a JSON response envelope. Returns
     * null when the body isn't JSON, isn't shaped `{meta: {total}}`,
     * or `total` isn't an integer — pagination headers are
     * advisory, never required, so a missing total is silent.
     */
    private function extractTotal(ResponseInterface $response): ?int
    {
        $body = (string)$response->getBody();
        // Reset stream position so downstream emit can re-read.
        if ($response->getBody()->isSeekable()) {
            $response->getBody()->rewind();
        }
        if ($body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['meta']['total'])) {
            return null;
        }
        $total = $decoded['meta']['total'];
        return is_int($total) || (is_string($total) && ctype_digit($total)) ? (int)$total : null;
    }

    private function buildLinks(PaginationParams $page, int $total, string $basePath): string
    {
        $totalPages = $page->totalPages($total);
        $links      = [];

        $build = function (int $p, string $rel) use ($basePath, $page): string {
            $query = http_build_query([
                'page'     => $p,
                'per_page' => $page->perPage,
            ]);
            return sprintf('<%s?%s>; rel="%s"', $basePath, $query, $rel);
        };

        if ($page->page > 1) {
            $links[] = $build(1, 'first');
            $links[] = $build($page->page - 1, 'prev');
        }
        if ($page->page < $totalPages) {
            $links[] = $build($page->page + 1, 'next');
            $links[] = $build($totalPages, 'last');
        }
        return implode(', ', $links);
    }
}
