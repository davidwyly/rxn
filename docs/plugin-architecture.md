# Plugin architecture

Rxn ships as a small core (~11K LOC) with first-party plugins
distributed as separate Composer packages. This document captures
the decision, the contract every plugin must satisfy, and the
repository / versioning conventions.

The first plugin family this is aimed at is **cross-language
client generators** (`rxn-client-typescript`,
`rxn-client-python`, etc.) but the architecture is general — any
non-essential capability that benefits from independent
versioning, optional installation, or distinct dependency
graphs is a plugin candidate.

## The decision

> **First-party plugins, in the davidwyly GitHub org, version-locked
> to core for major releases, independent for patches. Each plugin
> ships with a parity test that runs the framework's
> `ParityHarness` against the plugin's output and asserts
> agreement with the PHP reference implementation.**

Three things follow from this:

1. **Core stays small.** Plugin code never lands in
   `davidwyly/rxn`. The framework's headline LOC density —
   ~11K shipping what comparable Slim/Mezzio compositions
   reach in 70–100K — is a real product property; bundling
   generators (a few thousand LOC each) into core dilutes it
   directly. Plugins keep that property unchanged regardless of
   how many ship.

2. **Each plugin has its own dependency graph.** A Rust
   client generator might want `nikic/PHP-Parser` for advanced
   reflection. A TypeScript generator might want a JSON Schema
   → TS types library. None of these belong in the framework's
   runtime dependency graph. Plugins isolate the bloat to users
   who opt in.

3. **The plugin contract is enforceable.** Cross-language
   generators must pass the `ParityHarness` — a property test
   that runs N adversarial inputs through the plugin's output
   and the PHP reference, asserts the set of failing fields
   agrees on every input. The harness lives in core; plugins
   import it. Conformance isn't "trust the maintainer," it's
   "the harness verified it before the tagged release."

## Why not bundled

The straightforward alternative is to ship every generator inside
`davidwyly/rxn` itself. We don't, for four reasons:

- **LOC density.** As above. The "small framework that punches
  above its weight" claim depends on shedding non-essential code
  to optional packages.
- **Independent release cadence.** A breaking change in Rust 2026
  syntax shouldn't force a `davidwyly/rxn` patch release. A
  TypeScript codegen bug shouldn't block a PHP-only user.
- **Independent dependency graphs.** Apps that don't consume
  cross-language clients shouldn't autoload generator code, even
  cold.
- **Maintenance lane.** If the project ever grows beyond a single
  maintainer, plugins are the natural unit of contribution.
  "Take ownership of `rxn-client-rust`" is an order of magnitude
  smaller commitment than "contribute to the framework."

## Why not third-party-only

The other alternative is "the framework provides the reflection
primitives; third parties build whatever generators they want."
We don't pursue this either:

- **Marketing fragmentation.** "Rxn ships parity-tested
  cross-language clients" is a stronger headline than "Rxn has
  a community of plugins of varying quality." The first-party
  posture is what makes the parity guarantee credible.
- **Version drift.** Without first-party ownership, plugins
  fall out of sync with core's reflection API. A user
  installing `rxn-client-typescript@1.5` against `rxn@2.1` has
  no signal whether they're compatible.
- **Discovery.** Users shouldn't need to grep GitHub for working
  generators. The first-party list is finite, documented, and
  discoverable.

The Symfony bundle / Laravel first-party-package pattern is the
closest fit. Both ecosystems sustain a healthy community of
third-party plugins around a stable first-party set; Rxn aims
at the same shape.

## The plugin contract

Every plugin in the cross-language family **must** satisfy:

### 1. Composer package shape

```json
{
    "name": "davidwyly/rxn-client-<target>",
    "type": "library",
    "require": {
        "php": "^8.2",
        "davidwyly/rxn": "^<MAJOR>.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "Rxn\\Plugin\\Client\\<Target>\\": "src/"
        }
    },
    "bin": ["bin/rxn-client-<target>"]
}
```

Naming convention is rigid: package name, namespace prefix
(`Rxn\Plugin\<Family>\<Target>`), and `bin/` script all match
the target language slug.

### 2. The generator class

A single class with a single method:

