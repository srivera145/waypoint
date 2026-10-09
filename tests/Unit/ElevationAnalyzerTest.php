<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Services\Routing\ElevationAnalyzer;
use Keel\App\Services\Routing\ElevationProfile;
use Keel\App\Services\Routing\RouteResult;
use Keel\App\Services\Routing\RoutingException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeRoutingProvider;

class ElevationAnalyzerTest extends TestCase
{
    /** A profile sampled every 50 m over $lengthM, heights from $heightAt(distance). */
    private function profile(float $lengthM, callable $heightAt): ElevationProfile
    {
        $samples = [];

        for ($distance = 0.0; $distance <= $lengthM; $distance += 50.0) {
            $samples[] = [$distance, $heightAt($distance)];
        }

        return new ElevationProfile($samples, 50.0);
    }

    /** Flat, then $grade % for $slopeM metres starting at 2 km, then flat again. */
    private function hill(float $grade, float $slopeM, float $lengthM = 9000.0): ElevationProfile
    {
        return $this->profile($lengthM, static fn (float $d): float => 1000 + $grade / 100 * min(max($d - 2000, 0), $slopeM));
    }

    private function analyzer(): ElevationAnalyzer
    {
        return new ElevationAnalyzer(new FakeRoutingProvider());
    }

    public function testASteadyClimbIsOneSustainedGrade(): void
    {
        $stats = $this->analyzer()->stats($this->hill(6.0, 3000));

        self::assertTrue($stats['available']);
        self::assertSame(1180.0, $stats['max_elevation_m']);
        self::assertSame(1000.0, $stats['min_elevation_m']);
        self::assertEqualsWithDelta(180, $stats['total_climb_m'], 1);
        self::assertEqualsWithDelta(0, $stats['total_descent_m'], 1);
        self::assertEqualsWithDelta(6.0, $stats['max_grade_pct'], 0.05);
        self::assertEqualsWithDelta(6.0, $stats['max_uphill_grade_pct'], 0.05);
        self::assertSame(0.0, $stats['max_downhill_grade_pct']);
        self::assertSame(1, $stats['sustained_grade_count']);

        $grade = $stats['sustained_grades'][0];
        self::assertSame('up', $grade['direction']);
        self::assertEqualsWithDelta(3000, $grade['length_m'], 400);
        self::assertGreaterThan(ElevationAnalyzer::SUSTAINED_GRADE_PCT, $grade['avg_grade_pct']);
    }

    public function testTheProfileToDrawIsEvenlySpacedAndFollowsTheHill(): void
    {
        $stats = $this->analyzer()->stats($this->hill(6.0, 3000));
        $profile = $stats['profile'];
        $distances = array_column($profile, 0);
        $heights = array_column($profile, 1);

        self::assertCount(ElevationAnalyzer::PROFILE_POINTS, $profile);
        self::assertSame(0, $distances[0]);
        self::assertSame(9000, $distances[ElevationAnalyzer::PROFILE_POINTS - 1]);
        self::assertEqualsWithDelta(9000 / (ElevationAnalyzer::PROFILE_POINTS - 1), $distances[1] - $distances[0], 1);
        self::assertEqualsWithDelta(1000, $heights[0], 0.1);
        self::assertEqualsWithDelta(1180, end($heights), 0.1);
        self::assertTrue(array_reduce(array_keys($heights), static fn (bool $rising, int $i): bool => $rising && ($i === 0 || $heights[$i] >= $heights[$i - 1] - 0.05), true), 'A hill that only climbs draws as one.');

        // A short route keeps every sample.
        self::assertCount(31, $this->analyzer()->stats($this->profile(1500, static fn (float $d): float => 100 + $d * 0.03))['profile']);

        // Nothing to draw when there is no elevation.
        self::assertSame([], $this->analyzer()->stats($this->profile(2000, static fn (): ?float => null))['profile']);
    }

    public function testADescentIsCountedAsSustainedToo(): void
    {
        $stats = $this->analyzer()->stats($this->hill(-7.0, 2000));

        self::assertEqualsWithDelta(7.0, $stats['max_downhill_grade_pct'], 0.05);
        self::assertEqualsWithDelta(7.0, $stats['max_grade_pct'], 0.05);
        self::assertEqualsWithDelta(140, $stats['total_descent_m'], 1);
        self::assertSame(1, $stats['sustained_grade_count']);
        self::assertSame('down', $stats['sustained_grades'][0]['direction']);
    }

