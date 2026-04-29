# OPcache preload

Rxn ships a preload script at [`bin/preload.php`](../bin/preload.php)
that pre-compiles the entire framework into shared opcode cache at
php-fpm boot. Subsequent requests skip the parse / compile phase
entirely — every framework class is already linked in shared
memory, ready to dispatch.

## Wire-up

Set both knobs in your php.ini (or pool config):

```ini
opcache.preload = /var/www/rxn/bin/preload.php
opcache.preload_user = www-data
```

Reload php-fpm. On boot you'll see a single line in the error log:

```
rxn preload: compiled 72 files, skipped 52, 0 failed.
```

72 = the whole `src/Rxn/Framework` tree minus the test subtree, plus
the PSR-7 / PSR-15 interfaces it depends on.

## What gets preloaded

| Tree | Preloaded | Why |
|---|---|---|
| `src/Rxn/Framework/**` | yes (minus `Tests/`) | the framework hot path |
| `vendor/psr/http-message/**` | yes | PSR-7 interfaces — needed for `Psr15Pipeline` to link cleanly |
| `vendor/psr/http-server-handler/**` | yes | same |
| `vendor/psr/http-server-middleware/**` | yes | same |
| `vendor/psr/http-factory/**` | yes | same |
| `app/**` (your code) | no | stays out so app deploys don't require an fpm restart |
| `vendor/**` (other) | no | autoloaded on demand |

A typical preload uses about 1.1 MB of opcache memory — well within
the default `opcache.memory_consumption=128`.

## Why preload?

opcache normally caches bytecode per request, but the *first* request
after fpm starts still pays the parse-and-link cost for every file it
touches. With preload, that work happens once at fpm boot. The saving
is most visible on cold deploys and on long-tail requests that touch
classes the warm cache hasn't seen yet.

This is a deploy-time win, not a hot-path win — it doesn't move the
in-process `bin/bench` numbers, since those run inside a single
already-warm php process. To see the effect, measure cold-request
latency under php-fpm before and after wiring up the preload.

## Gotchas

- **Restart on framework upgrade.** Preloaded files live in shared
  memory until the master php-fpm process is recycled. After a
  framework upgrade, `systemctl reload php-fpm` (or equivalent) to
  pick up the new bytecode.
- **App code is *not* preloaded.** That's intentional — preloaded
  files require an fpm restart on every change, and you don't want
  to restart fpm every time a controller is edited. App code stays
  on the regular per-request opcache, which validates timestamps
  automatically.
- **`opcache.preload_user` must match.** php-fpm refuses to preload
  if it's running as root and `opcache.preload_user` is unset.
  Match it to your fpm pool user (typically `www-data` on Debian /
  Ubuntu, `nginx` on RHEL / Amazon Linux).
- **Apps extending the preload.** Apps that want to preload their
  own classes can write a thin wrapper that requires this file
  first, then walks `app/`:

  ```php
  <?php
  require __DIR__ . '/../vendor/davidwyly/rxn/bin/preload.php';
  // Your app classes:
  foreach (glob(__DIR__ . '/../app/**/*.php', GLOB_BRACE) as $f) {
      opcache_compile_file($f);
  }
  ```
