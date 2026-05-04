<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Routing;

use Rxn\Framework\Http\Attribute\Route;
use Rxn\Framework\Http\Attribute\Version;

/**
 * Compile-time route conflict detector. Scans `#[Route]` attributes
 * across a list of controllers, runs a pairwise overlap check, and
 * returns the conflicts. CI-only — runtime never calls this.
 *
 * What counts as a conflict: two routes whose method sets intersect
 * AND whose patterns share at least one URL that both could match.
 * Examples:
 *
 *   GET /users/{id:int}        ←┐
 *   GET /users/{name:any}      ←┘  any matches digits-only — conflict
 *
 *   GET /products/{id:int}     ←┐
 *   GET /products/{slug:slug}  ←┘  slug accepts digits — conflict
 *
 *   GET /posts/{id:int}        ←┐
 *   GET /posts/{name:alpha}    ←┘  digits ∩ letters = ∅ — clean
 *
 *   GET /users/me              ←┐
 *   GET /users/{id:int}        ←┘  "me" doesn't match \d+ — clean
 *
 *   GET /users/me              ←┐
 *   GET /users/{name:any}      ←┘  "me" matches any — conflict
 *
 * The character-set matrix below is the heart of the algorithm.
 * For pattern types Rxn ships, intersection emptiness is decidable
 * by inspection; we encode it as a static table.
 *
 * **Custom or overridden constraint types.** `Router::constraint()`
 * lets apps register new types and override the built-ins. The
 * detector accepts an optional `$constraints` map (same shape as
 * `Router`'s) so apps that customise their constraint set can pass
 * it in. The static matrix only applies when BOTH types in a pair
 * are still bound to their default regex — if either side has been
 * overridden or is custom, the detector falls back to "conservative
 * overlap" (treat as ambiguous). Flag-on-doubt is the right posture
 * for a CI gate; false positives are preferable to false negatives.
 *
 * **Unknown constraint types.** A route like `{id:nonsense}` would
 * make the runtime `Router::compile()` throw at registration. The
 * detector surfaces the same diagnostic at CI time as an
 * `InvalidRoute` finding, so the gate matches runtime semantics.
 */
final class ConflictDetector
{
    /**
     * Default constraint regexes Rxn's `Router` ships with. Mirrors
     * `Router::$constraints` exactly — the matrix below is derived
     * from these specific patterns; any divergence between this
     * list and Router's would silently desync the CI gate.
     *
     * @var array<string, string>
     */
    public const DEFAULT_CONSTRAINTS = [
        'int'   => '\d+',
        'slug'  => '[a-z0-9-]+',
        'alpha' => '[a-zA-Z]+',
        'uuid'  => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
        'any'   => '[^/]+',
    ];

    /**
     * Compatibility matrix: do these two constraint types accept at
     * least one common string? Symmetric. Built from the default
     * regexes above — see the comment in TYPE_REGEX for the
     * derivation.
     *
     * Used ONLY when both types in a pair are still bound to their
     * default regex; overridden built-ins or custom types fall back
     * to conservative overlap.
     *
     * @var array<string, array<string, bool>>
     */
    private const DEFAULT_OVERLAPS = [
        'int'   => ['int' => true,  'slug' => true,  'alpha' => false, 'uuid' => false, 'any' => true],
        'slug'  => ['int' => true,  'slug' => true,  'alpha' => true,  'uuid' => true,  'any' => true],
        'alpha' => ['int' => false, 'slug' => true,  'alpha' => true,  'uuid' => false, 'any' => true],
        'uuid'  => ['int' => false, 'slug' => true,  'alpha' => false, 'uuid' => true,  'any' => true],
        'any'   => ['int' => true,  'slug' => true,  'alpha' => true,  'uuid' => true,  'any' => true],
    ];

    /** @var array<string, string> */
    private array $constraints;

    /**
     * @param array<string, string>|null $constraints map of type
     *  name → regex body (no anchors), same shape as `Router`'s
     *  `$constraints` array. Defaults to Rxn's standard set. Pass
     *  the live router's constraint table for apps that customise
     *  via `Router::constraint()`.
     */
    public function __construct(?array $constraints = null)
    {
        $this->constraints = $constraints ?? self::DEFAULT_CONSTRAINTS;
    }

