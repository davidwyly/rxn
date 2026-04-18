# Rxn documentation

Rxn is a small JSON API framework for PHP 8.2+. The goal, in order,
is **fast**, **minimal**, and **easy to use**.

These pages go into more depth than the top-level README quickstart.

## Request lifecycle

A single request enters through `public/index.php`, boots the
environment via `Startup`, then flows through the optional router
and middleware pipeline before landing at a controller action.
Every uncaught exception rolls back into `Response::getFailure`
and renders as the standard JSON envelope — there is no other exit
path.

```mermaid
sequenceDiagram
    autonumber
    participant Client
    participant Index as public/index.php
    participant App
    participant Startup
    participant Registry
    participant Router as Router (optional)
    participant Pipeline as Pipeline (optional)
    participant Controller
    participant Response

    Client->>Index: HTTP request
    Index->>App: new App()
    App->>Startup: boot (.env, autoload, databases)
    App->>Registry: reflect schema (lazy)
    App->>App: resolve Request + Api
    alt explicit routing
        App->>Router: match(method, path)
        Router-->>App: Route (handler, params, middlewares)
    else convention routing
        App->>App: version/controller/action from URL
    end
    opt middleware attached
        App->>Pipeline: handle(request, dispatcher)
    end
    App->>Controller: trigger()
    Controller->>Controller: invoke action_v{N}
    alt success
        Controller->>Response: getSuccess($data)
    else thrown exception
        Controller->>Response: getFailure($exception)
    end
    Response-->>App: populated envelope
    App-->>Client: JSON envelope
```

## Topics

| Topic | Notes |
|---|---|
| [Routing](routing.md) | Convention-based URLs and the explicit `Router` |
| [Dependency injection](dependency-injection.md) | Container, autowiring, method injection |
| [Scaffolding](scaffolding.md) | Auto-CRUD endpoints against a live schema |
| [Error handling](error-handling.md) | Exceptions + JSON envelope |
| [Building blocks](building-blocks.md) | Logger, RateLimiter, Scheduler, Auth, Pipeline, Migration, Chain |
| [CLI](cli.md) | `bin/rxn` — migrations and scaffolding |
| [Benchmarks](benchmarks.md) | `bin/bench` — microbenchmarks for the building blocks |

The full list of features and their implementation status lives in
the top-level [README](../README.md). Framework-level conventions
and contribution guidance live in [CONTRIBUTING.md](../CONTRIBUTING.md).
