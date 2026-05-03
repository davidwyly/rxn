<?php declare(strict_types=1);

namespace Rxn\Framework\Codegen\Snapshot;

/**
 * Snapshot machinery for the OpenAPI doc Rxn already emits. Two
 * jobs: produce a stable byte-for-byte serialisation (so a JSON
 * file checked into the repo can be diffed against the current
 * generator output without spurious whitespace / key-order noise),
 * and classify the structural difference between two specs into
 * breaking vs additive changes.
 *
 * Used by `bin/rxn openapi:check` to gate API drift in CI:
 *
 *   $current  = (new Generator(...))->generate();
 *   $snapshot = json_decode(file_get_contents('openapi.snapshot.json'), true);
 *   $diff     = OpenApiSnapshot::diff($snapshot, $current);
 *   if ($diff->hasBreaking()) { exit(2); }
 *
 * The horizons.md doc (theme 1.3) frames this as the cheapest
 * possible governance layer — closer to a linter than a feature.
 * The implementation honours that: ~250 LOC, no dependencies, no
 * runtime cost (CI-only).
 *
 * The diff is intentionally coarse. It does NOT try to resolve
 * schema `$ref`s recursively or distinguish request-side schemas
 * from response-side schemas — both are real concerns but the
 * juice isn't worth the squeeze for v1. The conservative posture
 * (mark ambiguous removals as breaking) means the gate fails
 * loudly rather than silently passing real regressions.
 */
final class OpenApiSnapshot
{
    /**
     * Produce a stable JSON serialisation suitable for committing
     * as a snapshot artifact. Keys sorted recursively, pretty-
     * printed with two-space indent, slashes and unicode left
     * unescaped, trailing newline. Same bytes on every PHP
     * version + every machine.
     *
     * @param array<string, mixed> $spec
     */
    public static function serialise(array $spec): string
    {
        $sorted = self::recursiveKsort($spec);
        $json   = json_encode(
            $sorted,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        return $json . "\n";
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<int|string, mixed>
     */
    private static function recursiveKsort(array $value): array
    {
        if (array_is_list($value)) {
            // Lists keep order — reordering a `paths` array (which is
            // a map, not a list) would lose information; reordering a
            // `parameters` array (which is a list of objects) would
            // introduce bogus drift. Lists pass through, maps sort.
            return array_map(
                static fn ($v) => is_array($v) ? self::recursiveKsort($v) : $v,
                $value,
            );
        }
        ksort($value);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::recursiveKsort($v);
            }
        }
        return $value;
    }

    /**
     * Compare two specs and bucket the differences into breaking
     * vs additive changes. The returned `SnapshotDiff` is empty
     * when the specs are identical post-normalisation.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    public static function diff(array $old, array $new): SnapshotDiff
    {
        $diff = new SnapshotDiff();

        $oldPaths = is_array($old['paths'] ?? null) ? $old['paths'] : [];
        $newPaths = is_array($new['paths'] ?? null) ? $new['paths'] : [];
        self::diffPaths($oldPaths, $newPaths, $diff);

        $oldSchemas = is_array($old['components']['schemas'] ?? null) ? $old['components']['schemas'] : [];
        $newSchemas = is_array($new['components']['schemas'] ?? null) ? $new['components']['schemas'] : [];
        self::diffSchemas($oldSchemas, $newSchemas, $diff);

        return $diff;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private static function diffPaths(array $old, array $new, SnapshotDiff $diff): void
    {
        $allPaths = array_unique(array_merge(array_keys($old), array_keys($new)));
        sort($allPaths);

        foreach ($allPaths as $path) {
            $oldOps = is_array($old[$path] ?? null) ? $old[$path] : [];
            $newOps = is_array($new[$path] ?? null) ? $new[$path] : [];

            if ($oldOps === [] && $newOps !== []) {
                $diff->add(new SnapshotChange(
                    SnapshotChange::ADDITIVE,
                    "paths.$path",
                    'path added',
                ));
                continue;
            }
            if ($oldOps !== [] && $newOps === []) {
                $diff->add(new SnapshotChange(
                    SnapshotChange::BREAKING,
                    "paths.$path",
                    'path removed',
                ));
                continue;
            }

            self::diffOperations($path, $oldOps, $newOps, $diff);
        }
    }

    /**
     * @param array<string, mixed> $oldOps
     * @param array<string, mixed> $newOps
     */
    private static function diffOperations(string $path, array $oldOps, array $newOps, SnapshotDiff $diff): void
    {
        $methods = array_unique(array_merge(array_keys($oldOps), array_keys($newOps)));
        sort($methods);

        foreach ($methods as $method) {
            // Skip non-operation siblings ("parameters", "summary",
            // "description", "$ref" can live at the path level too).
            if (!in_array(strtolower($method), ['get', 'put', 'post', 'delete', 'patch', 'head', 'options', 'trace'], true)) {
                continue;
            }

            $hasOld = isset($oldOps[$method]);
            $hasNew = isset($newOps[$method]);

            if (!$hasOld && $hasNew) {
                $diff->add(new SnapshotChange(
                    SnapshotChange::ADDITIVE,
                    "paths.$path.$method",
                    'operation added',
                ));
                continue;
            }
            if ($hasOld && !$hasNew) {
                $diff->add(new SnapshotChange(
                    SnapshotChange::BREAKING,
                    "paths.$path.$method",
                    'operation removed',
                ));
                continue;
            }

            self::diffParameters($path, $method, $oldOps[$method], $newOps[$method], $diff);
        }
    }

