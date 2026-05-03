<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Routing;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Routing\ConflictDetector;
use Rxn\Framework\Http\Routing\RouteEntry;

/**
 * Unit tests for the route conflict detector. Each test isolates
 * one rule from the constraint-overlap matrix or the static-vs-
 * dynamic case, so a regression is easy to localise.
 *
 * The fixtures (CleanController, ConflictController,
 * StaticVsDynamicController) cover the realistic shapes; the
 * synthetic-entry tests below cover the matrix corners that
 * fixtures would make tedious.
 */
final class ConflictDetectorTest extends TestCase
{
    public function testCleanControllerHasZeroConflicts(): void
    {
        $detector = new ConflictDetector();
        $result   = $detector->check([Fixture\CleanController::class]);
        $this->assertTrue(
            $result->isClean(),
            "CleanController must not produce findings:\n" . $result->describe()
        );
    }

    public function testIntVsSlugIsAConflict(): void
    {
        $detector = new ConflictDetector();
        $result   = $detector->check([Fixture\ConflictController::class]);
        $this->assertSame([], $result->invalid);
        $this->assertCount(1, $result->conflicts);
        $this->assertSame('GET', $result->conflicts[0]->a->method);
        $this->assertSame('GET', $result->conflicts[0]->b->method);
        // Both /items/{id:int} and /items/{slug:slug} accept "123".
        $patterns = [$result->conflicts[0]->a->pattern, $result->conflicts[0]->b->pattern];
        sort($patterns);
        $this->assertSame(['/items/{id:int}', '/items/{slug:slug}'], $patterns);
    }

    public function testLiteralMatchingDynamicIsAConflict(): void
    {
        $detector = new ConflictDetector();
        $result   = $detector->check([Fixture\StaticVsDynamicController::class]);
        $this->assertCount(1, $result->conflicts);
        $patterns = [$result->conflicts[0]->a->pattern, $result->conflicts[0]->b->pattern];
        sort($patterns);
        $this->assertSame(['/users/me', '/users/{name:any}'], $patterns);
    }

    public function testDifferentMethodsNeverConflict(): void
    {
        $entries = [
            self::entry('GET',  '/users/{id:int}'),
            self::entry('POST', '/users/{id:int}'),
            self::entry('PUT',  '/users/{id:int}'),
        ];
        $this->assertSame([], (new ConflictDetector())->detect($entries));
    }

    public function testDifferentSegmentCountsNeverConflict(): void
    {
        $entries = [
            self::entry('GET', '/users/{id:int}'),
            self::entry('GET', '/users/{id:int}/orders'),
        ];
        $this->assertSame([], (new ConflictDetector())->detect($entries));
    }

    public function testIntAndAlphaDoNotOverlap(): void
    {
        // \d+ ∩ [a-zA-Z]+ = ∅ — these constraints have disjoint
        // accepted languages, so the routes never collide on any
        // possible URL.
        $entries = [
            self::entry('GET', '/x/{id:int}'),
            self::entry('GET', '/x/{name:alpha}'),
        ];
        $this->assertSame([], (new ConflictDetector())->detect($entries));
    }

    public function testIntAndUuidDoNotOverlap(): void
    {
        // uuid requires hyphens; int rejects them.
        $entries = [
            self::entry('GET', '/x/{id:int}'),
            self::entry('GET', '/x/{id:uuid}'),
        ];
        $this->assertSame([], (new ConflictDetector())->detect($entries));
    }

    public function testAlphaAndUuidDoNotOverlap(): void
    {
        // uuid contains digits; alpha rejects them.
        $entries = [
            self::entry('GET', '/x/{name:alpha}'),
            self::entry('GET', '/x/{id:uuid}'),
        ];
        $this->assertSame([], (new ConflictDetector())->detect($entries));
    }

