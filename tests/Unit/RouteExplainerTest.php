<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Models\RoutePreference;
use Keel\App\Services\Routing\ElevationAnalyzer;
use Keel\App\Services\Routing\RouteExplainer;
use Keel\App\Services\Routing\RouteRanker;
use Keel\App\Services\Routing\RouteRequest;
use Keel\App\Services\Routing\RouteResult;
use Keel\App\Services\Units;
use PHPUnit\Framework\TestCase;

/**
 * "No claim that is not backed by a computed field", checked mechanically: for every sentence
 * the explainer writes, in several scenarios and both unit systems,
 *   - the template has no digits, so every number in the text came in through a placeholder;
 *   - every placeholder value is exactly the formatted value of one of the sentence's facts;
 *   - every fact equals the computed field it names (route, elevation, ranking, request);
 *   - the claim's condition holds for those facts ("avoids tolls" only when has_toll is false).
 * A sentence code with no condition registered here fails, so a new template cannot slip by.
 */
class RouteExplainerTest extends TestCase
{
    private const LIMITS = ['height_m' => 3.81, 'width_m' => 2.6, 'length_m' => 10.7, 'weight_kg' => 10000.0, 'axle_load_kg' => 6500.0];

    private function elevation(float $climb, float $maxGrade, int $sustained, float $coverage = 100.0): array
    {
        $grades = [];
        for ($i = 0; $i < $sustained; $i++) {
            $grades[] = ['start_m' => $i * 5000.0, 'length_m' => 1200.0 + $i * 900, 'avg_grade_pct' => 5.4 + $i * 0.3, 'direction' => $i % 2 ? 'down' : 'up'];
        }

        return [
            'available' => true, 'unavailable_reason' => null, 'sample_spacing_m' => 50.0, 'coverage_pct' => $coverage,
            'max_elevation_m' => 1300 + $climb, 'min_elevation_m' => 1300.0, 'total_climb_m' => $climb, 'total_descent_m' => 20.0,
            'max_grade_pct' => $maxGrade, 'max_uphill_grade_pct' => $maxGrade, 'max_downhill_grade_pct' => 2.0,
            'sustained_grade_count' => $sustained, 'sustained_grades' => $grades,
        ];
    }

    private function candidate(float $durationS, float $distanceM, array $elevation, array $flags = []): array
    {
        return ['route' => new RouteResult('p' . $durationS, $distanceM, $durationS, ...$flags), 'elevation' => $elevation];
    }

    private function request(array $preferences): RouteRequest
    {
        return new RouteRequest(40.76, -111.89, 40.65, -111.50, 'rv', 3.81, 2.6, 10.7, 10000.0, 6500.0, 2,
            avoidTolls: (bool) ($preferences['avoid_tolls'] ?? false),
            avoidHighways: (bool) ($preferences['avoid_highways'] ?? false),
            avoidFerries: (bool) ($preferences['avoid_ferries'] ?? false),
            avoidMountains: (bool) ($preferences['avoid_mountains'] ?? false),
        );
    }