```php
namespace Rxn\Plugin\Client\Typescript;

final class Generator
{
    public function emit(string $dtoClass): string
    {
        // Read $dtoClass via reflection — same primitives the
        // framework's OpenAPI generator and Binder use.
        // Emit the target-language source as a string.
    }
}
```

No DI, no configuration, no state. The framework's reflection
machinery is the input; the target source is the output.

### 3. The parity test

Plugins import `Rxn\Framework\Codegen\Testing\ParityHarness`
(introduced alongside this doc — see PR #14) and run it against
their generator's output:

```php
namespace Rxn\Plugin\Client\Typescript\Tests;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Codegen\Testing\ParityHarness;
use Rxn\Plugin\Client\Typescript\Generator;
use Rxn\Plugin\Client\Typescript\Tests\Fixture\ParityDto;

final class ParityTest extends TestCase
{
    public function testGeneratorAgreesWithPhpOnRandomInputs(): void
    {
        $disagreements = ParityHarness::run(
            dto:        ParityDto::class,
            generator:  fn ($class) => (new Generator())->emit($class),
            language:   'typescript',
            iterations: 10_000,
        );
        $this->assertSame(0, $disagreements);
    }
}
```

The harness handles input generation, dual-runtime execution
(PHP via `Binder::bind`, target language via shell-out to its
runtime), and field-set diffing. The plugin only provides the
generator and a target-language runtime invocation hook.

**A plugin doesn't ship until its parity test passes at zero
disagreements over 10K inputs.** This is the load-bearing
property of the architecture.

### 4. CI configuration

Each plugin's CI must:

- Install the target-language runtime (Node, Python, Go, etc.)
- Run `composer install`
- Run `composer test` — which includes the parity test
- Pass on every PR

The harness's runtime requirement (e.g. Node ≥ 18 for
TypeScript) is documented in the plugin's README and pinned in
its CI config.

### 5. Versioning policy

- Plugins follow semver.
- **Plugin major version is locked to core's major.** A `rxn-client-typescript`
  in the `1.x` line works against `rxn ^1.0`. When core hits
  2.0, every plugin coordinates a 2.0 release together.
- Plugin minor/patch versions are independent. Bug fixes,
  improved coverage, new attribute support all ship as
  plugin patch / minor releases without touching core.

This is the same policy Symfony uses for its bundles relative
to the core kernel.

## Repository structure

```
davidwyly/
├── rxn                                ← core framework
├── rxn-orm                            ← already exists; query
│                                        builder + ActiveRecord
├── rxn-client-typescript              ← planned, first plugin
├── rxn-client-python                  ← planned
├── rxn-client-go                      ← planned
├── rxn-client-rust                    ← planned
└── rxn-client-java                    ← planned
```

The framework's `composer.json` lists each first-party plugin
under `suggest`:

```json
"suggest": {
    "davidwyly/rxn-client-typescript": "^X.0 — generates typed TypeScript clients with parity-tested validation against the PHP reference.",
    "davidwyly/rxn-client-python":     "^X.0 — same, in Python with pydantic models + httpx.",
    ...
}
```

Each plugin's README links back to this doc for the contract.

## First-party plugins

| Plugin | Status | Target |
|---|---|---|
| `rxn-orm` | shipped | Query builder + ActiveRecord |
| `rxn-client-typescript` | planned | TS types + fetch wrapper + validator |
| `rxn-client-python` | planned | pydantic models + httpx + validator |
| `rxn-client-go` | planned | Go structs + net/http + validator |
| `rxn-client-rust` | planned | serde types + reqwest + validator |
| `rxn-client-java` | planned | POJOs + HttpClient + validator |

The `JsValidatorEmitter` and `ParityHarness` introduced in PR
#14 are the prototype that shows the pattern works. The TypeScript
plugin is the first extraction — it takes the validator emitter
to production shape, adds typed-client generation around it, and
moves into its own repository under the conventions above.

## What plugins promise — and what they don't

**Plugins promise:**

- Their generated output passes the `ParityHarness` at zero
  disagreements over the harness's input distribution.
- Coverage is documented per-attribute. Unsupported attributes
  cause the generator to throw, never to emit silently
  divergent code.
