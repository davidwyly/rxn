<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Routing;

use Rxn\Framework\Http\Attribute\Route;

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
 * by inspection; we encode it as a static table. Custom constraint
 * types (added via `Router::constraint()`) are conservatively
 * treated as overlapping with everything — flag-on-doubt is the
 * right posture for a CI gate.
 */
final class ConflictDetector
{
    /**
     * Compatibility matrix: do these two constraint types accept at
     * least one common string? Symmetric. Built from the regexes:
     *
     *   int   = \d+                             digits
     *   slug  = [a-z0-9-]+                      lowercase + digits + dash
     *   alpha = [a-zA-Z]+                       letters (any case)
     *   uuid  = [0-9a-f]{8}-...                 hex digits + dashes, fixed length
     *   any   = [^/]+                           anything except slash
     *
     * A custom type or unknown type defaults to overlapping (false
     * positive is preferable to false negative for a gate).
     *
     * @var array<string, array<string, bool>>
     */
    private const TYPE_OVERLAPS = [
        'int'   => ['int' => true,  'slug' => true,  'alpha' => false, 'uuid' => false, 'any' => true],
        'slug'  => ['int' => true,  'slug' => true,  'alpha' => true,  'uuid' => true,  'any' => true],
        'alpha' => ['int' => false, 'slug' => true,  'alpha' => true,  'uuid' => false, 'any' => true],
        'uuid'  => ['int' => false, 'slug' => true,  'alpha' => false, 'uuid' => true,  'any' => true],
        'any'   => ['int' => true,  'slug' => true,  'alpha' => true,  'uuid' => true,  'any' => true],
    ];

    /**
     * Regexes the standard constraint types resolve to, used for
     * the static-vs-dynamic case (does this literal segment match
     * that constraint?).
     *
     * @var array<string, string>
     */
    private const TYPE_REGEX = [
        'int'   => '/^\d+$/',
        'slug'  => '/^[a-z0-9-]+$/',
        'alpha' => '/^[a-zA-Z]+$/',
        'uuid'  => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
        'any'   => '/^[^\/]+$/',
    ];

    /**
     * Reflect every `#[Route]` attribute across the given controller
     * classes into a flat `RouteEntry` list. Methods that aren't
     * declared on the class itself (inherited from a base) are
     * skipped — same rule the runtime Scanner applies, so detection
     * scope matches registration scope.
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
            $ref = new \ReflectionClass($class);
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $ref->getName()) {
                    continue;
                }
                foreach ($method->getAttributes(Route::class) as $attr) {
                    /** @var Route $route */
                    $route = $attr->newInstance();
                    $entries[] = new RouteEntry(
                        method:     strtoupper($route->method),
                        pattern:    self::normalisePattern($route->path),
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
     * Pairwise overlap check across the entry list. Each conflict
     * is reported once — the inner loop starts at `$i + 1`.
     *
     * @param list<RouteEntry> $entries
     * @return list<Conflict>
     */
    public function detect(array $entries): array
    {
        $conflicts = [];
        $count = count($entries);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $reason = self::conflictReason($entries[$i], $entries[$j]);
                if ($reason !== null) {
                    $conflicts[] = new Conflict($entries[$i], $entries[$j], $reason);
                }
            }
        }
        return $conflicts;
    }

    /**
     * Convenience: collect + detect in one call.
     *
     * @param list<class-string> $controllers
     * @return list<Conflict>
     */
    public function check(array $controllers): array
    {
        return $this->detect($this->collect($controllers));
    }

    private static function conflictReason(RouteEntry $a, RouteEntry $b): ?string
    {
        if ($a->method !== $b->method) {
            // Different verbs on the same path are never ambiguous —
            // GET /users/{id} and POST /users/{id} are distinct
            // dispatch targets.
            return null;
        }

        $segA = self::splitSegments($a->pattern);
        $segB = self::splitSegments($b->pattern);
        if (count($segA) !== count($segB)) {
            // Different segment counts can never share a URL.
            return null;
        }

        for ($i = 0; $i < count($segA); $i++) {
            if (!self::segmentsOverlap($segA[$i], $segB[$i])) {
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
        // Normalise here so synthetic entries (tests, programmatic
        // RouteEntry construction) match the same shape `collect()`
        // produces from reflected attributes. Trailing slash is
        // stripped (matching the runtime Router); leading slash is
        // ensured.
        $pattern = self::normalisePattern($pattern);
        if ($pattern === '/' || $pattern === '') {
            return [''];
        }
        return explode('/', ltrim($pattern, '/'));
    }

    private static function segmentsOverlap(string $segA, string $segB): bool
    {
        $isDynA = self::isDynamic($segA);
        $isDynB = self::isDynamic($segB);

        if (!$isDynA && !$isDynB) {
            return $segA === $segB;
        }

        if ($isDynA && $isDynB) {
            $typeA = self::dynamicType($segA);
            $typeB = self::dynamicType($segB);
            return self::typesOverlap($typeA, $typeB);
        }

        // One literal, one dynamic — overlap iff the literal matches
        // the dynamic's constraint regex.
        [$literal, $dyn] = $isDynA ? [$segB, $segA] : [$segA, $segB];
        $type = self::dynamicType($dyn);
        return self::literalMatchesType($literal, $type);
    }

    private static function isDynamic(string $segment): bool
    {
        return str_starts_with($segment, '{') && str_ends_with($segment, '}');
    }

    /**
     * Extract the type from `{name}` (= `any`) or `{name:type}`.
     * Does not validate the placeholder grammar — the Router does
     * that at registration time, so by the time the detector runs,
     * malformed placeholders have already failed loudly.
     */
    private static function dynamicType(string $segment): string
    {
        $inner = substr($segment, 1, -1);
        $colon = strpos($inner, ':');
        if ($colon === false) {
            return 'any';
        }
        return substr($inner, $colon + 1);
    }

    private static function typesOverlap(string $typeA, string $typeB): bool
    {
        $entryA = self::TYPE_OVERLAPS[$typeA] ?? null;
        $entryB = self::TYPE_OVERLAPS[$typeB] ?? null;
        if ($entryA === null || $entryB === null) {
            // At least one is a custom / unknown type — conservatively
            // assume overlap. The CI gate's purpose is to fail loudly
            // on ambiguity, so unknowns count as ambiguous.
            return true;
        }
        return $entryA[$typeB] ?? true;
    }

    private static function literalMatchesType(string $literal, string $type): bool
    {
        $regex = self::TYPE_REGEX[$type] ?? null;
        if ($regex === null) {
            // Custom type — conservatively assume the literal matches.
            return true;
        }
        return preg_match($regex, $literal) === 1;
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
