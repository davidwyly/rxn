<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Data\Database;

/**
 * Wrap a request in a database transaction. Commits on a 2xx
 * response, rolls back on 4xx / 5xx or any thrown exception.
 *
 *   $pipeline->add(new Transaction($container->get(Database::class)));
 *
 * Middleware vs. per-handler transactions:
 *
 *  - **Middleware** (this class) — every mutating request gets a
 *    transaction by default. Controllers don't have to remember
 *    the boilerplate. Failures → automatic rollback.
 *  - **Per-handler** — controllers call `$db->transactionOpen()`
 *    themselves. Useful for reads that don't need a transaction
 *    (most don't), or for fine-grained control inside a long
 *    request.
 *
 * Both compose: the middleware nests cleanly with handler-level
 * `transactionOpen()` calls because Database supports nested
 * begins via `transaction_depth`.
 *
 * GET / HEAD / OPTIONS pass through untouched by default — no
 * transaction overhead on read-only requests. Apps that *want*
 * read-side snapshot isolation can override `$wrappedMethods`.
 *
 * Multi-database apps add multiple Transaction instances, one per
 * Database; the middlewares stack and run in pipeline order. The
 * commit/rollback decision is made independently per database
 * based on the same response code.
 */
final class Transaction implements MiddlewareInterface
{
    public function __construct(
        private readonly Database $database,
        /** @var list<string> */
        private readonly array $wrappedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'],
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $method = strtoupper($request->getMethod());
        if (!in_array($method, $this->wrappedMethods, true)) {
            return $handler->handle($request);
        }

        $this->database->transactionOpen();
        try {
            $response = $handler->handle($request);
        } catch (\Throwable $exception) {
            // Roll back, then re-raise — the exception handler /
            // problem-details renderer further up the stack still
            // sees the same Throwable.
            $this->safeRollback();
            throw $exception;
        }
        // 2xx → commit, anything else → rollback. Validation
        // failures (422) and client errors (4xx) shouldn't persist
        // partial writes; server errors (5xx) definitely shouldn't.
        $code = $response->getStatusCode();
        if ($code >= 200 && $code < 300) {
            $this->database->transactionClose();
        } else {
            $this->safeRollback();
        }
        return $response;
    }

    /**
     * Rollback that swallows its own DatabaseException. Called
     * from both the catch and the post-process branch — neither
     * should mask the original outcome (the thrown exception or
     * the 4xx/5xx response) with a "rollback failed" error.
     * The underlying connection is still propagated up via the
     * normal exception path on next query attempt.
     */
    private function safeRollback(): void
    {
        try {
            $this->database->transactionRollback();
        } catch (\Throwable) {
            // Intentionally swallowed — see method docblock.
        }
    }
}
