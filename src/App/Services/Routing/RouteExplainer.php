<?php

namespace Keel\App\Services\Routing;

use Keel\App\Services\Units;

/**
 * Writes "Why this route?" from computed facts only, using fixed templates rather than a
 * language model, so it can never state something the data does not show.
 *
 * Every sentence is built by sentence(): a template with no digits in it, placeholder values
 * formatted from the facts, and the facts themselves, kept on the sentence so tests (and anyone
 * debugging) can check each claim against the numbers it came from. A sentence is only added
 * when the fact behind it exists: no elevation data means no grade sentences at all.
 */
class RouteExplainer
{
    /** Differences smaller than these are not worth a sentence. */
    public const SAME_TIME_S = 60.0;
    private const SAME_DISTANCE_M = 160.0;
    private const SAME_CLIMB_M = 10.0;

    private const LIMIT_NAMES = [
        'height_m' => 'height',
        'width_m' => 'width',
        'length_m' => 'length',
        'weight_kg' => 'weight',
        'axle_load_kg' => 'axle load',
    ];

    public function __construct(private string $unitSystem = Units::IMPERIAL)
    {
    }

    /**
     * @param array $option one option from RouteRanker::rank()
     * @param array $fastest the option labelled Fastest
     * @param int $routeCount how many options are shown
     * @param array $vehicleLimits from RoutingProvider::vehicleLimits(): the dimensions routed with
     * @return array{text: string, reasons: list<array>, warnings: list<array>} each reason and
     *         warning has code, text, template, values and facts
     */
    public function explain(array $option, array $fastest, int $routeCount, RouteRequest $request, array $vehicleLimits): array
    {
        $isFastest = in_array(RouteRanker::FASTEST, $option['labels'], true);

        $reasons = array_merge(
            $this->labelReasons($option, $fastest, $routeCount),
            $isFastest ? [] : $this->comparisonReasons($option, $fastest),
            $this->gradeReasons($option, $fastest, $isFastest),
            $this->preferenceReasons($option, $fastest, $isFastest, $request),
            $this->limitReasons($vehicleLimits),
        );

        return [
            'text' => implode(' ', array_column($reasons, 'text')),
            'reasons' => $reasons,
            'warnings' => array_map(fn (array $warning): array => $this->warning($warning), $option['warnings']),
        ];
    }

    private function labelReasons(array $option, array $fastest, int $routeCount): array
    {
        $route = $option['route'];
        $labels = $option['labels'];
        $reasons = [];

        if (in_array(RouteRanker::FASTEST, $labels, true)) {
            $facts = ['duration_s' => $route->durationS, 'route_count' => $routeCount];

            $reasons[] = $routeCount === 1
                ? $this->sentence('only_route', 'The only route found, at {duration}.', ['{duration}' => Units::formatDuration($route->durationS)], $facts)
                : $this->sentence('fastest', 'The quickest of the {count} routes found, at {duration}.', [
                    '{count}' => (string) $routeCount,
                    '{duration}' => Units::formatDuration($route->durationS),
                ], $facts);

            if (in_array(RouteRanker::VEHICLE_FRIENDLY, $labels, true)) {
                $reasons[] = $this->sentence('also_vehicle_friendly', 'It is also the easiest on your vehicle once climbs and steep grades are counted.', [], [
                    'score_s' => $option['score_s'],
                    'grade_penalty_s' => $option['grade_penalty_s'],
                ]);
            }

            return $reasons;
        }

        return [match ($labels[0]) {
            RouteRanker::VEHICLE_FRIENDLY => $this->sentence('vehicle_friendly', 'The easiest on your vehicle once climbs and steep grades are counted.', [], [
                'score_s' => $option['score_s'],
                'fastest_score_s' => $fastest['score_s'],
            ]),
            RouteRanker::SCENIC => $this->sentence('scenic', 'Stays off highways, which the fastest route uses.', [], [
                'has_highway' => $route->hasHighway,
                'fastest_has_highway' => $fastest['route']->hasHighway,
            ]),
            RouteRanker::FLAT => $this->sentence('flat', 'The least climbing of the routes found.', [], [
                'total_climb_m' => $option['elevation']['total_climb_m'],
                'fastest_total_climb_m' => $fastest['elevation']['total_climb_m'],
            ]),
            default => $this->sentence('alternate', 'A different way there, for comparison.', [], ['route_count' => $routeCount]),
        }];
    }