    /** @return array<string, array{candidates: list<array>, preferences: array}> */
    private function scenarios(): array
    {
        return [
            'three routes, tolls avoided, grade limit' => [
                'candidates' => [
                    $this->candidate(2157, 53300, $this->elevation(1104, 7.3, 4), ['hasToll' => true, 'hasHighway' => true]),
                    $this->candidate(2772, 54100, $this->elevation(400, 5.6, 1), ['hasHighway' => true]),
                    $this->candidate(3100, 49000, $this->elevation(700, 6.1, 2), ['hasHighway' => false]),
                ],
                'preferences' => ['avoid_tolls' => 1, 'max_grade_pct' => '7.0', 'avoid_ferries' => 1],
            ],
            'fastest is also easiest, highways avoided' => [
                'candidates' => [
                    $this->candidate(3600, 60000, $this->elevation(50, 2.0, 0), ['hasHighway' => false]),
                    $this->candidate(3630, 59000, $this->elevation(55, 2.5, 0), ['hasHighway' => false]),
                ],
                'preferences' => ['avoid_highways' => 1, 'max_grade_pct' => '6.5'],
            ],
            'missing and partial elevation, provider warning' => [
                'candidates' => [
                    $this->candidate(1800, 30000, ElevationAnalyzer::unavailable(ElevationAnalyzer::UNAVAILABLE_NO_DATA, 50.0, 0.0)),
                    $this->candidate(1900, 31000, $this->elevation(300, 4.0, 0, 72.5), ['hasTimeRestrictions' => true, 'warnings' => ['Something was clamped']]),
                    $this->candidate(2000, 33000, $this->elevation(250, 3.0, 0)),
                ],
                'preferences' => [],
            ],
            'flat third, ignored preferences, tolls only on the fastest' => [
                'candidates' => [
                    $this->candidate(3600, 60000, $this->elevation(600, 8.0, 3), ['hasToll' => true, 'hasHighway' => true, 'hasFerry' => true]),
                    $this->candidate(3650, 61000, $this->elevation(650, 5.0, 0), ['hasHighway' => true]),
                    $this->candidate(5200, 58000, $this->elevation(100, 3.0, 0), ['hasHighway' => true]),
                ],
                'preferences' => ['avoid_highways' => 1, 'avoid_ferries' => 1],
            ],
            'one route' => [
                'candidates' => [$this->candidate(45, 400, $this->elevation(5, 1.2, 0), ['hasToll' => true])],
                'preferences' => ['avoid_tolls' => 1],
            ],
        ];
    }

    public function testEverySentenceIsBackedByAComputedField(): void
    {
        $checked = [];

        foreach ($this->scenarios() as $name => $scenario) {
            $preferences = $scenario['preferences'] + RoutePreference::DEFAULTS;
            $options = (new RouteRanker())->rank($scenario['candidates'], $preferences, 'rv');
            $request = $this->request($preferences);

            foreach ([Units::IMPERIAL, Units::METRIC] as $units) {
                foreach ($options as $option) {
                    $explanation = (new RouteExplainer($units))->explain($option, $options[0], count($options), $request, self::LIMITS);
                    $sentences = array_merge($explanation['reasons'], $explanation['warnings']);

                    self::assertSame(implode(' ', array_column($explanation['reasons'], 'text')), $explanation['text']);

                    foreach ($sentences as $sentence) {
                        $this->assertBacked($sentence, $option, $options, $request, $units, "{$name} / {$units} / {$sentence['code']}");
                        $checked[$sentence['code']] = true;
                    }
                }
            }
        }

        // The scenarios between them reach every sentence the explainer can write.
        $unreached = array_values(array_diff(array_keys($this->conditions()), array_keys($checked)));
        self::assertSame([], $unreached, 'No scenario reaches: ' . implode(', ', $unreached));
        self::assertSame([], array_values(array_diff(array_keys($checked), array_keys($this->conditions()))));
    }

    public function testAnRvRouteReadsAsExpected(): void
    {
        $scenario = $this->scenarios()['three routes, tolls avoided, grade limit'];
        $preferences = $scenario['preferences'] + RoutePreference::DEFAULTS;
        $options = (new RouteRanker())->rank($scenario['candidates'], $preferences, 'rv');
        $explainer = new RouteExplainer(Units::IMPERIAL);

        $fastest = $explainer->explain($options[0], $options[0], 3, $this->request($preferences), self::LIMITS);
        self::assertSame(
            'The quickest of the 3 routes found, at 36 min. '
            . 'Climbs 3,622 ft in all and tops out at 7,887 ft, with a steepest grade of 7.3%. '
            . '4 grades stay steeper than 5% for more than 0.6 mi; the longest runs 2.4 mi averaging 6.3%. '
            . 'Takes no ferries, as you asked. '
            . "Planned with OpenStreetMap's road limits for your vehicle's height (12'6\"), width (8'6\"), length (35'1\"), weight (22,046 lbs) and axle load (14,330 lbs).",
            $fastest['text']
        );
        self::assertSame(['Uses toll roads, though you asked to avoid them.', 'Its steepest grade, 7.3%, is over your 7% limit.'], array_column($fastest['warnings'], 'text'));

        $friendly = $explainer->explain($options[1], $options[0], 3, $this->request($preferences), self::LIMITS);
        self::assertStringStartsWith(
            'The easiest on your vehicle once climbs and steep grades are counted. Takes 46 min, 10 min longer than the fastest route. ',
            $friendly['text']
        );
        self::assertStringContainsString('2,310 ft less climbing than the fastest route.', $friendly['text']);
        self::assertStringContainsString('No measured grade is over your 7% limit.', $friendly['text']);
        self::assertStringContainsString('Avoids toll roads, as you asked.', $friendly['text']);
    }