    public function testAnyOverlapsWithEverything(): void
    {
        // any = [^/]+ — its language is the universe of one-segment
        // strings, so it overlaps with every other constraint.
        foreach (['int', 'slug', 'alpha', 'uuid', 'any'] as $type) {
            $entries = [
                self::entry('GET', '/x/{a:' . $type . '}'),
                self::entry('GET', '/x/{b:any}'),
            ];
            $this->assertCount(
                1,
                (new ConflictDetector())->detect($entries),
                "any must overlap with $type"
            );
        }
    }

    public function testSlugOverlapsWithUuid(): void
    {
        // uuid = [0-9a-f]{8}-[0-9a-f]{4}-... — every char is in
        // slug = [a-z0-9-]+. Lowercase hex + dash is a slug subset.
        $entries = [
            self::entry('GET', '/x/{a:slug}'),
            self::entry('GET', '/x/{b:uuid}'),
        ];
        $this->assertCount(1, (new ConflictDetector())->detect($entries));
    }

    public function testCustomConstraintTypeIsConservativelyOverlapping(): void
    {
        // When the detector is told about a custom type via the
        // constructor, that type is no longer "unknown" (so it
        // doesn't get filtered as invalid), but it's also not in
        // the static matrix — so the typesOverlap() check falls
        // back to conservative `true`. False positives are
        // preferable to false negatives in a CI gate.
        $detector = new ConflictDetector(
            ConflictDetector::DEFAULT_CONSTRAINTS + ['hash' => '[a-f0-9]+'],
        );
        $entries = [
            self::entry('GET', '/x/{a:hash}'),
            self::entry('GET', '/x/{b:int}'),
        ];
        $this->assertCount(1, $detector->detect($entries));
    }

    public function testOverriddenBuiltinFallsBackToConservativeOverlap(): void
    {
        // If an app overrides `int` to a different regex, the
        // static matrix's "int ∩ alpha = ∅" claim no longer holds —
        // an overridden int could accept letters. Detector must
        // detect this and fall back to conservative overlap.
        $overridden = ConflictDetector::DEFAULT_CONSTRAINTS;
        $overridden['int'] = '[A-Z][0-9]+'; // not the default \d+
        $detector = new ConflictDetector($overridden);

        $entries = [
            self::entry('GET', '/x/{id:int}'),
            self::entry('GET', '/x/{name:alpha}'),
        ];
        // With default constraints these would not conflict; with
        // the override the detector can no longer trust the matrix
        // and conservatively flags overlap.
        $this->assertCount(1, $detector->detect($entries));
    }

    public function testStandardConstraintsStillUseMatrixWhenSomeAreOverridden(): void
    {
        // Overriding `int` must not poison the slug-vs-uuid pair —
        // those are still bound to their defaults, so the matrix
        // applies and the pair is still a conflict (slug ⊃ uuid).
        $overridden = ConflictDetector::DEFAULT_CONSTRAINTS;
        $overridden['int'] = '[A-Z][0-9]+';
        $detector = new ConflictDetector($overridden);

        $entries = [
            self::entry('GET', '/x/{a:slug}'),
            self::entry('GET', '/x/{b:uuid}'),
        ];
        $this->assertCount(1, $detector->detect($entries));
    }

    public function testUnknownConstraintTypeIsReportedAsInvalid(): void
    {
        // `{id:nonsense}` would make Router::compile() throw at
        // registration. The detector reports the same diagnostic
        // at CI time so the gate matches runtime semantics.
        $entries = [
            self::entry('GET', '/x/{id:nonsense}'),
        ];
        $invalid = (new ConflictDetector())->validate($entries);
        $this->assertCount(1, $invalid);
        $this->assertStringContainsString('nonsense', $invalid[0]->reason);
    }

    public function testInvalidRoutesAreSkippedFromConflictDetection(): void
    {
        // An unknown-type route can't be reasoned about — its
        // pattern wouldn't even register at runtime. The detector
        // skips it from the pairwise overlap check (so the user
        // sees the InvalidRoute finding without spurious
        // Conflict findings about a route that doesn't really
        // exist).
        $entries = [
            self::entry('GET', '/x/{id:nonsense}'),
            self::entry('GET', '/x/{id:int}'),
        ];
        $detector = new ConflictDetector();
        $this->assertCount(1, $detector->validate($entries));
        $this->assertSame([], $detector->detect($entries));
    }