    private function comparisonReasons(array $option, array $fastest): array
    {
        $route = $option['route'];
        $timeDelta = $route->durationS - $fastest['route']->durationS;
        $distanceDelta = $route->distanceM - $fastest['route']->distanceM;
        $reasons = [];

        $timeFacts = ['duration_s' => $route->durationS, 'fastest_duration_s' => $fastest['route']->durationS, 'duration_delta_s' => $timeDelta];
        $reasons[] = $timeDelta < self::SAME_TIME_S
            ? $this->sentence('time_same', 'Takes {duration}, about the same as the fastest route.', ['{duration}' => Units::formatDuration($route->durationS)], $timeFacts)
            : $this->sentence('time_slower', 'Takes {duration}, {delta} longer than the fastest route.', [
                '{duration}' => Units::formatDuration($route->durationS),
                '{delta}' => Units::formatDuration($timeDelta),
            ], $timeFacts);

        if (abs($distanceDelta) >= self::SAME_DISTANCE_M) {
            $reasons[] = $this->sentence(
                $distanceDelta > 0 ? 'distance_longer' : 'distance_shorter',
                $distanceDelta > 0 ? 'Covers {distance}, {delta} more than the fastest route.' : 'Covers {distance}, {delta} less than the fastest route.',
                ['{distance}' => Units::formatDistance($route->distanceM, $this->unitSystem), '{delta}' => Units::formatDistance($distanceDelta, $this->unitSystem)],
                ['distance_m' => $route->distanceM, 'fastest_distance_m' => $fastest['route']->distanceM, 'distance_delta_m' => $distanceDelta],
            );
        }

        return $reasons;
    }

    private function gradeReasons(array $option, array $fastest, bool $isFastest): array
    {
        $elevation = $option['elevation'];

        if (!$elevation['available']) {
            return [];
        }

        $reasons = [$this->sentence('elevation_summary', 'Climbs {climb} in all and tops out at {max_elevation}, with a steepest grade of {max_grade}.', [
            '{climb}' => Units::formatElevation((float) $elevation['total_climb_m'], $this->unitSystem),
            '{max_elevation}' => Units::formatElevation((float) $elevation['max_elevation_m'], $this->unitSystem),
            '{max_grade}' => $this->percent((float) $elevation['max_grade_pct']),
        ], [
            'total_climb_m' => $elevation['total_climb_m'],
            'max_elevation_m' => $elevation['max_elevation_m'],
            'max_grade_pct' => $elevation['max_grade_pct'],
        ])];

        $thresholds = [
            '{threshold}' => $this->percent(ElevationAnalyzer::SUSTAINED_GRADE_PCT),
            '{min_length}' => Units::formatDistance(ElevationAnalyzer::SUSTAINED_MIN_LENGTH_M, $this->unitSystem),
        ];
        $thresholdFacts = [
            'sustained_grade_count' => $elevation['sustained_grade_count'],
            'sustained_threshold_pct' => ElevationAnalyzer::SUSTAINED_GRADE_PCT,
            'sustained_min_length_m' => ElevationAnalyzer::SUSTAINED_MIN_LENGTH_M,
        ];
        $count = (int) $elevation['sustained_grade_count'];

        if ($count === 0) {
            $reasons[] = $this->sentence('no_sustained_grades', 'No grade stays steeper than {threshold} for more than {min_length}.', $thresholds, $thresholdFacts);
        } else {
            $longest = array_reduce(
                $elevation['sustained_grades'],
                static fn (?array $carry, array $grade): array => $carry === null || $grade['length_m'] > $carry['length_m'] ? $grade : $carry
            );
            $values = $thresholds + [
                '{longest}' => Units::formatDistance((float) $longest['length_m'], $this->unitSystem),
                '{longest_grade}' => $this->percent((float) $longest['avg_grade_pct']),
            ];
            $facts = $thresholdFacts + ['longest_length_m' => $longest['length_m'], 'longest_avg_grade_pct' => $longest['avg_grade_pct']];

            $reasons[] = $count === 1
                ? $this->sentence('sustained_grades', 'One grade stays steeper than {threshold} for more than {min_length}: {longest} averaging {longest_grade}.', $values, $facts)
                : $this->sentence('sustained_grades', '{count} grades stay steeper than {threshold} for more than {min_length}; the longest runs {longest} averaging {longest_grade}.', $values + ['{count}' => (string) $count], $facts);
        }

        $fastestClimb = $fastest['elevation']['total_climb_m'];

        if (!$isFastest && $fastestClimb !== null) {
            $delta = (float) $elevation['total_climb_m'] - (float) $fastestClimb;

            if (abs($delta) >= self::SAME_CLIMB_M) {
                $reasons[] = $this->sentence(
                    $delta < 0 ? 'climb_less' : 'climb_more',
                    $delta < 0 ? '{delta} less climbing than the fastest route.' : '{delta} more climbing than the fastest route.',
                    ['{delta}' => Units::formatElevation(abs($delta), $this->unitSystem)],
                    ['total_climb_m' => $elevation['total_climb_m'], 'fastest_total_climb_m' => $fastestClimb, 'climb_delta_m' => $delta],
                );
            }
        }

        if ($option['max_grade_limit_pct'] !== null && !$option['exceeds_max_grade']) {
            $reasons[] = $this->sentence('within_grade_limit', 'No measured grade is over your {limit} limit.', [
                '{limit}' => $this->percent((float) $option['max_grade_limit_pct']),
            ], ['max_grade_pct' => $elevation['max_grade_pct'], 'max_grade_limit_pct' => $option['max_grade_limit_pct']]);
        }

        return $reasons;
    }

