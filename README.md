![alt tag](http://i.imgur.com/nu63B1J.png?1)

#### A small, opinionated JSON API framework for PHP.

##### Status: alpha. Targets PHP 8.1+ and ships a Docker stack on PHP 8.3-fpm.

Rxn (from "reaction") is built around a single opinion: **strict
backend/frontend decoupling**. The backend is API-only, responds in
JSON, and rolls up every uncaught exception into a JSON error
envelope. Frontends — web, mobile, whatever — build against the
versioned contracts and stay decoupled.

The framework aims, in order, to be **fast**, **minimal**, and
**easy to use**.

## Quickstart

```bash
composer install
vendor/bin/phpunit          # run the test suite
composer validate --strict  # sanity-check composer.json
bin/rxn help                # list CLI subcommands
```

Full Docker stack (PHP 8.3-fpm + nginx 1.27 + MySQL 8):

```bash
cp docker-compose.env.example .env
# edit .env: set MYSQL_PASSWORD and MYSQL_ROOT_PASSWORD
docker compose up --build
```

Set `INSTALL_XDEBUG=1` in `.env` to build the PHP image with Xdebug 3.

CI runs lint + phpunit against PHP 8.1, 8.2, 8.3, and 8.4
(`.github/workflows/ci.yml`).

## Documentation

| Topic | Where |
|---|---|
| Routing (convention + explicit patterns) | [`docs/routing.md`](docs/routing.md) |
| Dependency injection | [`docs/dependency-injection.md`](docs/dependency-injection.md) |
| Scaffolded CRUD | [`docs/scaffolding.md`](docs/scaffolding.md) |
| Error handling | [`docs/error-handling.md`](docs/error-handling.md) |
| Building blocks (Logger, RateLimiter, Scheduler, Auth, Pipeline, Router, Migration, Chain, query cache) | [`docs/building-blocks.md`](docs/building-blocks.md) |
| Contribution / style guide | [`CLAUDE.md`](CLAUDE.md) |

## Features

`[X]` = implemented, `[~]` = partial / has known gaps, `[ ]` = not started.

- [ ] 80%+ unit test code coverage *(currently minimal; see
      `src/Rxn/**/Tests/` for what's covered)*
- [X] Gentle learning curve
   - [X] Installation through Composer
- [~] Simple workflow with an existing database schema
   - [X] Code generation
      - [X] CLI utility to create controllers and models
            (`bin/rxn make:controller`, `bin/rxn make:record`)
- [X] Database abstraction
   - [X] PDO for multiple database support
   - [X] Support for multiple database connections
- [~] Security
   - [X] Prepared statements (SQL injection)
   - [~] Session cookies use HttpOnly + SameSite=Lax and turn on
         Secure automatically when the request is HTTPS
   - [X] Stack traces only in non-production environments
   - [~] I/O sanitization (control-character stripping when
         `APP_USE_IO_SANITIZATION=true`)
   - [X] CSRF synchronizer tokens
   - [~] Authentication (bearer-token resolver; app supplies the
         token→user lookup)
   - [X] Rate limiting
- [X] Exception-driven error handling
- [X] Versioning (versioned controllers + actions)
- [X] Scaffolding (version-less CRUD against a live schema)
- [X] URI Routing
   - [X] Convention-based (`/v{N}/{controller}/{action}/key/value/...`)
   - [X] Explicit pattern routing (`Rxn\Framework\Http\Router`)
   - [X] Apache 2 (.htaccess)
   - [X] NGINX (see `docker/nginx`)
- [X] Dependency Injection container
   - [X] Controller method injection
   - [X] DI autowiring via constructor type hints
   - [X] Circular-dependency detection
- [~] Object-Relational Mapping
   - [X] Scaffolded CRUD on a record
   - [X] FK relationship graph (`Data\Chain` + `Link`)
   - [ ] Soft deletes
   - [ ] Support for third-party ORMs
- [X] HTTP middleware pipeline
- [X] Speed and performance
   - [X] PSR-4 autoloading
   - [X] File-backed query caching
   - [X] Object file caching (atomic writes)
- [X] Event logging (JSON-lines)
- [X] Scheduler (interval / predicate based)
- [X] Database migrations (`*.sql` runner)
- [ ] Mailer *(out of scope; use symfony/mailer or phpmailer)*
- [X] Request validation *(rule-based `Validator::assert`; see
      `Rxn\Framework\Utility\Validator`)*
- [ ] Automated API request validation from contracts
- [ ] Optional, modular plug-ins

## License

Rxn is released under the permissive [MIT](https://opensource.org/licenses/MIT) license.