- The generated source is dependency-free in its target language
  (vanilla ES modules, plain Python without third-party
  validation libraries, Go with stdlib only, etc.) unless the
  plugin's README explicitly calls out a runtime dep.

**Plugins do NOT promise:**

- Bit-for-bit message text agreement. The harness compares the
  *set of failing fields*, not the rendered messages. PHP and
  the target language can have different message wording for
  the same failure (typically they don't, but they're allowed
  to).
- Performance parity. The PHP server-side validator is
  schema-compiled; the target-language validator is whatever the
  generator emits. Optimisation work happens per-plugin, on its
  own cadence.
- Coverage of the entire DTO attribute set. Each plugin
  documents its supported subset; attributes outside the subset
  cause the generator to refuse rather than emit.

## How to build a plugin

The shape is straightforward enough that a new plugin is a few
hours of focused work given the harness exists:

1. **Fork the template.** A `davidwyly/rxn-plugin-template`
   repository (TODO) contains the skeleton: `composer.json`,
   directory structure, a stub `Generator` class, a stub
   parity test, `phpunit.xml`, README, and CI config.

2. **Implement the generator.** Read the DTO via
   `\ReflectionClass`, walk properties + attributes, emit
   target-language source. The PHP `JsValidatorEmitter` (in core
   as of PR #14) is the reference implementation — every plugin
   follows the same pattern in a different output language.

3. **Wire up the parity test.** Use `ParityHarness::run()` with
   your generator + a `language` slug. The harness handles input
   generation and target-runtime invocation; you provide a hook
   that knows how to invoke the target runtime
   (`node validator.mjs`, `python validator.py`, `go run
   validator.go`, etc.).

4. **Iterate until zero.** First parity test runs typically
   produce > 0 disagreements. Each disagreement is a divergence
   in cast / coercion / regex behaviour between PHP and the
   target. Fix or document. Ship when zero holds across multiple
   harness runs.

5. **Tag a release.** Initial release is `0.1.0`. Major version
   bumps to `1.0.0` when core does.

## What this changes about the framework's positioning

Before: **Rxn is a small, opinionated PHP API framework.**

After plugins ship: **Rxn is a JSON contract authority with
parity-tested adapters in N languages.**

That's a different category of product. The PHP framework is
still the reference implementation, but the surface that matters
to users in a polyglot shop is the *contract* + the *adapters*
+ the *harness that proves the adapters are equivalent*. The
plugin pattern isn't code organization — it's the unit of trust
extension.

The harness is small enough to audit. The plugin contract is
strict enough to enforce. Every adapter that ships passes the
same test. Users don't have to trust each generator's
correctness independently; they have to trust the harness, and
the harness applies uniformly.

## Open questions

- **Should there be a `bin/rxn plugins` discovery command** that
  lists installed first-party plugins and their parity status?
  Probably yes — turns plugin presence into observable framework
  state. Defer until at least one plugin is shipped.

- **Should the harness extension to non-validator plugins** (e.g.
  a future `rxn-orm-mongodb` plugin that doesn't generate code
  but adapts a DB driver) work the same way? Probably not — the
  contract there is "implements the ORM's interface," not
  "agrees with PHP on inputs." The cross-language family has
  the parity contract; other plugin families will define their
  own contracts.

- **What about community plugins?** They can exist; the framework
  won't ban them. But the "Rxn parity-tested" claim only extends
  to first-party plugins under the davidwyly org, because
  community plugins can't be required to run the harness.
  Community plugins are documented as `community/` separately.

## Why this is documented now

The cross-language validator experiment (PR #14) succeeded — 0
disagreements over 10K random inputs across the in-scope
attribute matrix. That result alone is interesting; it becomes
*architecturally* interesting when it generalises to N target
languages.

This document captures the architectural decision so that when
the first plugin is extracted (`rxn-client-typescript`, the
natural follow-up to PR #14), the conventions don't have to be
re-debated. The pattern is fixed; new plugins follow the
template.

If even three of the planned plugins ship, Rxn becomes the
framework where **"declare your DTOs once, validate the same way
in your browser, your Python service, and your Go gateway,
provably"** is a true product claim, not a marketing line. The
harness is what makes it true.
