<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Models\RoutePreference;
use Keel\App\Services\Routing\ElevationAnalyzer;
use Keel\App\Services\Routing\RouteRanker;
use Keel\App\Services\Routing\RouteResult;
use PHPUnit\Framework\TestCase;

class RouteRankerTest extends TestCase
{
    private function elevation(float $climb, float $maxGrade = 3.0, int $sustained = 0): array
    {
        return [
            'available' => true,
            'unavailable_reason' => null,
            'sample_spacing_m' => 50.0,
            'coverage_pct' => 100.0,
            'max_elevation_m' => 1000 + $climb,
            'min_elevation_m' => 1000.0,
            'total_climb_m' => $climb,
            'total_descent_m' => 0.0,
            'max_grade_pct' => $maxGrade,
            'max_uphill_grade_pct' => $maxGrade,
            'max_downhill_grade_pct' => 0.0,
            'sustained_grade_count' => $sustained,
            'sustained_grades' => array_fill(0, $sustained, ['start_m' => 0.0, 'length_m' => 1500.0, 'avg_grade_pct' => 6.0, 'direction' => 'up']),
        ];
    }

    private function candidate(float $durationS, array $elevation, array $flags = []): array
    {
        return ['route' => new RouteResult('poly' . $durationS, $durationS * 20, $durationS, ...($flags + ['hasHighway' => true])), 'elevation' => $elevation];
    }

    private function rank(array $candidates, array $preferences = [], string $type = 'car'): array
    {
        return (new RouteRanker())->rank($candidates, $preferences + RoutePreference::DEFAULTS, $type);
    }

    /** @return list<string> each option's labels joined, in order */
    private function labels(array $options): array
    {
        return array_map(static fn (array $option): string => implode('+', $option['labels']), $options);
    }

    public function testThreeRoutesGetFastestVehicleFriendlyAndAThird(): void
    {
        $options = $this->rank([
            $this->candidate(3600, $this->elevation(800, 7.0, 2)),
            $this->candidate(4000, $this->elevation(400), ['hasHighway' => false]),
            $this->candidate(3800, $this->elevation(100)),
        ]);

        self::assertSame(['fastest', 'vehicle_friendly', 'scenic'], $this->labels($options));
        self::assertSame(3600.0, $options[0]['route']->durationS);
        self::assertSame(3800.0, $options[1]['route']->durationS);
        self::assertSame(4000.0, $options[2]['route']->durationS);

        self::assertSame(RouteRanker::OK, $options[0]['classification']);
        self::assertSame(RouteRanker::OK, $options[1]['classification']);
        self::assertSame(RouteRanker::SUBOPTIMAL, $options[2]['classification'], 'The 3800 s route is faster and easier.');
    }

    public function testFastestThatIsAlsoEasiestCarriesBothLabels(): void
    {
        $options = $this->rank([
            $this->candidate(3700, $this->elevation(300)),
            $this->candidate(3600, $this->elevation(50)),
        ]);

        self::assertSame(['fastest+vehicle_friendly', 'alternate'], $this->labels($options));
        self::assertSame(RouteRanker::SUBOPTIMAL, $options[1]['classification']);
    }

    public function testAvoidMountainsWeighsGradesHeavily(): void
    {
        $candidates = [
            $this->candidate(3600, $this->elevation(200)),
            $this->candidate(3900, $this->elevation(0)),
        ];

        // A car: 200 m of climbing costs 200 s, and 3600 + 200 still beats 3900. The other
        // route climbs nothing at all, so it is the Flat one.
        self::assertSame(['fastest+vehicle_friendly', 'flat'], $this->labels($this->rank($candidates)));

        // Avoiding mountains, it costs five times that, and the flat route wins.
        $avoiding = $this->rank($candidates, ['avoid_mountains' => 1]);
        self::assertSame(['fastest', 'vehicle_friendly'], $this->labels($avoiding));
        self::assertSame(1000.0, $avoiding[0]['grade_penalty_s']);

        // The Flat personality counts as avoiding mountains.
        self::assertSame(['fastest', 'vehicle_friendly'], $this->labels($this->rank($candidates, ['personality' => 'flat'])));
    }

    public function testLargeVehiclesFeelGradesTwiceAsMuch(): void
    {
        $candidates = [$this->candidate(3600, $this->elevation(200)), $this->candidate(3900, $this->elevation(0))];

        $car = $this->rank($candidates, [], 'car');
        $rv = $this->rank($candidates, [], 'rv');

        self::assertSame(200.0, $car[0]['grade_penalty_s']);
        self::assertSame(400.0, $rv[0]['grade_penalty_s']);
        self::assertSame(['fastest', 'vehicle_friendly'], $this->labels($rv));
    }