    public function testNothingIsSaidAboutGradesWithoutElevationOrAboutTollsNobodyAskedAbout(): void
    {
        $options = (new RouteRanker())->rank([
            $this->candidate(1800, 30000, ElevationAnalyzer::unavailable(ElevationAnalyzer::UNAVAILABLE_FETCH_FAILED, 50.0, null)),
        ], RoutePreference::DEFAULTS, 'car');

        $explanation = (new RouteExplainer())->explain($options[0], $options[0], 1, $this->request([]), []);

        self::assertSame('The only route found, at 30 min.', $explanation['text']);
        self::assertSame(["Elevation data isn't available for this route, so its grades weren't checked."], array_column($explanation['warnings'], 'text'));
    }

    public function testNeverClaimsTheRouteClearsAnything(): void
    {
        foreach ($this->scenarios() as $scenario) {
            $preferences = $scenario['preferences'] + RoutePreference::DEFAULTS;
            $options = (new RouteRanker())->rank($scenario['candidates'], $preferences, 'rv');

            foreach ($options as $option) {
                $text = (new RouteExplainer())->explain($option, $options[0], count($options), $this->request($preferences), self::LIMITS)['text'];

                self::assertDoesNotMatchRegularExpression('/\b(clears?|guarantee\w*|safe(ly)?|will fit|fits)\b/i', $text);
            }
        }
    }

    private function assertBacked(array $sentence, array $option, array $options, RouteRequest $request, string $units, string $where): void
    {
        $facts = $sentence['facts'];

        self::assertDoesNotMatchRegularExpression('/\d/', $sentence['template'], "{$where}: a number written into the template");
        self::assertSame(strtr($sentence['template'], $sentence['values']), $sentence['text'], $where);

        preg_match_all('/\{[a-z_]+\}/', $sentence['template'], $placeholders);
        self::assertEqualsCanonicalizing($placeholders[0], array_keys($sentence['values']), "{$where}: placeholders and values differ");

        $sources = $this->sources($option, $options[0], count($options), $request);

        foreach ($facts as $key => $value) {
            if ($sentence['code'] === 'provider' && $key === 'text') {
                self::assertContains($value, $option['route']->warnings, $where);
                continue;
            }

            self::assertArrayHasKey($key, $sources, "{$where}: fact '{$key}' has no computed source");
            self::assertEquals($sources[$key], $value, "{$where}: fact '{$key}'");
        }

        foreach ($sentence['values'] as $placeholder => $value) {
            self::assertSame($this->expectedValue($sentence['code'], $placeholder, $facts, $units), $value, "{$where}: {$placeholder}");
        }

        $conditions = $this->conditions();
        self::assertArrayHasKey($sentence['code'], $conditions, "{$where}: no condition registered for this claim");
        self::assertTrue($conditions[$sentence['code']]($facts, $option, $options), "{$where}: the facts do not support the claim");
    }

