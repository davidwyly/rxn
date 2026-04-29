<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Pagination;

/**
 * Resolved pagination parameters for the current request.
 *
 * Constructed by the Pagination middleware from the query string
 * and exposed as a typed value object — controllers receive
 * already-validated `limit` / `offset` instead of raw `$_GET`
 * strings.
 *
 * Two query shapes are supported:
 *
 *   ?limit=20&offset=40         (offset-based, the default)
 *   ?page=3&per_page=20         (page-based, friendlier for clients)
 *
 * Both produce the same `limit` + `offset` properties downstream.
 * The class is read-only — apps that need richer state (cursors,
 * sort) compose it.
 */
final class Pagination
{
    private static ?self $current = null;

    public function __construct(
        public readonly int $limit,
        public readonly int $offset,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    /**
     * The Pagination resolved for the current request, or null when
     * no Pagination middleware is on the pipeline / before it has
     * run / after it has finished. Cleared in the middleware's
     * `finally` so a long-lived worker doesn't leak the previous
     * request's state into the next.
     *
     * Mirrors the `RequestId::current()` pattern used by the other
     * shipped middlewares.
     */
    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * @internal Used by the Pagination middleware to set / clear
     *           the per-request value. Not part of the public API.
     */
    public static function setCurrent(?self $value): void
    {
        self::$current = $value;
    }

    /**
     * Page number this Pagination represents (1-indexed). Useful
     * when you parsed `?limit=&offset=` but want to emit a
     * `Link: rel=next` for `?page=` clients.
     */
    public function pageFor(int $offset, int $perPage): int
    {
        return $perPage > 0 ? intdiv($offset, $perPage) + 1 : 1;
    }

    /**
     * Total number of pages given `$total` matching rows. Returns
     * 0 when there are no rows so empty-result pagination renders
     * cleanly.
     */
    public function totalPages(int $total): int
    {
        if ($total <= 0 || $this->perPage <= 0) {
            return 0;
        }
        return (int) ceil($total / $this->perPage);
    }
}