    /**
     * Reflect every `#[Route]` attribute across the given controller
     * classes into a flat `RouteEntry` list. Methods that aren't
     * declared on the class itself (inherited from a base) are
     * skipped — same rule the runtime Scanner applies, so detection
     * scope matches registration scope.
     *
     * `#[Version]` is honoured here for the same reason: the
     * detector's notion of "the route's path" has to match what
     * the Scanner actually registers. Without this, two methods
     * with identical `#[Route]` patterns but different
     * `#[Version('v1')]` / `#[Version('v2')]` would still be
     * flagged as conflicting — even though they end up at distinct
     * `/v1/...` and `/v2/...` URLs at runtime.
     *
     * Method-level `#[Version]` overrides class-level (matches
     * Scanner's resolution rule).
     *
     * @param list<class-string> $controllers
     * @return list<RouteEntry>
     */
    public function collect(array $controllers): array
    {
        $entries = [];
        foreach ($controllers as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $ref          = new \ReflectionClass($class);
            $classVersion = self::firstVersion($ref->getAttributes(Version::class));
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $ref->getName()) {
                    continue;
                }
                $methodVersion    = self::firstVersion($method->getAttributes(Version::class));
                $effectiveVersion = $methodVersion ?? $classVersion;
                foreach ($method->getAttributes(Route::class) as $attr) {
                    /** @var Route $route */
                    $route   = $attr->newInstance();
                    $pattern = $effectiveVersion === null
                        ? $route->path
                        : $effectiveVersion->applyTo($route->path);
                    $entries[] = new RouteEntry(
                        method:     strtoupper($route->method),
                        pattern:    self::normalisePattern($pattern),
                        class:      $class,
                        methodName: $method->getName(),
                        file:       (string) $method->getFileName(),
                        line:       $method->getStartLine() ?: 0,
                    );
                }
            }
        }
        return $entries;
    }

    /**
     * Pick the first `#[Version]` from a reflection-attribute list
     * (or null when none). `#[Version]` isn't `IS_REPEATABLE`, so
     * there's at most one — but reflection still hands us a list.
     *
     * @param list<\ReflectionAttribute<Version>> $attrs
     */
    private static function firstVersion(array $attrs): ?Version
    {
        if ($attrs === []) {
            return null;
        }
        /** @var Version $v */
        $v = $attrs[0]->newInstance();
        return $v;
    }

    /**
     * Find routes whose patterns reference unknown constraint types
     * or contain malformed placeholders. The runtime `Router::compile()`
     * would throw on these; the detector reports them as findings so
     * the developer sees them alongside any conflicts.
     *
     * @param list<RouteEntry> $entries
     * @return list<InvalidRoute>
     */
    public function validate(array $entries): array
    {
        $invalid = [];
        foreach ($entries as $entry) {
            $reason = $this->validateOne($entry->pattern);
            if ($reason !== null) {
                $invalid[] = new InvalidRoute($entry, $reason);
            }
        }
        return $invalid;
    }

    /**
     * Pairwise overlap check across the entry list. Each conflict
     * is reported once — the inner loop starts at `$i + 1`. Routes
     * that fail validation are skipped from conflict checking
     * because their patterns aren't well-defined.
     *
     * @param list<RouteEntry> $entries
     * @return list<Conflict>
     */
    public function detect(array $entries): array
    {
        // Filter out invalid routes — we can't reason about overlap
        // for patterns that wouldn't even register at runtime.
        $valid = array_values(array_filter(
            $entries,
            fn (RouteEntry $e): bool => $this->validateOne($e->pattern) === null,
        ));

        $conflicts = [];
        $count = count($valid);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $reason = $this->conflictReason($valid[$i], $valid[$j]);
                if ($reason !== null) {
                    $conflicts[] = new Conflict($valid[$i], $valid[$j], $reason);
                }
            }
        }
        return $conflicts;
    }

    /**
     * Convenience: collect + validate + detect in one call.
     *
     * @param list<class-string> $controllers
     */
    public function check(array $controllers): DetectorResult
    {
        $entries = $this->collect($controllers);
        return new DetectorResult(
            invalid:   $this->validate($entries),
            conflicts: $this->detect($entries),
        );
    }

    private function validateOne(string $pattern): ?string
    {
        if ($pattern === '/' || $pattern === '') {
            return null;
        }
        foreach (explode('/', ltrim($pattern, '/')) as $segment) {
            if (!self::isDynamic($segment)) {
                continue;
            }
            // Same grammar Router::compile() enforces.
            $inner = substr($segment, 1, -1);
            if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(?::([a-zA-Z_][a-zA-Z0-9_]*))?$/', $inner)) {
                return "malformed placeholder '$segment'";
            }
            if (str_contains($inner, ':')) {
                $type = substr($inner, strpos($inner, ':') + 1);
                if (!isset($this->constraints[$type])) {
                    return "unknown constraint type '$type'";
                }
            }
        }
        return null;
    }

    private function conflictReason(RouteEntry $a, RouteEntry $b): ?string
    {
        if ($a->method !== $b->method) {
            return null;
        }

        $segA = self::splitSegments($a->pattern);
        $segB = self::splitSegments($b->pattern);
        if (count($segA) !== count($segB)) {
            return null;
        }

        for ($i = 0; $i < count($segA); $i++) {
            if (!$this->segmentsOverlap($segA[$i], $segB[$i])) {
                return null;
            }
        }
        return 'every segment overlaps';
    }

    /**
     * @return list<string>
     */
    private static function splitSegments(string $pattern): array
    {
        $pattern = self::normalisePattern($pattern);
        if ($pattern === '/' || $pattern === '') {
            return [''];
        }
        return explode('/', ltrim($pattern, '/'));
    }

    private function segmentsOverlap(string $segA, string $segB): bool
    {
        $isDynA = self::isDynamic($segA);
        $isDynB = self::isDynamic($segB);

        if (!$isDynA && !$isDynB) {
            return $segA === $segB;
        }

        if ($isDynA && $isDynB) {
            $typeA = self::dynamicType($segA);
            $typeB = self::dynamicType($segB);
            return $this->typesOverlap($typeA, $typeB);
        }

        [$literal, $dyn] = $isDynA ? [$segB, $segA] : [$segA, $segB];
        $type = self::dynamicType($dyn);
        return $this->literalMatchesType($literal, $type);
    }

    private static function isDynamic(string $segment): bool
    {
        return str_starts_with($segment, '{') && str_ends_with($segment, '}');
    }

    private static function dynamicType(string $segment): string
    {
        $inner = substr($segment, 1, -1);
        $colon = strpos($inner, ':');
        if ($colon === false) {
            return 'any';
        }
        return substr($inner, $colon + 1);
    }

    /**
     * Use the static matrix only when both types are present in
     * the default constraint set AND bound to their default regex.
     * Any divergence — overridden built-in OR custom type — falls
     * back to conservative overlap so the gate doesn't silently
     * trust stale matrix data.
     */
    private function typesOverlap(string $typeA, string $typeB): bool
    {
        if (!$this->isStandardBinding($typeA) || !$this->isStandardBinding($typeB)) {
            return true;
        }
        return self::DEFAULT_OVERLAPS[$typeA][$typeB] ?? true;
    }

    private function isStandardBinding(string $type): bool
    {
        $defaultRegex = self::DEFAULT_CONSTRAINTS[$type] ?? null;
        if ($defaultRegex === null) {
            return false;
        }
        return ($this->constraints[$type] ?? null) === $defaultRegex;
    }

    /**
     * Anchor the constraint regex and test the literal against it.
     * Uses the LIVE constraint table (this->constraints), so an
     * overridden built-in is tested with the override's regex —
     * not the default. Custom types resolve here too.
     */
    private function literalMatchesType(string $literal, string $type): bool
    {
        $regex = $this->constraints[$type] ?? null;
        if ($regex === null) {
            // Truly unknown — validate() should have surfaced this
            // already, but be conservative as a safety net.
            return true;
        }
        return preg_match('#^' . $regex . '$#', $literal) === 1;
    }

    private static function normalisePattern(string $pattern): string
    {
        $pattern = '/' . ltrim($pattern, '/');
        if ($pattern !== '/') {
            $pattern = rtrim($pattern, '/');
        }
        return $pattern;
    }
}