    public function testAShortSteepPitchIsSteepButNotSustained(): void
    {
        $stats = $this->analyzer()->stats($this->hill(9.0, 600));

        self::assertGreaterThan(7.0, $stats['max_grade_pct']);
        self::assertLessThanOrEqual(9.0, $stats['max_grade_pct']);
        self::assertSame(0, $stats['sustained_grade_count'], '600 m is under the 1 km a sustained grade needs.');
    }

    public function testAGentleGradeIsNeverSustained(): void
    {
        $stats = $this->analyzer()->stats($this->hill(4.5, 5000, 12000));

        self::assertSame(0, $stats['sustained_grade_count']);
        self::assertEqualsWithDelta(4.5, $stats['max_grade_pct'], 0.05);
    }

    public function testSampleNoiseDoesNotReadAsGradesOrClimbing(): void
    {
        // Flat road, but every other sample reads 3 m high: elevation tiles do this.
        $stats = $this->analyzer()->stats($this->profile(5000, static fn (float $d): float => 1500 + ((int) ($d / 50) % 2) * 3));

        self::assertLessThan(1.0, $stats['max_grade_pct']);
        self::assertLessThan(10, $stats['total_climb_m']);
        self::assertSame(0, $stats['sustained_grade_count']);
    }

    public function testMissingHeightsAreSkippedAndCoverageReported(): void
    {
        $stats = $this->analyzer()->stats($this->profile(5000, static fn (float $d): ?float => ((int) ($d / 50)) % 5 === 0 ? null : 1200.0));

        self::assertTrue($stats['available']);
        self::assertSame(79.2, $stats['coverage_pct'], '80 of 101 samples have a height.');
        self::assertSame(0.0, $stats['max_grade_pct']);
    }

    public function testNoHeightsAtAllIsUnavailable(): void
    {
        $stats = $this->analyzer()->stats($this->profile(2000, static fn (): ?float => null));

        self::assertFalse($stats['available']);
        self::assertSame(ElevationAnalyzer::UNAVAILABLE_NO_DATA, $stats['unavailable_reason']);
        self::assertSame(0.0, $stats['coverage_pct']);
        self::assertNull($stats['max_grade_pct']);
        self::assertNull($stats['sustained_grade_count']);
        self::assertSame([], $stats['sustained_grades']);
    }

    public function testARouteShorterThanOneGradeWindowStillGetsAGrade(): void
    {
        $stats = $this->analyzer()->stats($this->profile(150, static fn (float $d): float => 100 + $d * 0.03));

        self::assertTrue($stats['available']);
        self::assertGreaterThan(0.0, $stats['max_grade_pct']);
        self::assertLessThanOrEqual(3.0, $stats['max_grade_pct']);
    }

    public function testAnalyzeAsksTheProviderAndWidensSpacingOnLongRoutes(): void
    {
        $provider = new FakeRoutingProvider();
        $short = $provider->addRoute(600, 9000, static fn (float $d): float => 1000 + 0.06 * min(max($d - 2000, 0), 3000));

        $stats = (new ElevationAnalyzer($provider))->analyze($short);
        self::assertSame(1, $stats['sustained_grade_count']);
        self::assertSame(1, $provider->elevationCalls);

        $spy = new class extends FakeRoutingProvider {
            public array $spacings = [];

            public function elevation(string $polyline, float $sampleSpacingM = 50.0): ElevationProfile
            {
                $this->spacings[] = $sampleSpacingM;

                return new ElevationProfile([[0.0, 100.0], [$sampleSpacingM, 100.0]], $sampleSpacingM);
            }
        };

        $analyzer = new ElevationAnalyzer($spy);
        $analyzer->analyze(new RouteResult('x', 9000, 600));
        $analyzer->analyze(new RouteResult('x', 1_000_000, 36000));

        self::assertSame([50.0, 200.0], $spy->spacings, 'A 1,000 km route is sampled every 200 m: 5,000 samples.');
    }

    public function testAFailedFetchIsUnavailableNotAnError(): void
    {
        $provider = new FakeRoutingProvider([], null, RoutingException::unavailable());

        $stats = (new ElevationAnalyzer($provider))->analyze(new RouteResult('x', 9000, 600));

        self::assertFalse($stats['available']);
        self::assertSame(ElevationAnalyzer::UNAVAILABLE_FETCH_FAILED, $stats['unavailable_reason']);
    }
}