    public function testMalformedPlaceholderIsReportedAsInvalid(): void
    {
        // Same grammar Router::compile() enforces — `{1bad:int}`
        // doesn't match [a-zA-Z_][a-zA-Z0-9_]* at the leading char.
        $entries = [
            self::entry('GET', '/x/{1bad:int}'),
        ];
        $invalid = (new ConflictDetector())->validate($entries);
        $this->assertCount(1, $invalid);
        $this->assertStringContainsString('malformed placeholder', $invalid[0]->reason);
    }

    public function testLiteralNotMatchingTypeIsNotAConflict(): void
    {
        // "/users/me" can never be parsed as int — disjoint.
        $entries = [
            self::entry('GET', '/users/me'),
            self::entry('GET', '/users/{id:int}'),
        ];
        $this->assertSame([], (new ConflictDetector())->detect($entries));
    }

    public function testEachConflictReportedOnce(): void
    {
        // Detector iterates with j > i, so a triplet that all
        // overlap should produce exactly C(3,2) = 3 conflicts —
        // not 6, not 9.
        $entries = [
            self::entry('GET', '/x/{a:any}'),
            self::entry('GET', '/x/{b:any}'),
            self::entry('GET', '/x/{c:any}'),
        ];
        $this->assertCount(3, (new ConflictDetector())->detect($entries));
    }

    public function testPatternNormalisationAlignsTrailingSlashes(): void
    {
        // The runtime Router strips trailing slashes; the detector
        // does the same, so `/x/y` and `/x/y/` are the same route
        // and DO conflict (same path).
        $entries = [
            self::entry('GET', '/x/y'),
            self::entry('GET', '/x/y/'),
        ];
        $this->assertCount(1, (new ConflictDetector())->detect($entries));
    }

    public function testCollectReturnsSourceCoordinates(): void
    {
        $detector = new ConflictDetector();
        $entries  = $detector->collect([Fixture\CleanController::class]);
        $this->assertNotEmpty($entries);
        foreach ($entries as $entry) {
            $this->assertStringContainsString('CleanController.php', $entry->file);
            $this->assertGreaterThan(0, $entry->line);
            $this->assertSame(Fixture\CleanController::class, $entry->class);
        }
    }

    public function testRootPathOverlapsWithItself(): void
    {
        $entries = [
            self::entry('GET', '/'),
            self::entry('GET', '/'),
        ];
        $this->assertCount(1, (new ConflictDetector())->detect($entries));
    }

    public function testDescribeProducesReadableOutput(): void
    {
        $result = (new ConflictDetector())->check([Fixture\ConflictController::class]);
        $this->assertCount(1, $result->conflicts);
        $output = $result->conflicts[0]->describe();
        $this->assertStringContainsString('Ambiguous routes', $output);
        $this->assertStringContainsString('GET /items/{id:int}', $output);
        $this->assertStringContainsString('GET /items/{slug:slug}', $output);
    }

    public function testResultDescribeRendersBothInvalidAndConflicts(): void
    {
        $entries = [
            self::entry('GET', '/x/{id:nonsense}'),
            self::entry('GET', '/y/{id:int}'),
            self::entry('GET', '/y/{slug:slug}'),
        ];
        $detector = new ConflictDetector();
        $result   = new \Rxn\Framework\Http\Routing\DetectorResult(
            invalid:   $detector->validate($entries),
            conflicts: $detector->detect($entries),
        );
        $output = $result->describe();
        $this->assertStringContainsString('invalid route', $output);
        $this->assertStringContainsString('route conflict', $output);
        $this->assertStringContainsString("unknown constraint type 'nonsense'", $output);
    }

    private static function entry(string $method, string $pattern): RouteEntry
    {
        return new RouteEntry(
            method: $method,
            pattern: $pattern,
            class: 'Synthetic',
            methodName: 'fake',
            file: '<test>',
            line: 0,
        );
    }
}
