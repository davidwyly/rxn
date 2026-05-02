# Plugins

Rxn keeps a small core. A handful of optional capabilities ship
as separate Composer packages — currently
[`davidwyly/rxn-orm`](https://github.com/davidwyly/rxn-orm) (the
query builder + ActiveRecord layer). Apps that don't reach for
those capabilities don't pay their cost.

That's the whole architecture. There isn't a formal plugin
contract because there aren't enough plugins yet for the
contract to be useful. When there are, this doc grows.

## What lives in core, what lives outside

**In core:**

- Router, Pipeline, Container, Binder, Response, App
- All eight shipped middlewares
- The Validator + the schema-compiled fast path
- The PSR-7/15/11/3/14 conformance bits
- The OpenAPI generator
- `Rxn\Framework\Codegen\JsValidatorEmitter` — generates a
  vanilla JS validator from any `RequestDto`. Useful for PHP
  shops with a TypeScript / vanilla-JS frontend that want
  drift-free validation across the wire.
- `Rxn\Framework\Codegen\Testing\ParityHarness` — the property-
  test rig that the JS validator's correctness rests on
  (runs adversarial inputs through PHP and the emitted JS,
  asserts agreement on the failing-field set per input).

**Outside core (separate Composer packages):**

- `davidwyly/rxn-orm` — query builder, ActiveRecord, scaffolded
  CRUD. Independently versioned. Apps using Rxn purely for
  routing / DTO binding / middleware don't install it.

## Why not a sprawling plugin family

A previous version of this document outlined a multi-language
plugin family (`rxn-client-typescript`, `rxn-client-python`,
`rxn-client-go`, etc.) with a formal parity-test contract. That
ambition has been scoped down for two reasons:

1. **Audience mismatch.** PHP shops mostly aren't polyglot;
   polyglot teams mostly aren't on PHP. The audience for "PHP
   framework that ships first-party clients in five languages"
   is the small intersection of those — not enough to justify
   the maintenance overhead.
2. **Wrong vehicle.** If validator parity across runtimes is
   genuinely valuable (it is), the substrate that delivers it
   shouldn't be a PHP-CLI codegen tool that polyglot devs have
   to install PHP to run. It should be a language-agnostic
   spec format with native generators in each target's
   ecosystem.

That second observation pointed toward a separate, language-
neutral project. The cross-language parity work as a
**framework feature** stops at "PHP + JS, useful when both run
on the same product." The cross-language parity work as a
**bigger idea** lives elsewhere now.

## When a plugin is worth extracting

Reasonable signals for extracting capability X out of core into
its own Composer package:

- **Independent release cadence.** X needs to ship faster (or
  slower) than core's major releases.
- **Distinct dependency graph.** X pulls in libraries that
  core users shouldn't have to install.
- **Distinct audience.** Most apps using core don't reach for
  X (the rxn-orm case — apps using Rxn purely for HTTP routing
  don't need a query builder).
- **Distinct maintainer interest.** Someone other than the
  core maintainer wants to own X's lifecycle.

When two or more of those apply, extraction is justified.

## Repository conventions

For the one extracted plugin (`davidwyly/rxn-orm`):

- Lives in its own GitHub repo under the same org as core
- `composer require davidwyly/rxn-orm` to install
- `suggest`'d from core's `composer.json` so it shows up in
  install hints without being required
- Major version locked to core's major (rxn 1.x → rxn-orm 1.x);
  patch and minor versions independent

## What "first-party" means

Means the same person (or team) owns both core and the plugin,
and the plugin's release cadence is coordinated with core
through major versions. It's a maintenance promise, not a
technical property.

If a third party wants to maintain a plugin, that's fine —
they can publish under their own Composer name, and core's
docs can link to community plugins that meet a quality bar.
But "first-party" specifically means the davidwyly-owned
plugins listed above.