    private function preferenceReasons(array $option, array $fastest, bool $isFastest, RouteRequest $request): array
    {
        $route = $option['route'];
        $reasons = [];

        if ($request->avoidTolls && !$route->hasToll) {
            $reasons[] = $this->sentence('tolls_avoided', 'Avoids toll roads, as you asked.', [], ['avoid_tolls' => true, 'has_toll' => false]);
        } elseif (!$isFastest && !$route->hasToll && $fastest['route']->hasToll) {
            $reasons[] = $this->sentence('skips_fastest_tolls', 'Avoids the toll roads on the fastest route.', [], ['has_toll' => false, 'fastest_has_toll' => true]);
        }

        if ($request->avoidHighways && !$route->hasHighway && !in_array(RouteRanker::SCENIC, $option['labels'], true)) {
            $reasons[] = $this->sentence('highways_avoided', 'Stays off highways, as you asked.', [], ['avoid_highways' => true, 'has_highway' => false]);
        }

        if ($request->avoidFerries && !$route->hasFerry) {
            $reasons[] = $this->sentence('ferries_avoided', 'Takes no ferries, as you asked.', [], ['avoid_ferries' => true, 'has_ferry' => false]);
        }

        return $reasons;
    }

    /** Names the dimensions the route was planned with. Never claims the route clears anything. */
    private function limitReasons(array $vehicleLimits): array
    {
        $parts = [];

        foreach (self::LIMIT_NAMES as $key => $name) {
            if (isset($vehicleLimits[$key])) {
                $value = (float) $vehicleLimits[$key];
                $formatted = str_ends_with($key, '_kg') ? Units::formatWeight($value, $this->unitSystem) : Units::formatLength($value, $this->unitSystem);
                $parts[] = "{$name} ({$formatted})";
            }
        }

        if ($parts === []) {
            return [];
        }

        $list = count($parts) === 1 ? $parts[0] : implode(', ', array_slice($parts, 0, -1)) . ' and ' . end($parts);

        return [$this->sentence('vehicle_limits', "Planned with OpenStreetMap's road limits for your vehicle's {dimensions}.", ['{dimensions}' => $list], $vehicleLimits)];
    }

    private function warning(array $warning): array
    {
        $code = $warning['code'];
        $facts = array_diff_key($warning, ['code' => true]);

        return match ($code) {
            'provider' => $this->sentence($code, '{text}', ['{text}' => (string) $warning['text']], $facts),
            'time_restrictions' => $this->sentence($code, 'Part of this route has time-based access restrictions.', [], $facts),
            'uses_tolls' => $this->sentence($code, 'Uses toll roads, though you asked to avoid them.', [], $facts),
            'uses_highways' => $this->sentence($code, 'Uses highways, though you asked to avoid them.', [], $facts),
            'uses_ferry' => $this->sentence($code, 'Takes a ferry, though you asked to avoid ferries.', [], $facts),
            'elevation_unavailable' => $this->sentence($code, "Elevation data isn't available for this route, so its grades weren't checked.", [], $facts),
            'elevation_partial' => $this->sentence($code, "Elevation data covers only {coverage} of this route, so some grades weren't checked.", [
                '{coverage}' => $this->percent((float) $warning['coverage_pct']),
            ], $facts),
            'over_grade_limit' => $this->sentence($code, 'Its steepest grade, {max_grade}, is over your {limit} limit.', [
                '{max_grade}' => $this->percent((float) $warning['max_grade_pct']),
                '{limit}' => $this->percent((float) $warning['max_grade_limit_pct']),
            ], $facts),
            default => throw new \LogicException("No wording for route warning {$code}."),
        };
    }

    /** @param array<string, string> $values placeholder => formatted value */
    private function sentence(string $code, string $template, array $values, array $facts): array
    {
        return [
            'code' => $code,
            'text' => strtr($template, $values),
            'template' => $template,
            'values' => $values,
            'facts' => $facts,
        ];
    }

    /** 6%, 6.5%: one decimal at most, none when it is zero. */
    public static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') . '%';
    }
}
