# Error handling

Rxn is exception-driven. Throwing an exception at any depth rolls
back in-flight database transactions and returns a JSON error
envelope.

```php
try {
    $result = $database->query($sql, $bindings);
} catch (\PDOException $exception) {
    throw new \Exception("Something went terribly wrong!", 422);
}
```

The client sees:

```json
{
  "_rxn": {
    "success": false,
    "code": 422,
    "result": "Unprocessable Entity",
    "message": "Something went terribly wrong!"
  }
}
```

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

File paths, line numbers, and stack traces are only included in the
JSON envelope when `ENVIRONMENT` is not `production`. In production
the payload contains only `type`, `message`, and `code`.

Set `ENVIRONMENT=production` in `.env` when shipping.
