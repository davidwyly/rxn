# Request binding + validation

Controller actions take DTO classes as parameters; the framework
hydrates them from the request and validates them before your
action runs. Failures emit standards-shaped Problem Details with
every error at once — no one-at-a-time round-trip loop.

```php
use Rxn\Framework\Http\Attribute\{Required, Length, Min, Max, Pattern, InSet};
use Rxn\Framework\Http\Binding\RequestDto;

final class CreateProduct implements RequestDto
{
    #[Required]
    #[Length(min: 1, max: 100)]
    public string $name;

    #[Required]
    #[Min(0)]
    #[Max(1_000_000)]
    public int $price;

    #[Pattern('/^[a-z0-9-]+$/')]
    public string $slug = 'default-slug';

    #[InSet(['draft', 'published', 'archived'])]
    public string $status = 'draft';

    public bool $featured = false;

    public ?string $note = null;
}

class ProductsController extends \Rxn\Framework\Http\Controller
{
    public function create_v1(CreateProduct $input): array
    {
        return ['id' => $this->save($input)];
    }
}
```

`POST /v1.1/products` with `{"name": "Widget", "price": "1299"}`:

```json
{
  "data": { "id": 42 },
  "meta": { "success": true, "code": 200, "elapsed_ms": "1.842 ms" }
}
```

`POST /v1.1/products` with missing / bad fields — one response,
every failure:

```json
{
  "type": "about:blank",
  "title": "Unprocessable Entity",
  "status": 422,
  "detail": "Validation failed",
  "instance": "/v1.1/products/create",
  "errors": [
    { "field": "name",  "message": "is required" },
    { "field": "price", "message": "must be >= 0" },
    { "field": "slug",  "message": "does not match required pattern" }
  ]
}
```

## How it works

`Controller::invokeObjectsToInject` checks each method parameter:
if the type implements `Rxn\Framework\Http\Binding\RequestDto` it
calls `Binder::bind($class)` instead of the container. The Binder:

1. Merges `$_GET + $_POST` into a request bag (POST wins on
   conflicts). The `JsonBody` middleware (when installed) is what
   decodes `application/json` bodies into `$_POST`, so JSON and
   form requests both work.
2. Walks the DTO's public properties, coercing string request
   values to the declared PHP type:
   - `string` ← scalars
   - `int` ← numeric strings that round-trip (`"42"` yes, `"42.5"` no)
   - `float` ← any numeric
   - `bool` ← `true` / `false` / `1` / `0` / `yes` / `no` / `on` / `off`
   - `array` / `iterable` ← arrays
3. Runs every property-level validation attribute. Attributes
   implement the `Validates` contract — returning `null` means
   pass, a string means fail with that message.
4. Collects every failure into a list and throws
   `ValidationException` (422) at the end; success returns the
   populated DTO.

Missing fields with a default initializer or a nullable type pass
through untouched. Missing fields marked `#[Required]` (or that
are non-nullable without a default) fail.

## Validation attributes

| Attribute | Argument | Effect |
|---|---|---|
| `#[Required]` | — | Marker; field must be present and non-empty |
| `#[Min(n)]` | `int\|float` | Numeric lower bound (inclusive) |
| `#[Max(n)]` | `int\|float` | Numeric upper bound (inclusive) |
| `#[Length(min, max)]` | `?int`, `?int` | UTF-8 length bounds (either may be null) |
| `#[Pattern('/regex/')]` | `string` | Regex match (caller supplies delimiters) |
| `#[InSet(['a', 'b'])]` | `list<scalar>` | Enum-like membership |

Custom attributes: implement
`Rxn\Framework\Http\Binding\Validates::validate(mixed $value): ?string`
on any `#[Attribute(Attribute::TARGET_PROPERTY)]` class and the
Binder picks it up — no registration step.

## Attribute-routed controllers

The same DTO mechanism works under attribute routing; the only
difference is the dispatcher. In your route dispatcher, call
`Binder::bind()` for any method parameter whose type implements
`RequestDto`, then invoke the handler with the populated instance
alongside any container-resolved services.

## Why this shape

- **Fail fast, report completely.** Every validation error comes
  back in one response, so a client fixing a form doesn't need
  four round-trips to discover four problems.
- **Standards-shaped.** The error body is RFC 7807 Problem
  Details with an `errors` extension member. Any consumer that
  already knows 7807 gets field-level feedback for free.
- **No DSL.** Validation rules are PHP attributes with PHP types
  — your IDE autocompletes them, your static analyzer checks
  them, and they live next to the property they describe.
- **Always in sync.** The same reflection that powers DTO binding
  will feed request-body schemas into the OpenAPI generator (see
  [`docs/cli.md`](cli.md)), so the API contract doesn't drift
  from the code.
