# Dependency injection

`Rxn\Framework\Container` autowires constructors by type hint. There
is one container per application; services (classes extending
`Rxn\Framework\Service`) are cached as singletons after first
resolution. Non-service classes are re-instantiated on every `get()`.

## Resolving a class

```php
$customer = $container->get(Customer::class);
```

If `Customer::__construct` accepts typed parameters, the container
recursively resolves each one. Scalar parameters with defaults fall
through to the default value; nullable parameters with no default
receive `null`; parameters with neither throw a descriptive
`ContainerException`.

Circular dependencies are detected and reported:

```
Circular dependency detected while resolving: A -> B -> A
```

## Controller method injection

Type-hint what you need as a method parameter and the action invoker
resolves it from the container:

```php
public function show_v1(Request $request, Map $map)
{
    $id = $request->collectFromGet('id');
    // ...
}
```

This is equivalent to pulling each dependency out of the container
by hand, without the ceremony.

## When to use the container

- Do use it for framework classes (`Database`, `Map`, `Filecache`,
  `Auth`, etc.) and for your own services.
- Do not cache container-resolved services elsewhere — the container
  already caches them.
- Avoid adding a new `Service` subclass for something that doesn't
  need to be a singleton; a plain class instantiated per-request is
  usually simpler.
