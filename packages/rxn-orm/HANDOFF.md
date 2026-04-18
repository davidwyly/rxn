# Handoff: Import Rxn ORM into `davidwyly/rxn-orm`

You (sibling Claude instance) are working in a clone of the empty
`git@github.com:davidwyly/rxn-orm.git`. Your job is to populate it
from the extraction tree already staged in the sibling repository
`davidwyly/rxn` under `packages/rxn-orm/`.

Keep commits clean, tests green, and when you're done, the owner
will ping the original Claude instance on `rxn` to validate and
clean up.

---

## What you're doing, in one line

Copy every file from `davidwyly/rxn`'s `packages/rxn-orm/` directory
into the root of this repo, verify the tests run green standalone,
commit, and tag `v0.1.0`.

## Prerequisites

- Git remote: `origin = git@github.com:davidwyly/rxn-orm.git`
- PHP 8.1+ installed
- Composer 2.x installed

## Step-by-step

### 1. Get the source tree

Two options. Pick one:

**a) Clone rxn alongside and copy:**
```bash
git clone https://github.com/davidwyly/rxn.git ../rxn
cp -r ../rxn/packages/rxn-orm/. ./
```

**b) Curl the raw files.** Tedious; prefer (a).

After the copy your tree must look exactly like this:

```
./
├── HANDOFF.md            (this file — delete after you're done)
├── README.md
├── composer.json
├── phpunit.xml
├── src/
│   ├── Builder.php
│   ├── DataModel.php
│   └── Builder/
│       ├── Buildable.php
│       ├── Delete.php
│       ├── HasWhere.php
│       ├── Insert.php
│       ├── Query.php
│       ├── QueryParser.php
│       ├── Raw.php
│       ├── Update.php
│       └── Query/
│           ├── From.php
│           ├── Join.php
│           └── Select.php
└── tests/
    └── Builder/
        ├── DeleteTest.php
        ├── InsertTest.php
        ├── RawTest.php
        ├── SubqueryTest.php
        ├── UpdateTest.php
        ├── UpsertReturningTest.php
        └── Query/
            ├── ClauseTest.php
            ├── JoinTest.php
            ├── SelectTest.php
            └── WhereTest.php
```

### 2. Install, lint, test

```bash
composer install
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax errors'
vendor/bin/phpunit
```

Expected outcome — **all three must hold** before you commit:

- `composer install` completes without errors; no entry under
  `require` or `require-dev` outside these five:
  `php`, `ext-pdo`, `phpunit/phpunit`.
- `php -l` is silent for every file (the `grep -v` returns nothing).
- `vendor/bin/phpunit` reports **68 tests, 132 assertions, OK** with
  zero failures / warnings / deprecations / incomplete. If the count
  differs, do not proceed — something was missed in the copy.

### 3. Verify no Rxn\Framework leakage

```bash
grep -rn 'Rxn\\Framework\|use Rxn\\Framework' src tests || echo "clean"
```

Must print `clean`. If anything matches, the extraction is
incomplete — stop and report.

### 4. Delete this handoff, then commit

```bash
rm HANDOFF.md
git add .
git commit -m "initial import from rxn"
```

### 5. Tag and push

```bash
git push origin main
git tag v0.1.0
git push origin v0.1.0
```

### 6. Report back

Leave a short message summarizing what you did, what phpunit
reported, and the v0.1.0 commit SHA. The owner will then trigger
the sibling Claude on `rxn` to do the cleanup commit over there.

---

## Things not to do

- **Don't add new code.** This is a straight import. New features,
  refactors, and renames are the original Claude's problem on `rxn`.
- **Don't change the namespace.** All classes stay under `Rxn\Orm\`.
  Downstream `rxn` already imports them at those fully-qualified
  names.
- **Don't add a CI workflow yet.** It's nice to have, but adding
  one here risks green-vs-red mismatches against what the owner
  expects. If the owner asks after v0.1.0 lands, add one then.
- **Don't publish to Packagist from inside the agent.** The owner
  will handle Packagist manually or add a VCS entry to rxn's
  `composer.json`.
- **Don't rewrite git history** of the rxn-orm repo. Leave a clean
  linear log of "initial import from rxn" for v0.1.0.

## If something breaks

- `phpunit` fails: the likely cause is a missed file during copy.
  Diff your `src/` and `tests/` trees against the expected layout
  above. Every file listed must be present.
- `composer install` fails on the PHP version: make sure you're on
  PHP 8.1 or later.
- You find a bug in the code itself while running the tests: stop.
  That's a bug the original Claude needs to see on `rxn`. File a
  note in your report; don't patch here.

## Validation the owner will run on your work

When you report back, the owner's other Claude will pull your tag
and check:

1. `vendor/bin/phpunit` reports 68 / 132 / 0 failures locally.
2. `grep -rn 'Rxn\\Framework' src tests` is empty.
3. The file tree matches the expected layout exactly (no extras).
4. `composer validate --strict` is clean.
5. Tag `v0.1.0` points at a commit whose tree matches the copy.

Only after those five pass does `rxn` proceed to add
`davidwyly/rxn-orm` as a dependency and delete its local
`src/Rxn/Orm/` tree.
