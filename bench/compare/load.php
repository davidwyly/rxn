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
     *   count:               int,
     *   errors:              int,
     *   non_2xx:             int,
     *   elapsed:             float,
     *   rps:                 float,
     *   rps_median_window:   float,
     *   p50_ms:              float,
     *   p99_ms:              float,
     *   max_ms:              float,
     *   status_breakdown:    array<int, int>
     * }
     */
    public static function run(array $request, int $concurrency, float $duration): array
    {
        $multi = curl_multi_init();

        // Pre-allocate one handle per slot and reuse it for the whole
        // run. The earlier shape closed-and-recreated a handle per
        // request, which destroyed curl's connection cache and forced
        // a fresh TCP socket per request — at 17k rps × concurrency
        // that depletes the ephemeral port pool (~28k ports, TIME_WAIT
        // holds them) within seconds and the bench windows hit
        // periodic port-pressure stalls.
        //
        // **Caveat for `php -S`**: the built-in CLI server always
        // emits `Connection: close`, so even with handle reuse the
        // server tears down the TCP socket after every response.
        // Real connection reuse only happens for the small fraction
        // of requests where curl re-arms before the kernel has
        // observed FIN. Under `php -S` this fix mitigates but does
        // not eliminate the port-pressure stall pattern. Under any
        // server with HTTP/1.1 keep-alive (FrankenPHP, RoadRunner,
        // PHP-FPM behind nginx) the fix is fully effective and pins
        // open-socket count at ~$concurrency for the whole run.
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
        $runStart       = microtime(true);
        $deadline       = $runStart + $duration;
        $running        = $concurrency;
        // Bin completions into 100ms windows so we can report the
        // median windowed rps in addition to the simple count/duration
        // value. The simple rps is sensitive to brief stalls (a 1s
        // stall in a 7s run costs 14% rps); the median windowed rps
        // is robust to short bursts and tells you "what was the rate
        // a typical 100ms slice of this run saw" — closer to what
        // you'd see at steady state on a clean rig.
        $windowMs       = 100;
        $windowCounts   = [];

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

                $bin = (int) (($end - $runStart) * 1000 / $windowMs);
                $windowCounts[$bin] = ($windowCounts[$bin] ?? 0) + 1;

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

                // Remove the handle from the multi, but don't close
                // it — re-arm and re-add so the next request reuses
                // the underlying TCP connection. The handle keeps
                // its options (URL, method, body, headers) across
                // remove/add cycles.
                curl_multi_remove_handle($multi, $h);
                if (microtime(true) < $deadline) {
                    $slots[$hid] = microtime(true);
                    curl_multi_add_handle($multi, $h);
                    $running++;
                } else {
                    curl_close($h);
                    unset($slots[$hid]);
                }
            }

            if ($running <= 0) {
                break;
            }

            // Block for up to 50ms waiting for activity. Avoids
            // burning CPU on tight curl_multi_exec spins.
            curl_multi_select($multi, 0.05);
        }

        // All handles get closed in the else-branch above as they
        // complete past the deadline; $running can't reach 0 until
        // every active handle has been removed and closed.
        curl_multi_close($multi);

        $count   = count($latencies);
        $elapsed = $duration; // Time-bounded, not iteration-bounded.

        sort($latencies);
        $p50 = self::percentile($latencies, 0.50);
        $p99 = self::percentile($latencies, 0.99);
        $max = $latencies !== [] ? end($latencies) : 0.0;

        // Median windowed rps: drop the first and last bins (partially
        // populated at run boundaries — first bin starts mid-warmup,
        // last bin ends mid-tick) and take the median of the rest.
        // Multiplied to req/sec from req/100ms.
        $rpsMedianWindow = 0.0;
        if (count($windowCounts) > 0) {
            ksort($windowCounts);
            $firstBin = (int) array_key_first($windowCounts);
            // Clamp to the last bin that falls within the intended timed run.
            // Without this, a single slow request completing well after the
            // deadline (up to CURLOPT_TIMEOUT_MS later) would cause the range
            // loop to synthesise a large block of zero-count bins that aren't
            // part of the run, dragging the median down toward 0.
            $maxBin  = (int) ceil($duration * 1000.0 / $windowMs) - 1;
            $lastBin = min((int) array_key_last($windowCounts), $maxBin);
            $bins = [];
            for ($b = $firstBin; $b <= $lastBin; $b++) {
                $bins[] = $windowCounts[$b] ?? 0;
            }
            if (count($bins) > 2) {
                $bins = array_slice($bins, 1, -1);
                sort($bins);
                $mid = $bins[(int) (count($bins) / 2)];
                $rpsMedianWindow = $mid * (1000.0 / $windowMs);
            }
        }

        ksort($statusCounts);

        return [
            'count'             => $count,
            'errors'            => $errors,
            'non_2xx'           => $non2xx,
            'elapsed'           => $elapsed,
            'rps'               => $elapsed > 0 ? $count / $elapsed : 0.0,
            'rps_median_window' => $rpsMedianWindow,
            'p50_ms'            => $p50,
            'p99_ms'            => $p99,
            'max_ms'            => $max,
            'status_breakdown'  => $statusCounts,
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
