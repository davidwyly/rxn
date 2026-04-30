<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Health;

use Rxn\Framework\Http\Response;
use Rxn\Framework\Http\Router;

/**
 * Readiness / liveness endpoint helper.
 *
 *   HealthCheck::register($router, '/health', [
 *       'database' => fn () => $db->getConnection() !== null,
 *       'cache'    => fn () => $cache->ping(),
 *       'queue'    => fn () => ['ok' => true, 'depth' => $q->depth()],
 *   ]);
 *
 * The registered route returns:
 *
 *   200 OK            — every check passed
 *   503 Service Unavailable — at least one check failed
 *
 * Body:
 *
 *   {
 *     "status": "ok" | "fail",
 *     "checks": {
 *       "database": { "status": "ok" },
 *       "cache":    { "status": "ok" },
 *       "queue":    { "status": "ok", "depth": 17 }
 *     }
 *   }
 *
 * Each check callable can return:
 *
 *   - `bool`   — true = ok, false = fail
 *   - `array`  — passes through as the check's body; if it has a
 *                `status` key, that determines pass/fail. Otherwise
 *                presence of the array means the check ran.
 *   - thrown Throwable — captured as `{ status: fail, error: <msg> }`
 *
 * Zero deps on the rest of the framework — registers a closure
 * directly with the Router. Apps that need authentication on the
 * health endpoint stack a middleware via `->middleware(...)` on
 * the returned Route.
 */
final class HealthCheck
{
    /**
     * @param array<string, callable(): bool|array<string, mixed>> $checks
     */
    public static function register(
        Router $router,
        string $path = '/health',
        array  $checks = [],
    ): \Rxn\Framework\Http\Route {
        return $router->get($path, fn () => self::run($checks));
    }

    /**
     * Run the configured checks and return a closure that the
     * router dispatcher invokes. Exposed as a static so apps that
     * want to embed health output in a richer endpoint (e.g.
     * `/admin/status`) can call it directly.
     *
     * @param array<string, callable(): bool|array<string, mixed>> $checks
     * @return array<string, mixed>
     */
    public static function run(array $checks): array
    {
        $results = [];
        $allOk   = true;
        foreach ($checks as $name => $check) {
            try {
                $raw = $check();
            } catch (\Throwable $e) {
                $results[$name] = ['status' => 'fail', 'error' => $e->getMessage()];
                $allOk = false;
                continue;
            }
            $entry = self::normalise($raw);
            $results[$name] = $entry;
            if (($entry['status'] ?? 'fail') !== 'ok') {
                $allOk = false;
            }
        }
        $status = $allOk ? 'ok' : 'fail';
        // Returned in Rxn's standard `{data, meta}` envelope shape so
        // the dispatcher renders it correctly. The HTTP status lives
        // in `meta.status` (200 / 503) — renderers that want strict
        // status mapping read it from there.
        return [
            'data' => [
                'status' => $status,
                'checks' => $results,
            ],
            'meta' => ['status' => $allOk ? 200 : 503],
        ];
    }

    /**
     * @param bool|array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function normalise(mixed $raw): array
    {
        if ($raw === true) {
            return ['status' => 'ok'];
        }
        if ($raw === false) {
            return ['status' => 'fail'];
        }
        if (is_array($raw)) {
            return array_merge(['status' => 'ok'], $raw);
        }
        return ['status' => 'fail', 'error' => 'check returned a non-boolean / non-array value'];
    }
}
