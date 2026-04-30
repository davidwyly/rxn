# A/B microbenchmark driver

`bench/ab.php` runs the same `bin/bench` microbenchmark suite
against two git refs back-to-back and reports per-case **median
ops/sec for each side, percent delta, [min, max] range per side,
and a verdict** (win / regression / noise / uncertain).

It exists so a code change with a credible mechanism — "this
caches reflection so the autowire path should be faster" — can be
proven or disproven before it lands, instead of guessed at.

## Quick start

```bash
# Compare two committed refs:
php bench/ab.php --a=main --b=feat/fast-router

# Limit cases by substring match on the case name:
php bench/ab.php --a=HEAD~1 --b=HEAD --filter=container

# Run more iterations to tighten the verdict:
php bench/ab.php --a=main --b=HEAD --runs=9

# Keep the temporary worktrees around for inspection:
php bench/ab.php --a=main --b=HEAD --keep
```

Defaults: `runs=5`, all cases, worktrees deleted on exit.

Both refs must be committed — the driver materialises each ref
into its own `git worktree --detach` checkout under
`/tmp/rxn-ab-<sha>-<rand>/`, runs `composer install` per worktree,
then runs `php bin/bench --json` N times against each. Worktree
isolation guarantees each side gets its own vendor tree, so a
`composer.json` change between A and B can't contaminate the
result.

## How to read the verdict

| label | meaning |
|---|---|
| `win` | Δ > +5% AND A.max < B.min — i.e. every B run beat every A run, and the median improvement is past the per-run noise floor. |
| `regression` | Δ < −5% AND B.max < A.min — symmetric to the above. |
| `noise` | abs(Δ) < 5%. The 5% threshold is the per-run variance `bin/bench` documents; below it, you can't tell signal from jitter without much longer runs. |
| `uncertain` | abs(Δ) > 5% but the [min, max] ranges overlap. The medians are far apart but at least one A run beat at least one B run (or vice versa). Re-run with `--runs=9` or higher; if the verdict stays uncertain, the change probably isn't real. |

The non-overlapping-range check is a non-parametric stand-in for
a Welch's t-test; with N=5 it's at roughly p ≈ 0.04 — adequate
for a homebrew tool, not adequate for a published claim.

## Methodology — what the driver controls for, and what it doesn't

**Controls for:**
- Same machine, same PHP binary, same opcache state across runs.
- Both refs get a fresh `composer install` (no shared vendor/),
  so a dep that changed between A and B is reflected in the
  result.
- Median across N runs, not mean — robust against the occasional
  GC spike.
- Verdict requires both effect-size *and* range separation; you
  can't earn a `win` by getting lucky on one outlier run.

**Doesn't control for:**
- **Order.** A is always run before B. On a thermally-throttled
  laptop the second run can be slower simply because the CPU got
  hotter. For numbers you intend to publish, alternate the order
  by hand and verify the verdict is the same.
- **Other workloads on the box.** Don't run a build / test /
  Slack call concurrently.
- **Microarchitectural noise** below 5%. The driver is honest
  about this — it calls anything under the floor "noise" rather
  than reporting a number that's too precise to mean anything.

## Workflow for proposing an optimisation

1. **State the hypothesis.** "Caching the route compile result
   should improve `router.match.static` because it removes the
   regex compile from the hot path." A change with no mechanism
   isn't a candidate; it's a vibe.
2. **Implement on a topic branch** off the integration branch.
3. **Run the driver** at `--runs=5`. If verdict is `win` or
   `regression`, you're done — record the number and decide.
4. **If `uncertain`**, run again at `--runs=9`. Don't cherry-pick
   the better of two runs.
5. **If `noise`**, the change isn't worth shipping for that
   reason. It might still be worth shipping for code-clarity
   reasons; that's a separate decision the driver can't make.
6. **Write up the experiment** in `experiments/<date>-<slug>.md`
   so the next maintainer doesn't re-run the same idea.

## Experiments to date

See [`experiments/`](experiments/) — each file is one hypothesis,
the change tested, the actual verdict, and the decision. Negative
results (`noise`, `uncertain`, `regression`) are written up
alongside wins; preserving them prevents the same dead-end from
being re-tried.