    /** Every computed field a sentence may rest on, for one option. */
    private function sources(array $option, array $fastest, int $count, RouteRequest $request): array
    {
        $route = $option['route'];
        $elevation = $option['elevation'];
        $longest = null;

        foreach ($elevation['sustained_grades'] as $grade) {
            if ($longest === null || $grade['length_m'] > $longest['length_m']) {
                $longest = $grade;
            }
        }

        return self::LIMITS + [
            'route_count' => $count,
            'duration_s' => $route->durationS,
            'fastest_duration_s' => $fastest['route']->durationS,
            'duration_delta_s' => $route->durationS - $fastest['route']->durationS,
            'distance_m' => $route->distanceM,
            'fastest_distance_m' => $fastest['route']->distanceM,
            'distance_delta_m' => $route->distanceM - $fastest['route']->distanceM,
            'score_s' => $option['score_s'],
            'fastest_score_s' => $fastest['score_s'],
            'grade_penalty_s' => $option['grade_penalty_s'],
            'has_toll' => $route->hasToll,
            'fastest_has_toll' => $fastest['route']->hasToll,
            'has_highway' => $route->hasHighway,
            'fastest_has_highway' => $fastest['route']->hasHighway,
            'has_ferry' => $route->hasFerry,
            'has_time_restrictions' => $route->hasTimeRestrictions,
            'avoid_tolls' => $request->avoidTolls,
            'avoid_highways' => $request->avoidHighways,
            'avoid_ferries' => $request->avoidFerries,
            'available' => $elevation['available'],
            'unavailable_reason' => $elevation['unavailable_reason'],
            'coverage_pct' => $elevation['coverage_pct'],
            'total_climb_m' => $elevation['total_climb_m'],
            'fastest_total_climb_m' => $fastest['elevation']['total_climb_m'],
            'climb_delta_m' => $elevation['total_climb_m'] === null || $fastest['elevation']['total_climb_m'] === null ? null : $elevation['total_climb_m'] - $fastest['elevation']['total_climb_m'],
            'max_elevation_m' => $elevation['max_elevation_m'],
            'max_grade_pct' => $elevation['max_grade_pct'],
            'max_grade_limit_pct' => $option['max_grade_limit_pct'],
            'sustained_grade_count' => $elevation['sustained_grade_count'],
            'sustained_threshold_pct' => ElevationAnalyzer::SUSTAINED_GRADE_PCT,
            'sustained_min_length_m' => ElevationAnalyzer::SUSTAINED_MIN_LENGTH_M,
            'longest_length_m' => $longest['length_m'] ?? null,
            'longest_avg_grade_pct' => $longest['avg_grade_pct'] ?? null,
        ];
    }

    /** What a placeholder has to read, recomputed here from the sentence's own facts. */
    private function expectedValue(string $code, string $placeholder, array $facts, string $units): string
    {
        $percent = static fn (float $value): string => rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') . '%';

        return match ($placeholder) {
            '{duration}' => Units::formatDuration($facts['duration_s']),
            '{count}' => (string) ($code === 'fastest' ? $facts['route_count'] : $facts['sustained_grade_count']),
            '{delta}' => match ($code) {
                'time_slower' => Units::formatDuration($facts['duration_delta_s']),
                'distance_longer', 'distance_shorter' => Units::formatDistance($facts['distance_delta_m'], $units),
                'climb_less', 'climb_more' => Units::formatElevation(abs($facts['climb_delta_m']), $units),
            },
            '{distance}' => Units::formatDistance($facts['distance_m'], $units),
            '{climb}' => Units::formatElevation($facts['total_climb_m'], $units),
            '{max_elevation}' => Units::formatElevation($facts['max_elevation_m'], $units),
            '{max_grade}' => $percent($facts['max_grade_pct']),
            '{threshold}' => $percent($facts['sustained_threshold_pct']),
            '{min_length}' => Units::formatDistance($facts['sustained_min_length_m'], $units),
            '{longest}' => Units::formatDistance($facts['longest_length_m'], $units),
            '{longest_grade}' => $percent($facts['longest_avg_grade_pct']),
            '{limit}' => $percent($facts['max_grade_limit_pct']),
            '{coverage}' => $percent($facts['coverage_pct']),
            '{text}' => $facts['text'],
            '{dimensions}' => $this->dimensions($facts, $units),
        };
    }

    private function dimensions(array $facts, string $units): string
    {
        $names = ['height_m' => 'height', 'width_m' => 'width', 'length_m' => 'length', 'weight_kg' => 'weight', 'axle_load_kg' => 'axle load'];
        $parts = [];

        foreach ($names as $key => $name) {
            $value = $facts[$key];
            $parts[] = $name . ' (' . (str_ends_with($key, '_kg') ? Units::formatWeight($value, $units) : Units::formatLength($value, $units)) . ')';
        }

        return implode(', ', array_slice($parts, 0, -1)) . ' and ' . end($parts);
    }

