<?php declare(strict_types=1);

namespace Rxn\Framework\Codegen\Snapshot;

/**
 * One classified change between two OpenAPI snapshots. Lives next
 * to `SnapshotDiff` — value-object only.
 *
 * `path` is a JSON-pointer-ish location string ("paths./v1/x.get",
 * "components.schemas.CreateProduct.required[]") so a reader can
 * navigate to the exact site of the change.
 *
 * Categorisation is intentionally coarse (BREAKING vs ADDITIVE) —
 * the snapshot contract is a CI gate, not a semver tool. The CLI
 * gates breaking changes behind `--allow-breaking` and lets the
 * additive bucket through silently or with a warning, depending
 * on caller policy.
 */
final class SnapshotChange
{
    public const BREAKING = 'breaking';
    public const ADDITIVE = 'additive';

    public function __construct(
        public readonly string $kind,
        public readonly string $path,
        public readonly string $message,
    ) {}
}
