<?php declare(strict_types=1);

namespace Rxn\Bench\Compare;

/**
 * Pure-PHP HTTP load generator. Drives `concurrency` parallel
 * curl handles via curl_multi for `duration` seconds, records the
 * latency of every completed request, and reports throughput +
 * p50 / p99.
 *
 * The choice of curl_multi is deliberate: it keeps the harness a
 * single PHP process (no `wrk` install, no second runtime), and
 * curl's HTTP/1.1 keep-alive support gives us realistic per-request
 * costs once the connection pool is warm.
 *
 * The numbers it reports are *useful*, not *publishable*: they
 * include the load generator's own per-request overhead (curl
 * setup, callback dispatch). They're directly comparable across
 * apps run on the same harness, which is what we care about for
 * "does Rxn cost more than Slim on the same hardware?".
 */
final class Load
{
    /**
     * @param array{
     *   url:     string,
     *   method:  string,
     *   body:    ?string,
     *   headers: array<int, string>
     * } $request
     * @return array{
     *   count:    int,
     *   errors:   int,
     *   non_2xx:  int,
     *   elapsed:  float,
     *   rps:      float,
     *   p50_ms:   float,
     *   p99_ms:   float,
     *   max_ms:   float,
     *   status_breakdown: array<int, int>
     * }
     */
    public static function run(array $request, int $concurrency, float $duration): array
    {
        $multi = curl_multi_init();

        // Slot table: [handle => start_time] for the in-flight handles.
        $slots = [];
        for ($i = 0; $i < $concurrency; $i++) {
            $h = self::makeHandle($request);
            curl_multi_add_handle($multi, $h);
            $slots[(int) $h] = microtime(true);
        }

        $latencies      = [];
        $errors         = 0;
        $non2xx         = 0;
        $statusCounts   = [];
        $deadline       = microtime(true) + $duration;
        $running        = $concurrency;

        while (true) {
            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            // Drain everything that finished this tick.
            while ($info = curl_multi_info_read($multi)) {
                $h    = $info['handle'];
                $hid  = (int) $h;
                $end  = microtime(true);
                $start = $slots[$hid] ?? $end;
                $latencies[] = ($end - $start) * 1000.0; // ms

                if ($info['result'] !== CURLE_OK) {
                    $errors++;
                } else {
                    $code = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
                    $statusCounts[$code] = ($statusCounts[$code] ?? 0) + 1;
                    if ($code < 200 || $code >= 300) {
                        // 422 is a planned response shape, not a fault — but it
                        // *is* still non-2xx. We track it separately so the
                        // report can flag accidental 5xx's, while letting
                        // the user see the 422 count in status_breakdown.
                        $non2xx++;
                    }
                }

                curl_multi_remove_handle($multi, $h);
                curl_close($h);
                unset($slots[$hid]);

                // Refill the slot if we still have time on the clock.
                if (microtime(true) < $deadline) {
                    $newH = self::makeHandle($request);
                    curl_multi_add_handle($multi, $newH);
                    $slots[(int) $newH] = microtime(true);
                    $running++;
                }
            }

            if ($running <= 0) {
                break;
            }

            // Block for up to 50ms waiting for activity. Avoids
            // burning CPU on tight curl_multi_exec spins.
            curl_multi_select($multi, 0.05);
        }

        curl_multi_close($multi);

        $count   = count($latencies);
        $elapsed = $duration; // Time-bounded, not iteration-bounded.

        sort($latencies);
        $p50 = self::percentile($latencies, 0.50);
        $p99 = self::percentile($latencies, 0.99);
        $max = $latencies !== [] ? end($latencies) : 0.0;

        ksort($statusCounts);

        return [
            'count'            => $count,
            'errors'           => $errors,
            'non_2xx'          => $non2xx,
            'elapsed'          => $elapsed,
            'rps'              => $elapsed > 0 ? $count / $elapsed : 0.0,
            'p50_ms'           => $p50,
            'p99_ms'           => $p99,
            'max_ms'           => $max,
            'status_breakdown' => $statusCounts,
        ];
    }

    /**
     * Quick burst against the target so first-hit costs (autoload,
     * opcode cache priming, JIT warmup, framework lazy bootstraps)
     * don't get billed to the timed run.
     *
     * @param array{url:string, method:string, body:?string, headers:array<int,string>} $request
     */
    public static function warmup(array $request, int $hits = 50): void
    {
        for ($i = 0; $i < $hits; $i++) {
            $h = self::makeHandle($request);
            curl_exec($h);
            curl_close($h);
        }
    }

    /**
     * Single-shot, returns [status, body]. Used by the driver to
     * sanity-check that each app actually answers the route shape
     * we claim before we pile load on it.
     *
     * @param array{url:string, method:string, body:?string, headers:array<int,string>} $request
     * @return array{0: int, 1: string}
     */
    public static function once(array $request): array
    {
        $h = self::makeHandle($request);
        $body = (string) curl_exec($h);
        $code = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_close($h);
        return [$code, $body];
    }

    /**
     * @param array{url:string, method:string, body:?string, headers:array<int,string>} $request
     * @return \CurlHandle
     */
    private static function makeHandle(array $request): \CurlHandle
    {
        $h = curl_init();
        curl_setopt_array($h, [
            CURLOPT_URL            => $request['url'],
            CURLOPT_CUSTOMREQUEST  => $request['method'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => 10_000,
            CURLOPT_CONNECTTIMEOUT_MS => 1_000,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => $request['headers'],
            CURLOPT_FORBID_REUSE   => false,
            CURLOPT_FRESH_CONNECT  => false,
        ]);

        if ($request['body'] !== null && $request['body'] !== '') {
            curl_setopt($h, CURLOPT_POSTFIELDS, $request['body']);
        }

        return $h;
    }

    /**
     * @param list<float> $sorted ascending-sorted latencies in ms
     */
    private static function percentile(array $sorted, float $q): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        // Nearest-rank percentile. Cheap, stable, no interpolation
        // surprises for small samples.
        $idx = (int) ceil($q * $n) - 1;
        if ($idx < 0) {
            $idx = 0;
        }
        if ($idx >= $n) {
            $idx = $n - 1;
        }
        return $sorted[$idx];
    }
}
