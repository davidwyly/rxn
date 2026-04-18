# Scaffolding

Rxn ships a CRUD scaffold that reflects any table in the configured
database and exposes it over HTTP without you writing the endpoints.

```
https://yourapp.tld/api/order/create
https://yourapp.tld/api/order/read/id/{id}
https://yourapp.tld/api/order/update/id/{id}
https://yourapp.tld/api/order/delete/id/{id}
https://yourapp.tld/api/order/search
```

The segment after `/api/` is the record name (matching a class under
your app's `Model\` namespace that extends `Rxn\Framework\Model\Record`).
Primary keys, required columns, and the column list are pulled from
`information_schema` and cached.

## How it works

1. `Rxn\Framework\Http\CrudController` dispatches one of
   `create / read / update / delete / search` to the matching `Record`
   instance resolved from the container.
2. `Rxn\Framework\Model\Record` handles the actual SQL, binding
   every user value through PDO. Column names come from schema
   reflection, never from the request.
3. `Rxn\Framework\Data\Map` + `Rxn\Framework\Data\Map\Table` reflect
   the schema once and cache it through `Filecache` so the reflection
   cost isn't paid per request.

## When to migrate off the scaffold

Scaffolded endpoints are intentionally version-less: they reflect
whatever the schema looks like right now. That makes them ideal for
early prototyping and terrible for a stable public API, because a
schema change instantly rewrites the contract.

Before a release, replace scaffolded endpoints with versioned
controllers that:

- Validate input explicitly at the boundary.
- Project the DB columns you want to expose, rather than every
  column the table happens to have.
- Live at a stable URL (`/v1/orders/{id}` rather than
  `/api/order/read/id/{id}`).
