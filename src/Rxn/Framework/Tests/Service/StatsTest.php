<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Service;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Service\Stats;

final class StatsTest extends TestCase
{
    public function testStopComputesElapsedMilliseconds(): void
    {
        $stats = new Stats();
        $start = microtime(true) - 0.012; // pretend we started 12ms ago

        $stats->stop($start);

        // Allow generous slack for CI scheduler jitter; what we
        // really care about is the unit (ms, not seconds, not µs).
        $this->assertIsFloat($stats->load_ms);
        $this->assertGreaterThan(10.0, $stats->load_ms);
        $this->assertLessThan(500.0, $stats->load_ms);
    }

    public function testStopRoundsToFourDecimalPlaces(): void
    {
        $stats = new Stats();
        $stats->stop(microtime(true));
        // round(..., 4) → at most 4 fractional digits.
        $fraction = (string)$stats->load_ms;
        if (str_contains($fraction, '.')) {
            $decimals = strlen(substr($fraction, strpos($fraction, '.') + 1));
            $this->assertLessThanOrEqual(4, $decimals);
        } else {
            $this->assertTrue(true);
        }
    }
}