    /**
     * @param array<string, mixed> $oldOp
     * @param array<string, mixed> $newOp
     */
    private static function diffParameters(string $path, string $method, array $oldOp, array $newOp, SnapshotDiff $diff): void
    {
        $oldParams = self::indexByName($oldOp['parameters'] ?? []);
        $newParams = self::indexByName($newOp['parameters'] ?? []);

        $names = array_unique(array_merge(array_keys($oldParams), array_keys($newParams)));
        sort($names);

        foreach ($names as $name) {
            $hasOld = isset($oldParams[$name]);
            $hasNew = isset($newParams[$name]);

            if (!$hasOld && $hasNew) {
                $required = (bool) ($newParams[$name]['required'] ?? false);
                $diff->add(new SnapshotChange(
                    $required ? SnapshotChange::BREAKING : SnapshotChange::ADDITIVE,
                    "paths.$path.$method.parameters.$name",
                    $required ? 'required parameter added' : 'optional parameter added',
                ));
                continue;
            }
            if ($hasOld && !$hasNew) {
                // Removing any parameter — even an optional one — is
                // a contract change for clients that were sending it.
                $diff->add(new SnapshotChange(
                    SnapshotChange::BREAKING,
                    "paths.$path.$method.parameters.$name",
                    'parameter removed',
                ));
                continue;
            }

            $oldRequired = (bool) ($oldParams[$name]['required'] ?? false);
            $newRequired = (bool) ($newParams[$name]['required'] ?? false);
            if (!$oldRequired && $newRequired) {
                $diff->add(new SnapshotChange(
                    SnapshotChange::BREAKING,
                    "paths.$path.$method.parameters.$name",
                    'parameter became required',
                ));
            }

            $oldType = $oldParams[$name]['schema']['type'] ?? null;
            $newType = $newParams[$name]['schema']['type'] ?? null;
            if ($oldType !== null && $newType !== null && $oldType !== $newType) {
                $diff->add(new SnapshotChange(
                    SnapshotChange::BREAKING,
                    "paths.$path.$method.parameters.$name",
                    "type changed from $oldType to $newType",
                ));
            }
        }
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, array<string, mixed>>
     */
    private static function indexByName(array $params): array
    {
        $out = [];
        foreach ($params as $p) {
            if (!is_array($p)) {
                continue;
            }
            $name = $p['name'] ?? null;
            if (!is_string($name)) {
                continue;
            }
            $out[$name] = $p;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private static function diffSchemas(array $old, array $new, SnapshotDiff $diff): void
    {
        $names = array_unique(array_merge(array_keys($old), array_keys($new)));
        sort($names);

        foreach ($names as $name) {
            $hasOld = isset($old[$name]);
            $hasNew = isset($new[$name]);

            if (!$hasOld && $hasNew) {
                $diff->add(new SnapshotChange(
                    SnapshotChange::ADDITIVE,
                    "components.schemas.$name",
                    'schema added',
                ));
                continue;
            }
            if ($hasOld && !$hasNew) {
                $diff->add(new SnapshotChange(
                    SnapshotChange::BREAKING,
                    "components.schemas.$name",
                    'schema removed',
                ));
                continue;
            }

            self::diffSchema($name, $old[$name], $new[$name], $diff);
        }
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private static function diffSchema(string $schemaName, array $old, array $new, SnapshotDiff $diff): void
    {
        $oldType = $old['type'] ?? null;
        $newType = $new['type'] ?? null;
        if ($oldType !== null && $newType !== null && $oldType !== $newType) {
            $diff->add(new SnapshotChange(
                SnapshotChange::BREAKING,
                "components.schemas.$schemaName",
                "schema type changed from $oldType to $newType",
            ));
        }

        $oldProps = is_array($old['properties'] ?? null) ? $old['properties'] : [];
        $newProps = is_array($new['properties'] ?? null) ? $new['properties'] : [];
        $oldRequired = is_array($old['required'] ?? null) ? $old['required'] : [];
        $newRequired = is_array($new['required'] ?? null) ? $new['required'] : [];

        $propNames = array_unique(array_merge(array_keys($oldProps), array_keys($newProps)));
        sort($propNames);

        foreach ($propNames as $propName) {
            $hasOld = isset($oldProps[$propName]);
            $hasNew = isset($newProps[$propName]);
            $wasRequired = in_array($propName, $oldRequired, true);
            $isRequired  = in_array($propName, $newRequired, true);

            if (!$hasOld && $hasNew) {
                $diff->add(new SnapshotChange(
                    $isRequired ? SnapshotChange::BREAKING : SnapshotChange::ADDITIVE,
                    "components.schemas.$schemaName.properties.$propName",
                    $isRequired ? 'required property added' : 'optional property added',
                ));
                continue;
            }
            if ($hasOld && !$hasNew) {
                // Conservative: any property removal is breaking.
                // A response-side removal genuinely is; a request-side
                // removal is benign for clients but the snapshot
                // doesn't track ref direction, so we don't try to
                // distinguish.
                $diff->add(new SnapshotChange(
                    SnapshotChange::BREAKING,
                    "components.schemas.$schemaName.properties.$propName",
                    'property removed',
                ));
                continue;
            }

            if (!$wasRequired && $isRequired) {
                $diff->add(new SnapshotChange(
                    SnapshotChange::BREAKING,
                    "components.schemas.$schemaName.required",
                    "property '$propName' became required",
                ));
            }

            $oldPropType = $oldProps[$propName]['type'] ?? null;
            $newPropType = $newProps[$propName]['type'] ?? null;
            if ($oldPropType !== null && $newPropType !== null && $oldPropType !== $newPropType) {
                $diff->add(new SnapshotChange(
                    SnapshotChange::BREAKING,
                    "components.schemas.$schemaName.properties.$propName",
                    "type changed from $oldPropType to $newPropType",
                ));
            }
        }
    }
}
