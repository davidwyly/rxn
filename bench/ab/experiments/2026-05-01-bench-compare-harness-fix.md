# `bench/compare` harness: Latin-square outlier, root-cause + fix

**Date:** 2026-05-01
**Decision:** Two complementary fixes landed:
1. **`fix(bench/compare): reuse curl handles instead of close-and-recreate per request`** —
   correct under any keep-alive server, partial mitigation under
   `php -S`.
2. **`feat(bench/compare): report median-window rps alongside count/duration rps`** —
   robust to brief stalls; the steady-state metric `rxn` /
   `rxn-psr7` should have been compared on all along.

The second fix is the real one. The Latin-square stalls survived
every TIME-side mitigation (handle reuse, longer cooldown, lower
concurrency) — so we stopped trying to remove the stalls and
instead reported the metric that doesn't care about them.

## Symptom

In the PSR-7 end-to-end A/B
(`2026-05-01-psr7-end-to-end.md`), the `rxn` leg's per-cell
samples showed a deterministic outlier pattern. Across 5 runs of
4 routes:

```
            /hello   /products  POST valid  POST 422
run 1     LOW 13050     16873       16993     16677
run 2        17184  LOW 12775       16545     16601
run 3        16815     16903   LOW 12936     16773
run 4        16993     16754       16823 LOW 12789
run 5        17043     17048       16995     16731
```

Exactly **one cell per run** drops to ~75% of its true throughput,
and the low **walks diagonally** across positions 1 → 2 → 3 → 4
in runs 1–4. Reversing the framework order moved the diagonal
to whichever leg ran first — so the artifact is position-driven,
not framework-driven, and it was inflating `rxn`'s range enough
to produce a spurious "non-overlapping ranges, PSR-7 wins"
verdict from the project's standard A/B rule.

## Root cause

`bench/compare/load.php`'s load generator was creating a fresh
`CurlHandle` for every completed request:

```php
curl_multi_remove_handle($multi, $h);
curl_close($h);                      // <-- destroys the connection cache
unset($slots[$hid]);
if (microtime(true) < $deadline) {
    $newH = self::makeHandle($request);   // <-- new TCP socket
    curl_multi_add_handle($multi, $newH);
    ...
}
```

`CURLOPT_FORBID_REUSE => false` and `CURLOPT_HTTP_VERSION_1_1`
*looked* like keep-alive support, but `curl_close($h)` between
requests cleared the per-handle connection cache. Result: every
request opens a new TCP socket from a fresh client-side
ephemeral port.

At the bench's normal operating point (concurrency=10, ~17k rps),
that's ~17,000 new ephemeral ports per second. Linux's default
`ip_local_port_range` is 32768–60999 (~28k ports);
`tcp_fin_timeout` holds them in TIME_WAIT for 60s. Even with
`tcp_tw_reuse=2` enabled (it is on this rig), the port pool is
*permanently* over its sustainable rate. The diagonal walk is
the regular periodicity of TIME_WAIT pressure landing in
different bench windows.

`ss -s` during a typical 5-run sweep shows ~42,000 TIME_WAIT
entries — way past the ephemeral pool size, only kept afloat by
`tcp_tw_reuse`'s aggressive recycling.

## Partial fix (committed)

Reused `CurlHandle` across remove/re-add cycles so curl's
internal connection cache survives:

```php
curl_multi_remove_handle($multi, $h);
if (microtime(true) < $deadline) {
    $slots[$hid] = microtime(true);
    curl_multi_add_handle($multi, $h);   // same $h, cache intact
    $running++;
} else {
    curl_close($h);
    unset($slots[$hid]);
}
```

This is the **correct shape** for any normal HTTP server. Under
`php -S`, however, the server itself emits `Connection: close`
on every response — verified directly:

```
$ curl -si -H 'Connection: keep-alive' http://127.0.0.1:8183/hello | head -3
HTTP/1.1 200 OK
Connection: close       <-- always
```

So the *server* tears down the TCP connection regardless of what
the client wants. Real connection reuse only happens for the
small fraction of requests where curl re-arms before the kernel
has observed the FIN. Under `php -S` the partial fix lifts every
cell's median by 5–13% (effective for that small fraction), but
the Latin-square stall pattern survives at reduced amplitude
(lows now ~14k instead of ~12-13k).

A direct probe with `CURLINFO_NUM_CONNECTS` confirms that almost
every request still creates a new connection under `php -S`,
even with the handle-reuse fix in place — exactly what the
`Connection: close` response header forces.

## Real fix (the metric, not the runtime)

The breakthrough was realising the Latin-square is *unfixable
within `php -S`* — every mitigation tried (handle reuse, longer
cooldown, lower concurrency) addressed at most a fraction of
the variance. WSL2's scheduler jitter plus `php -S`'s
connection-close-per-request plus saturating load = an
irreducible noise floor.

