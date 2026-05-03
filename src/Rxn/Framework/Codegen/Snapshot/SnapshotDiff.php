<?php declare(strict_types=1);

namespace Rxn\Framework\Codegen\Snapshot;

/**
 * The result of comparing two OpenAPI snapshots. Two buckets:
 * `breaking` (operation/parameter/property removals, type changes,
 * required-toggles to required) and `additive` (new operations,
 * new optional fields).
 *
 * The classifier is conservative: when context can't disambiguate
 * (e.g. removing a property from a schema referenced by both a
 * request body AND a response — the former is benign for clients,
 * the latter is breaking), the change is marked breaking. Caller
 * policy then decides whether to gate or report.
 */
final class SnapshotDiff
{
    /** @var list<SnapshotChange> */
    private array $changes = [];

    public function add(SnapshotChange $change): void
    {
        $this->changes[] = $change;
    }

    public function isClean(): bool
    {
        return $this->changes === [];
    }

    public function hasBreaking(): bool
    {
        foreach ($this->changes as $c) {
            if ($c->kind === SnapshotChange::BREAKING) {
                return true;
            }
        }
        return false;
    }

    /** @return list<SnapshotChange> */
    public function breaking(): array
    {
        return array_values(array_filter(
            $this->changes,
            static fn (SnapshotChange $c): bool => $c->kind === SnapshotChange::BREAKING,
        ));
    }

    /** @return list<SnapshotChange> */
    public function additive(): array
    {
        return array_values(array_filter(
            $this->changes,
            static fn (SnapshotChange $c): bool => $c->kind === SnapshotChange::ADDITIVE,
        ));
    }

    /** @return list<SnapshotChange> */
    public function all(): array
    {
        return $this->changes;
    }

    public function describe(): string
    {
        if ($this->changes === []) {
            return "No drift.\n";
        }
        $out = '';
        $breaking = $this->breaking();
        if ($breaking !== []) {
            $out .= 'Breaking changes (' . count($breaking) . "):\n";
            foreach ($breaking as $c) {
                $out .= "  [breaking] $c->path — $c->message\n";
            }
        }
        $additive = $this->additive();
        if ($additive !== []) {
            $out .= 'Additive changes (' . count($additive) . "):\n";
            foreach ($additive as $c) {
                $out .= "  [additive] $c->path — $c->message\n";
            }
        }
        return $out;
    }
}