    public function testOverTheMaxGradeIsRestrictedAndPenalisedHeavily(): void
    {
        $options = $this->rank([
            $this->candidate(3600, $this->elevation(100, 8.0)),
            $this->candidate(3900, $this->elevation(150, 5.5)),
        ], ['max_grade_pct' => '6.0']);

        self::assertSame(['fastest', 'vehicle_friendly'], $this->labels($options));
        self::assertTrue($options[0]['exceeds_max_grade']);
        self::assertSame(RouteRanker::RESTRICTED, $options[0]['classification']);
        self::assertSame(['code' => 'over_grade_limit', 'max_grade_pct' => 8.0, 'max_grade_limit_pct' => 6.0], $options[0]['warnings'][0]);
        // (100 m climbed + 3 grade points over 5% at 60 s) x 5.
        self::assertSame(1400.0, $options[0]['grade_penalty_s']);

        self::assertFalse($options[1]['exceeds_max_grade']);
        self::assertSame(RouteRanker::OK, $options[1]['classification']);
    }

    public function testImpassableRoutesAreNeverReturned(): void
    {
        $blocked = ['route' => new RouteResult('blocked', 50000, 3000, violations: ['Bridge clearance 3.5 m']), 'elevation' => $this->elevation(0)];

        $options = $this->rank([$blocked, $this->candidate(3600, $this->elevation(100))]);

        self::assertCount(1, $options);
        self::assertSame(3600.0, $options[0]['route']->durationS);
        self::assertSame(RouteRanker::IMPASSABLE, (new RouteRanker())->classifyHard($blocked['route']));
        self::assertSame([], $this->rank([$blocked]));
    }

    public function testIgnoredPreferencesAndTimeRestrictionsAreWarnings(): void
    {
        $options = $this->rank([
            $this->candidate(3600, $this->elevation(100), ['hasToll' => true, 'hasFerry' => true]),
            $this->candidate(3700, $this->elevation(100), ['hasTimeRestrictions' => true]),
            $this->candidate(3800, $this->elevation(100)),
        ], ['avoid_tolls' => 1, 'avoid_ferries' => 1]);

        self::assertSame(['uses_tolls', 'uses_ferry'], array_column($options[0]['warnings'], 'code'));
        self::assertSame(RouteRanker::RESTRICTED, $options[0]['classification']);

        $timeRestricted = array_values(array_filter($options, static fn (array $option): bool => $option['route']->durationS === 3700.0))[0];
        self::assertSame(['time_restrictions'], array_column($timeRestricted['warnings'], 'code'));

        // The clean 3800 s route is not made pointless by routes that carry warnings.
        $clean = array_values(array_filter($options, static fn (array $option): bool => $option['route']->durationS === 3800.0))[0];
        self::assertSame(RouteRanker::OK, $clean['classification']);
    }

    public function testRoutesWithoutElevationAreRestrictedAndNeverVehicleFriendly(): void
    {
        $missing = ElevationAnalyzer::unavailable(ElevationAnalyzer::UNAVAILABLE_NO_DATA, 50.0, 0.0);

        $options = $this->rank([
            $this->candidate(3600, $this->elevation(900, 7.0, 3)),
            $this->candidate(3700, $missing),
        ]);

        self::assertSame(['fastest', 'alternate'], $this->labels($options), 'One measured route has nothing to be friendlier than.');
        self::assertSame('elevation_unavailable', $options[1]['warnings'][0]['code']);
        self::assertSame(RouteRanker::RESTRICTED, $options[1]['classification']);
        self::assertSame(0.0, $options[1]['grade_penalty_s']);
    }

    public function testASingleRouteIsJustFastest(): void
    {
        $options = $this->rank([$this->candidate(3600, $this->elevation(100))]);

        self::assertSame(['fastest'], $this->labels($options));
        self::assertSame(RouteRanker::OK, $options[0]['classification']);
    }

    public function testAThirdRouteThatClimbsLeastByAMarginIsFlat(): void
    {
        $options = $this->rank([
            $this->candidate(3600, $this->elevation(600)),
            $this->candidate(3650, $this->elevation(450)),
            $this->candidate(4500, $this->elevation(100)),
        ]);

        self::assertSame(['fastest', 'vehicle_friendly', 'flat'], $this->labels($options));
    }

    public function testNoMoreThanThreeOptions(): void
    {
        $candidates = array_map(fn (int $i): array => $this->candidate(3600 + $i * 60, $this->elevation(100 + $i)), range(0, 4));

        self::assertCount(3, $this->rank($candidates));
    }
}