    /** @return array<string, \Closure(array $facts, array $option, array $options): bool> */
    private function conditions(): array
    {
        $lowest = static fn (array $options, string $field, bool $measuredOnly = false): float => min(array_map(
            static fn (array $other): float => (float) ($field === 'climb' ? $other['elevation']['total_climb_m'] : $other[$field]),
            array_filter($options, static fn (array $other): bool => !$measuredOnly || $other['elevation']['available'])
        ));

        return [
            'only_route' => static fn (array $f): bool => $f['route_count'] === 1,
            'fastest' => static fn (array $f, array $o, array $all): bool => $f['route_count'] > 1 && $f['duration_s'] == $lowest($all, 'duration'),
            'also_vehicle_friendly' => static fn (array $f, array $o, array $all): bool => $f['score_s'] == $lowest($all, 'score_s', true),
            'vehicle_friendly' => static fn (array $f, array $o, array $all): bool => $f['score_s'] == $lowest($all, 'score_s', true) && $f['score_s'] <= $f['fastest_score_s'],
            'scenic' => static fn (array $f): bool => $f['has_highway'] === false && $f['fastest_has_highway'] === true,
            'flat' => static fn (array $f, array $o, array $all): bool => $f['total_climb_m'] == $lowest(array_filter($all, static fn (array $x): bool => $x['elevation']['available']), 'climb') && $f['total_climb_m'] < $f['fastest_total_climb_m'],
            'alternate' => static fn (array $f): bool => $f['route_count'] > 1,
            'time_same' => static fn (array $f): bool => $f['duration_delta_s'] < 60,
            'time_slower' => static fn (array $f): bool => $f['duration_delta_s'] >= 60,
            'distance_longer' => static fn (array $f): bool => $f['distance_delta_m'] > 0,
            'distance_shorter' => static fn (array $f): bool => $f['distance_delta_m'] < 0,
            'elevation_summary' => static fn (array $f, array $o): bool => $o['elevation']['available'],
            'no_sustained_grades' => static fn (array $f): bool => $f['sustained_grade_count'] === 0,
            'sustained_grades' => static fn (array $f): bool => $f['sustained_grade_count'] >= 1,
            'climb_less' => static fn (array $f): bool => $f['climb_delta_m'] < 0,
            'climb_more' => static fn (array $f): bool => $f['climb_delta_m'] > 0,
            'within_grade_limit' => static fn (array $f): bool => $f['max_grade_pct'] <= $f['max_grade_limit_pct'],
            'tolls_avoided' => static fn (array $f): bool => $f['avoid_tolls'] && !$f['has_toll'],
            'skips_fastest_tolls' => static fn (array $f): bool => !$f['has_toll'] && $f['fastest_has_toll'],
            'highways_avoided' => static fn (array $f): bool => $f['avoid_highways'] && !$f['has_highway'],
            'ferries_avoided' => static fn (array $f): bool => $f['avoid_ferries'] && !$f['has_ferry'],
            'vehicle_limits' => static fn (array $f): bool => $f !== [],
            'provider' => static fn (array $f, array $o): bool => in_array($f['text'], $o['route']->warnings, true),
            'time_restrictions' => static fn (array $f): bool => $f['has_time_restrictions'] === true,
            'uses_tolls' => static fn (array $f): bool => $f['avoid_tolls'] && $f['has_toll'],
            'uses_highways' => static fn (array $f): bool => $f['avoid_highways'] && $f['has_highway'],
            'uses_ferry' => static fn (array $f): bool => $f['avoid_ferries'] && $f['has_ferry'],
            'elevation_unavailable' => static fn (array $f): bool => $f['available'] === false,
            'elevation_partial' => static fn (array $f): bool => $f['coverage_pct'] < ElevationAnalyzer::MIN_COVERAGE_PCT,
            'over_grade_limit' => static fn (array $f): bool => $f['max_grade_pct'] > $f['max_grade_limit_pct'],
        ];
    }
}
