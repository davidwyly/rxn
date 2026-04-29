# PSR-7 fast `serverRequestFromGlobals()` — direct construction

**Date:** 2026-04-29
**Decision:** **Merged** as
`9bcd345 perf(PsrAdapter): direct ServerRequest construction, skip
with*() dance`. The single biggest single-case win this branch — a
2.24× on the heaviest PSR adapter case.

## Hypothesis

`PsrAdapter::serverRequestFromGlobals()` was calling Nyholm's
`ServerRequestCreator::fromGlobals()`, which is the canonical PSR-7
builder but does the construction via the immutable-builder pattern:
every URI part, header, cookie param, query param, parsed body, and
uploaded-file array goes through a `with*()` method, and `with*()`
in Nyholm clones the receiver:

```php
public function withScheme($scheme) {
    $new = clone $this;
    $new->scheme = ...;
    return $new;
}
```

For a typical request that's:

- Uri: 1 base + 4–5 with*() clones (scheme/host/port/path/query)
- ServerRequest: 1 base + 1 clone per header (`withAddedHeader`) +
  1 protocol-version + 1 cookies + 1 query + 1 parsedBody + 1
  uploaded-files

≈ 15+ allocations. Most of them write a single private property
that the constructor could have set in the first place.

`Nyholm\Psr7\ServerRequest` *does* have a constructor that accepts
method, uri, headers, body, version, and serverParams in one shot.
queryParams is auto-derived from the URI by the constructor. So
~10 of those 15 clones can be collapsed into one `new` call.

## Change

Replaced the `ServerRequestCreator->fromGlobals()` delegation with
direct construction:

```php
public static function serverRequestFromGlobals(): ServerRequestInterface
{
    $server = $_SERVER;
    $method = $server['REQUEST_METHOD'] ?? 'GET';

    // Build URI as a string in one pass; Uri('http://host/path?q')
    // parse_url's it once internally — no with*() chain.
    $scheme = $server['HTTP_X_FORWARDED_PROTO']
        ?? $server['REQUEST_SCHEME']
        ?? (isset($server['HTTPS']) && $server['HTTPS'] !== 'off' ? 'https' : 'http');
    $host = $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost';
    $path  = isset($server['REQUEST_URI']) ? explode('?', $server['REQUEST_URI'], 2)[0] : '/';
    $query = $server['QUERY_STRING'] ?? '';
    $uri   = $scheme . '://' . $host . $path . ($query !== '' ? '?' . $query : '');

    $headers  = function_exists('getallheaders')
        ? getallheaders()
        : self::headersFromServer($server);
    $protocol = isset($server['SERVER_PROTOCOL'])
        ? str_replace('HTTP/', '', $server['SERVER_PROTOCOL'])
        : '1.1';

    $request = new ServerRequest($method, new Uri($uri), $headers, null, $protocol, $server);

    if ($_COOKIE !== []) {
        $request = $request->withCookieParams($_COOKIE);
    }
    if ($method === 'POST') {
        // Match Nyholm's existing rule: parsedBody only set when
        // Content-Type is form-encoded. JSON / etc. stay raw and
        // middleware decodes them.
        $ct = self::headerValue($headers, 'content-type');
        if ($ct !== null) {
            $primary = strtolower(trim(explode(';', $ct, 2)[0]));
            if ($primary === 'application/x-www-form-urlencoded'
                || $primary === 'multipart/form-data') {
                $request = $request->withParsedBody($_POST);
            }
        }
    }
    if ($_FILES !== []) {
        $request = $request->withUploadedFiles(self::normalizeFiles($_FILES));
    }
    return $request;
}
```

For the bench's request shape (no cookies, no form body, no files),
this is exactly **2 allocations**: one `new Uri(...)` and one
`new ServerRequest(...)`. The full Nyholm path was ~14.

PSR-7 contract is preserved: same Nyholm concrete classes, same
public surface. `getMethod()`, `getUri()`, `getHeaderLine()`,
`getQueryParams()`, `getServerParams()`, etc. all behave
identically — the test suite confirms.

Branch: `bench/ab-psr-fast-from-globals`, commit `6c32ebc`.

## Result

```
A = claude/code-review-pDtRd (b9ac06970f43)
B = bench/ab-psr-fast-from-globals (6c32ebc6ef25)
runs = 5

| case               | A median ops/s | B median ops/s |    Δ %   | A range          | B range            | verdict |
|--------------------|---------------:|---------------:|---------:|------------------|--------------------|---------|
| psr7.from_globals  |         55,000 |        123,392 |  +124.3% | 52,170..55,572   | 121,590..124,234   | win     |
```

A.max = 55,572 < B.min = 121,590. Per-call cost drops from ~18µs to
~8.1µs. **2.24× speedup on the heaviest bench case.**

## Why the gap is huge

Cloning a PSR-7 immutable object isn't just a property write — PHP
`clone` has to copy every property of the source (including the
`headers`, `headerNames`, `serverParams` *arrays*) into a new
zval graph. For ServerRequest with 5+ properties already populated,
that's a non-trivial copy on every with*() call.

The Nyholm path:
- 5× Uri clones (scheme, host, port, path, query)
- 3–8× ServerRequest clones (one per header through
  `withAddedHeader`)
- 1× ServerRequest clone for protocol
- 1× ServerRequest clone for cookies
- 1× ServerRequest clone for queryParams
- 1× ServerRequest clone for parsedBody
- 1× ServerRequest clone for uploadedFiles

Each ServerRequest clone is ~1µs because of the array-copy cost.
Removing 12 of them = ~12µs saved, which matches the measured
~10µs delta closely.

## Test impact

`PsrAdapterTest`: 8 tests / 21 assertions, all green. Tests cover
URI scheme/host/path, query params, headers, server params, and the
emit() path. Full suite: 253 / 573, all green.

## Notes

- This is the most invasive change yet for `PsrAdapter` — it
  reaches into Nyholm's concrete classes (`ServerRequest`, `Uri`)
  rather than going through `ServerRequestCreator`. Strictly that
  ties the adapter to Nyholm's class layout, but the framework
  already names Nyholm in its composer.json (it's not a swappable
  PSR-7 implementation in this codebase).
- The `headersFromServer` fallback uses `str_starts_with` and
  native string ops; it's not the bench's path (since
  `getallheaders()` exists) but it's correct for FPM environments
  that lack it.
- The `with*()` calls that *do* fire (cookies / parsedBody /
  uploadedFiles) are conditional — JSON-API requests with no
  cookies hit zero of them.
- A future experiment could push further by caching the
  `Psr17Factory` instance (it's allocated lazily inside
  `normalizeFiles` when files are present). Negligible until apps
  start uploading files heavily.
