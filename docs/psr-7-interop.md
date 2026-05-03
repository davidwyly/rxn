# PSR-7 / PSR-15 interop

> **Status (2026-05-01):** This page predates the PSR-15 native
> migration on `experiment/psr-7-refactor`. After that branch
> lands, the framework is **PSR-15-native end-to-end** — one
> `Pipeline`, one `MiddlewareInterface` (PSR-15), all eight
> shipped middlewares migrated, PSR-7 ingress via
> `PsrAdapter::serverRequestFromGlobals` as the default. The "two
> stacks" framing below describes the previous shape (native +
> PSR-15 escape hatch); the bench evidence for the *ingress* cost
> was re-measured end-to-end and PSR-7 won by 9–14% on
> binder-driven cells (see
> `bench/ab/experiments/2026-05-01-psr7-end-to-end.md`). This
> document needs a rewrite once the branch merges; it's left in
> place for now because the cost analysis (immutable builder,
> clone-chain, JSON-only narrowing) is still the right framing
> for *why* you'd want a fast-path ingress.

Rxn is **PSR-15-bridged, not PSR-7-native**, deliberately. This is
the most-questioned design decision in the framework, so this
document is its receipts.

## The two stacks

| Stack | Request shape | Pipeline | Middleware interface | When to use |
|---|---|---|---|---|
| **Native** (default) | `Rxn\Framework\Http\Request` (custom) | `Http\Pipeline` | `Rxn\Framework\Http\Middleware` (`handle(Request, callable): Response`) | Default. The throughput numbers in the README live here. |
| **PSR-15** (escape hatch) | `Psr\Http\Message\ServerRequestInterface` | `Http\Psr15Pipeline` | `Psr\Http\Server\MiddlewareInterface` (PSR-15) | When you need ecosystem PSR-15 middleware (CORS, OAuth, OpenTelemetry, ...) that doesn't have a native equivalent. |

A single dispatch chooses one or the other. They don't compose
inside the same pipeline. This is the same shape principle 5 —
"convention default, explicit escape hatch" — applies to the HTTP
shape itself.

## Why native is the default

Three reasons, recorded in the codebase:

### 1. PSR-7's immutable-builder pattern is allocation-heavy

The PSR-7 fast-from-globals experiment
([`bench/ab/experiments/2026-04-29-psr-fast-from-globals.md`](../bench/ab/experiments/2026-04-29-psr-fast-from-globals.md))
measured Nyholm's `ServerRequestCreator::fromGlobals()` directly:

> For a typical request that's:
> - Uri: 1 base + 4–5 with*() clones (scheme/host/port/path/query)
> - ServerRequest: 1 base + 1 clone per header (`withAddedHeader`)
>   + 1 protocol-version + 1 cookies + 1 query + 1 parsedBody + 1
>   uploaded-files
>
> ≈ 15+ allocations. Most of them write a single private property
> that the constructor could have set in the first place.

That's just construction, before any middleware step has run. Each
`with*()` middleware step does another `clone`. Going PSR-7-native
means paying that overhead on every request and every middleware
step. The experiment netted **+124%** by going around it for the
one place we do build a PSR-7 request.

### 2. Principle 4 generalises this

[`design-philosophy.md#4`](design-philosophy.md) records:

> Schema-compiling user-space code only beats the baseline when
> the baseline is also user-space. Don't try to outrun a C
> extension from PHP.

The dual of that lesson is: **don't import PHP overhead you
can't compile away.** PSR-7's immutable graph (Uri + Stream +
UploadedFile + ServerRequest, each with their own `with*()`
clones) is exactly that kind of overhead. There's nothing for
`Validator::compile`-style code-gen to remove — the cost is in
`clone` itself, which is already at C speed and unavoidable in
the contract. So the framework opts out of the contract for the
hot path.

### 3. JSON-only narrowing doesn't need PSR-7's generality

PSR-7 carries the apparatus for streamed bodies, content
negotiation between HTML / JSON / XML / streams, the message-vs-
server-request distinction, multiple body shapes, and an
ecosystem of factories per interface. Rxn's commitment
([principle 1](design-philosophy.md), "opinionated narrowing") is
**JSON in, JSON out, RFC 7807 errors, period.** Most of PSR-7's
shape is unused under that commitment, and "we pay for what we
don't use" is exactly the cost the framework is trying to avoid.

