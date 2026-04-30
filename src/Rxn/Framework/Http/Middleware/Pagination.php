<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Rxn\Framework\Http\Middleware;
use Rxn\Framework\Http\Pagination\Pagination as PaginationParams;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

/**
 * Parse-and-emit pagination middleware. Two responsibilities:
 *
 *  1. **Parse** — read `?limit=&offset=` (or `?page=&per_page=`)
 *     from the query string, clamp to the configured bounds,
 *     expose via `Pagination::current()`.
 *  2. **Emit** — after the controller runs, inspect the response
 *     for `meta.total` (set by controllers that know the total
 *     row count). When present, emit `X-Total-Count` and
 *     `Link: rel=first|prev|next|last` headers per RFC 8288.
 *
 * Controllers don't need to do pagination math:
 *
 *   $page = Pagination::current();        // limit/offset/page/perPage
 *   $rows = $repo->fetch(limit: $page->limit, offset: $page->offset);
 *   $total = $repo->count();
 *   return ['data' => $rows, 'meta' => ['total' => $total]];
 *   //                                  ^^^^^^^^^^^^^^^^^
 *   // The middleware reads this and emits headers automatically.
 *
 * Defaults: limit=25, max=100, offset=0. Configurable per-instance.
 */
final class Pagination implements Middleware
{
    /** @var callable(string): void */
    private $emitHeader;

    public function __construct(
        private readonly int $defaultLimit = 25,
        private readonly int $maxLimit     = 100,
        ?callable $emitHeader = null,
    ) {
        $this->emitHeader = $emitHeader ?? static fn (string $h) => header($h);
    }

    public function handle(Request $request, callable $next): Response
    {
        $parsed = $this->parse($_GET ?? []);
        PaginationParams::setCurrent($parsed);
        try {
            $response = $next($request);
            $this->emitLinkHeaders($response, $parsed);
            return $response;
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

    private function emitLinkHeaders(Response $response, PaginationParams $page): void
    {
        $total = is_array($response->meta) && isset($response->meta['total'])
            ? (int) $response->meta['total']
            : null;
        if ($total === null) {
            return;
        }
        ($this->emitHeader)("X-Total-Count: $total");

        $links = $this->buildLinks($page, $total);
        if ($links !== '') {
            ($this->emitHeader)("Link: $links");
        }
    }

    private function buildLinks(PaginationParams $page, int $total): string
    {
        $base       = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
        $totalPages = $page->totalPages($total);
        $links      = [];

        $build = function (int $p, string $rel) use ($base, $page): string {
            $query = http_build_query([
                'page'     => $p,
                'per_page' => $page->perPage,
            ]);
            return sprintf('<%s?%s>; rel="%s"', $base, $query, $rel);
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
