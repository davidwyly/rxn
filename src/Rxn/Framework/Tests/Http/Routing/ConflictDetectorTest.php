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
        $conflicts = $detector->check([Fixture\CleanController::class]);
        $this->assertSame(
            [],
            $conflicts,
            "CleanController must not produce conflicts:\n" . self::renderConflicts($conflicts)
        );
    }

    public function testIntVsSlugIsAConflict(): void
    {
        $detector = new ConflictDetector();
        $conflicts = $detector->check([Fixture\ConflictController::class]);
        $this->assertCount(1, $conflicts);
        $this->assertSame('GET', $conflicts[0]->a->method);
        $this->assertSame('GET', $conflicts[0]->b->method);
        // Both /items/{id:int} and /items/{slug:slug} accept "123".
        $patterns = [$conflicts[0]->a->pattern, $conflicts[0]->b->pattern];
        sort($patterns);
        $this->assertSame(['/items/{id:int}', '/items/{slug:slug}'], $patterns);
    }

    public function testLiteralMatchingDynamicIsAConflict(): void
    {
        $detector = new ConflictDetector();
        $conflicts = $detector->check([Fixture\StaticVsDynamicController::class]);
        $this->assertCount(1, $conflicts);
        $patterns = [$conflicts[0]->a->pattern, $conflicts[0]->b->pattern];
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
        // The detector doesn't know what regex a custom type
        // resolves to, so it must conservatively assume overlap —
        // false positives are preferable to false negatives in a
        // CI gate.
        $entries = [
            self::entry('GET', '/x/{a:hash}'),
            self::entry('GET', '/x/{b:int}'),
        ];
        $conflicts = (new ConflictDetector())->detect($entries);
        $this->assertCount(1, $conflicts);
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
        $conflicts = (new ConflictDetector())->check([Fixture\ConflictController::class]);
        $this->assertCount(1, $conflicts);
        $output = $conflicts[0]->describe();
        $this->assertStringContainsString('Ambiguous routes', $output);
        $this->assertStringContainsString('GET /items/{id:int}', $output);
        $this->assertStringContainsString('GET /items/{slug:slug}', $output);
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

    /** @param list<\Rxn\Framework\Http\Routing\Conflict> $conflicts */
    private static function renderConflicts(array $conflicts): string
    {
        return implode("\n", array_map(static fn ($c) => $c->describe(), $conflicts));
    }
}