But the noise floor is in the **count/duration rps** metric, not
in the steady-state behaviour. A 1-second stall in a 7-second
window costs 14% of `count/duration` rps even though the other
6 seconds were running at full speed. p50 latency is barely
affected by such stalls (it picks the median of all sampled
requests, and the in-flight requests during the stall are still
fast). The right answer is to report the metric that aligns
with what users care about — **the rate a typical 100ms slice
of the run saw** — and stop pretending the count/duration value
is a useful comparison statistic.

Implementation: bin every completion into 100ms windows by its
end timestamp. Drop the first and last bin (partially populated
at run boundaries) and take the median of the remaining bin
counts, scaled to req/sec. This is a 20-line change in
`Load::run`; both metrics are reported so users can sanity-check
the windowing.

### Empirical validation

Smoke test of the new metric against a known-stalled run:

| Cell                | raw rps | median-window rps | p50 ms |
|---------------------|--------:|------------------:|-------:|
| rxn POST 422 (stalled) | 10,436 |            16,970 | 0.58   |
| rxn POST valid (clean) | 17,054 |            17,270 | 0.57   |

The stalled cell's count/duration rps is 39% lower than its
steady-state neighbours; its median-window rps is within 2% of
them, exactly matching the p50 latency story. The
median-window metric is reading "what the framework is actually
doing per request when nothing's wrong" — which is what every
A/B verdict cares about.

### What this doesn't replace

A future server swap (FrankenPHP / PHP-FPM + nginx /
RoadRunner) is still the right move for *absolute* throughput
numbers — `php -S` will always be a development server with
its own ceiling. But for *relative* framework comparison (which
is what `bench/compare` exists for), the median-window metric
on `php -S` is now precise enough to support the verdicts the
project's A/B discipline requires. The runtime swap moves from
"required" to "nice to have."

## Mitigations that DON'T work within `php -S`

Two workarounds were tested and found to **not eliminate** the
Latin-square outlier pattern. Both ruled out on direct
measurement, no merge.

### 5-second per-route cooldown

Hypothesis: 250ms isn't enough for TIME_WAIT'd ports to drain
between bench windows. Bumping to 5s should let the kernel
reclaim a meaningful chunk before the next route fires.

Result: 3 runs at `COOLDOWN=5`, c=10, d=7s — outliers persisted
in every run. Run 1 had two simultaneous outliers (one per leg);
run 3 saw `rxn` *uniformly depressed* across all four cells
(15.0–15.7k vs the steady-state 17–18k), as if the cumulative
sweep is exhausting some resource that 5s of idle does not
restore.

Conclusion: the stalls aren't TIME_WAIT-resolves-with-time
events. Something more structural is going on (`php -S` worker
state, opcache distribution across workers, OS scheduler).

### Lower concurrency (c=3)

Hypothesis: at c=3 we're well below the port-exhaustion
threshold (~28k ports / 60s TIME_WAIT = 466 conn/s sustainable;
c=3 × ~9k rps = 27k conn/s — still above, but ~3.5× lower than
c=10's pressure).

Result: 3 runs at c=3, d=5s, default cooldown — outliers
persisted, sometimes worse. Run 1 had `rxn POST 422` drop to
5,849 rps (others at 9-10k, a 40% gap). And — interestingly —
the PSR-7 advantage **inverted** at c=3: rxn ran ~9-12k rps
while rxn-psr7 ran 7-9k. So the apparent "PSR-7 wins" verdict
at c=10 was tied to the saturation regime, not a framework
property.

Conclusion: the cause isn't only port pressure. The Latin-square
appears to be an emergent artifact of running high-rate
benchmarks against `php -S` on this rig, regardless of the
specific bottleneck.

### What the rig actually is

The host is **WSL2** (`Linux 5.15.167.4-microsoft-standard-WSL2`).
WSL2 has documented scheduling jitter and TCP performance
variance vs bare-metal Linux. Running a single-binary CLI
server (`php -S`) at saturating load on WSL2 is essentially
guaranteed to produce noisy, hard-to-attribute samples. The
benchmark hits multiple noise sources at once (kernel TIME_WAIT,
WSL2 scheduler, `php -S` worker handoff, opcache distribution),
and no single mitigation removes more than a fraction of the
variance.

## What lands and what doesn't

- **Lands:** the curl handle-reuse fix
  (`fix(bench/compare): reuse curl handles instead of close-and-recreate per request`).
  Correct under any keep-alive server, partial mitigation under
  `php -S`. No risk.
- **Lands:** the median-window rps metric
  (`feat(bench/compare): report median-window rps alongside count/duration rps`).
  This is the actual fix. Both metrics now appear in CLI output
  and the markdown table; A/B verdicts should be read off the
  windowed value.
- **Doesn't land:** the env-configurable cooldown knob (tested,
  doesn't help — TIME_WAIT-resolves-with-time isn't the
  mechanism).
- **Doesn't land:** server swap. Still the right move for
  absolute-throughput claims and a published cross-framework
  table; tracked as future work, not a blocker.

## Cross-link

This investigation was triggered by the PSR-7 A/B writeup
identifying the Latin-square as an unfixed harness bug. The
A/B's verdict needs to be re-computed with the median-window
metric — see the next pass on `2026-05-01-psr7-end-to-end.md`.
