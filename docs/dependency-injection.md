# Dependency injection

`Rxn\Framework\Container` autowires constructors by type hint.
Resolved classes are cached as singletons — subsequent `get()`s
return the same instance.

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

## Binding interfaces to implementations

Autowiring can't instantiate an interface directly — there's no
concrete class to reflect on. Tell the container which
implementation to use with `bind()`:

```php
$container->bind(UserRepo::class, PostgresUserRepo::class);
$container->bind(Clock::class,    fn () => new FrozenClock('2026-01-01'));
```

Two forms:

- **Class-string binding:** `bind($abstract, $concreteClass)`. The
  container still autowires the concrete's constructor, so its
  deps resolve normally.
- **Factory closure:** `bind($abstract, fn(Container $c) => ...)`.
  The closure receives the container and returns a fully-built
  instance — handy when construction depends on runtime state.

Bindings are checked before autowiring, so any constructor that
type-hints `UserRepo` now receives the bound concrete. Re-binding
the same abstract overwrites the previous target.

## When to use the container

- Do use it for framework classes (`Database`, `Map`, `Filecache`,
  `Auth`, etc.) and for your own services.
- Do not cache container-resolved services elsewhere — the container
  already caches them.
- Avoid adding a new `Service` subclass for something that doesn't
  need to be a singleton; a plain class instantiated per-request is
  usually simpler.
