# Error handling

Rxn is exception-driven. Throwing an exception at any depth rolls
back in-flight database transactions and renders an RFC 7807
Problem Details document — the single error shape the whole
ecosystem (API gateways, client libraries, error aggregators)
already speaks.

```php
try {
    $result = $database->query($sql, $bindings);
} catch (\PDOException $exception) {
    throw new \Exception("Widget 42 does not exist", 404);
}
```

The client sees `Content-Type: application/problem+json` and:

```json
{
  "type": "about:blank",
  "title": "Not Found",
  "status": 404,
  "detail": "Widget 42 does not exist",
  "instance": "/api/v1/widgets/42",
  "x-rxn-elapsed-ms": "1.824 ms"
}
```

Field mapping:

| Field | Source |
|---|---|
| `type` | `about:blank` by default (category URI if you set one) |
| `title` | Standard HTTP status text for the code |
| `status` | HTTP status (from the exception's `$code`) |
| `detail` | Exception message |
| `instance` | Request URI |
| `x-rxn-elapsed-ms` | Diagnostic: time-to-error |

Success responses stay on the native `{data, meta}` envelope
— RFC 7807 is errors-only by design, and no comparable standard
covers successful JSON API responses without imposing JSON:API-style
resource typing.

## Exception types

Framework code should throw one of the subclasses of
`Rxn\Framework\Error\AppException`:

| Subsystem | Exception |
|---|---|
| Container / autowiring | `ContainerException` |
| Database connection / transactions | `DatabaseException` |
| Query execution | `QueryException` |
| Request parsing / validation | `RequestException` |
| Schema registry | `RegistryException` |
| ORM builder | `OrmException` |
| Everything else inside the framework | `CoreException` / `AppException` |

Application code is free to throw plain `\Exception` with an HTTP
status in the `$code` field. Anything outside the 100-599 range
falls back to a 500.

## Debug output

Outside `ENVIRONMENT=production`, the file / line / stack trace of
the exception tag along as `x-rxn-file`, `x-rxn-line`,
`x-rxn-trace` extension members on the Problem Details body — same
diagnostic surface the previous envelope carried, just under a
standards-compliant roof. In production those fields are stripped,
so failing payloads never expose server internals.

Set `ENVIRONMENT=production` in `.env` when shipping.