## When the bridge IS the right answer

The PSR-15 path isn't a fallback or a placeholder — it's the
correct choice when:

- You need ecosystem middleware that doesn't have a native
  equivalent (`tuupola/cors-middleware`, OpenTelemetry HTTP
  instrumentation, certain OAuth flows, framework-portable
  rate limiters, ...).
- A host hands you a `ServerRequestInterface` already
  (RoadRunner with the PSR-7 worker, some Swoole bridges) — you
  don't pay the construction cost.
- You're writing libraries that should be portable across PHP
  micro-frameworks.

In all these cases, opt into `Psr15Pipeline` for that route. You
trade a fraction of the throughput for the interop unlock, and
the rest of the framework stays unchanged.

## When to pick which

### Use the native stack when

- You want the throughput numbers in the README (the optimised
  path lives here).
- Every middleware you need is in
  `Http\Middleware\` already.
- You're writing app middleware against `Http\Request` directly
  and don't need ecosystem PSR-15 components.

### Use the PSR-15 stack when

- You need a specific ecosystem PSR-15 middleware that doesn't
  exist as a native Rxn one — `php-middlewares/*`,
  `tuupola/cors-middleware`, OAuth/JWT middlewares, OpenTelemetry
  HTTP instrumentation, etc.
- You're plugging Rxn into a host that hands you a
  `ServerRequestInterface` already (RoadRunner with the
  PSR-7 worker, some Swoole bridges).
- You're building libraries that should be portable across PHP
  micro-frameworks.

The PSR-15 path costs the Nyholm clone chain on construction
(unless you're handed the request) and an interface check per
middleware step. On `php -S` micro-benchmarks the difference is
visible; on FPM-behind-nginx it's typically lost in the noise.

## How to use the PSR-15 stack

```php
use Rxn\Framework\Http\PsrAdapter;
use Rxn\Framework\Http\Psr15Pipeline;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$request = PsrAdapter::serverRequestFromGlobals();

$pipeline = (new Psr15Pipeline())
    ->add(new SomeEcosystemMiddleware())    // any psr/http-server-middleware
    ->add(new AnotherPsr15Middleware());

// Terminal handler — returns a PSR-7 ResponseInterface.
$terminal = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Build your response with PsrAdapter::factory() (Nyholm).
        return PsrAdapter::factory()
            ->createResponse(200)
            ->withHeader('content-type', 'application/json')
            ->withBody(PsrAdapter::factory()->createStream('{"ok":true}'));
    }
};

$response = $pipeline->run($request, $terminal);
PsrAdapter::emit($response);
```

`PsrAdapter::factory()` returns Nyholm's PSR-17 factory, which
implements every PSR-17 interface — use it to construct
requests, responses, streams, or uploaded files when you need to.

## Crossing the boundary

There is **no automatic adapter** between the two stacks in either
direction. A PSR-15 middleware cannot run inside `Http\Pipeline`
unless you write a custom shim, and a native `Http\Middleware`
cannot run inside `Http\Psr15Pipeline` unless you write the
inverse shim. This is deliberate: an automatic adapter would have
to construct a PSR-7 request from `Http\Request` (or vice versa),
which means walking superglobals on every middleware step and
defeating the optimisation that motivated the split in the first
place.

If you find yourself needing both shapes in the same request,
that's the signal to consolidate on the PSR-15 stack for that
route — not to bridge mid-pipeline.

## Future work

The dual-stack is **not** a transitional state — it's the design.
A hypothetical "PSR-7-everywhere" v2 would re-import the
allocation cost the framework has explicitly chosen to opt out of,
and there's no code-gen trick to reclaim it (see reason 2 above).
What's worth doing instead, if interop demand grows:

- A small adapter that wraps a PSR-15 middleware as an
  `Http\Middleware`, so individual ecosystem components can be
  used inside the native pipeline (paying PSR-7 construction
  *only on that step*, not for the whole pipeline).
- Sharper docs on which native middlewares have ecosystem
  PSR-15 equivalents and when each is the right call.

Until/unless those land: pick a stack per route, document which
one you're on, and use this page to settle which side of the
boundary each piece of middleware lives on.
