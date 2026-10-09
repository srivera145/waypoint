<?php

namespace Keel\App\Services\Routing;

use Keel\App\Models\VehicleType;

/**
 * Labels and classifies the routes a provider found.
 *
 *   Fastest           shortest drive time.
 *   Vehicle-Friendly  lowest score: drive time plus a grade penalty in seconds. When the fastest
 *                     route also scores best it carries both labels, rather than handing the
 *                     label to a route that is worse on both counts. No route gets it unless
 *                     every route's grades were measured.
 *   Scenic / Flat / Alternate
 *                     the rest. Scenic stays off highways where the fastest route uses them;
 *                     Flat climbs least, by a real margin; anything else is an Alternate.
 *
 * Classifications:
 *   IMPASSABLE  the provider says it breaks a hard vehicle restriction. Never returned.
 *   RESTRICTED  over the user's max grade, or carries a warning.
 *   SUBOPTIMAL  another route is at least as fast and scores at least as well.
 *   OK          none of the above.
 *
 * Each option comes back as: route, elevation, labels, classification, warnings (codes with
 * the facts behind them, for RouteExplainer to word), exceeds_max_grade, grade_penalty_s, score_s.
 */
class RouteRanker
{
    public const FASTEST = 'fastest';
    public const VEHICLE_FRIENDLY = 'vehicle_friendly';
    public const SCENIC = 'scenic';
    public const FLAT = 'flat';
    public const ALTERNATE = 'alternate';

    public const LABELS = [
        self::FASTEST => 'Fastest',
        self::VEHICLE_FRIENDLY => 'Vehicle-Friendly',
        self::SCENIC => 'Scenic',
        self::FLAT => 'Flat',
        self::ALTERNATE => 'Alternate',
    ];

    public const IMPASSABLE = 'IMPASSABLE';
    public const RESTRICTED = 'RESTRICTED';
    public const SUBOPTIMAL = 'SUBOPTIMAL';
    public const OK = 'OK';

    /** The grade penalty, counted as seconds of driving. */
    private const CLIMB_S_PER_M = 1.0;
    private const SUSTAINED_GRADE_S = 120.0;
    private const STEEP_S_PER_PCT = 60.0;

    /** Big vehicles feel grades more: double the penalty. */
    private const LARGE_VEHICLE_FACTOR = 2.0;

    /** avoid_mountains, the Flat personality, or a route over max_grade_pct: five times the penalty. */
    private const STRONG_FACTOR = 5.0;

    /** A third route is Flat when it climbs at least this much less than the fastest one. */
    private const FLAT_MIN_SAVING_M = 50.0;
    private const FLAT_MIN_SAVING_SHARE = 0.2;

    private const MAX_OPTIONS = 3;

    /**
     * @param list<array{route: RouteResult, elevation: array}> $candidates in provider order
     * @param array $preferences a route_preferences row, or RoutePreference::DEFAULTS
     * @return list<array> at most three options: Fastest first, then Vehicle-Friendly, then the rest
     */
    public function rank(array $candidates, array $preferences, string $vehicleType): array
    {
        $options = [];

        foreach ($candidates as $candidate) {
            if ($this->classifyHard($candidate['route']) === self::IMPASSABLE) {
                continue;
            }

            $options[] = $this->score($candidate['route'], $candidate['elevation'], $preferences, $vehicleType);
        }

        if ($options === []) {
            return [];
        }

        $fastest = $this->lowest($options, 'duration');

        // "Easiest on your vehicle" compares grades, so it needs at least two routes and every
        // one of them measured: a route whose grades are unknown might be the easier one.
        $measured = array_filter($options, static fn (array $option): bool => $option['elevation']['available']);
        $friendly = count($options) < 2 || count($measured) < count($options) ? null : $this->lowest($options, 'score_s', $fastest);

        $ranked = [];
        $options[$fastest]['labels'] = $friendly === $fastest ? [self::FASTEST, self::VEHICLE_FRIENDLY] : [self::FASTEST];
        $ranked[] = $options[$fastest];

        if ($friendly !== null && $friendly !== $fastest) {
            $options[$friendly]['labels'] = [self::VEHICLE_FRIENDLY];
            $ranked[] = $options[$friendly];
        }

        $taken = [$fastest => true];
        if ($friendly !== null) {
            $taken[$friendly] = true;
        }

        $rest = array_diff_key($options, $taken);
        uasort($rest, static fn (array $a, array $b): int => $a['score_s'] <=> $b['score_s']);

        foreach ($rest as $option) {
            if (count($ranked) >= self::MAX_OPTIONS) {
                break;
            }

            $option['labels'] = [$this->otherLabel($option, $options[$fastest], $options)];
            $ranked[] = $option;
        }

        foreach ($ranked as $index => $option) {
            $ranked[$index]['classification'] = $this->classify($option, $options);
        }

        return $ranked;
    }

    /** IMPASSABLE when the provider reports a hard restriction the route breaks; null otherwise. */
    public function classifyHard(RouteResult $route): ?string
    {
        return $route->violations === [] ? null : self::IMPASSABLE;
    }

    private function score(RouteResult $route, array $elevation, array $preferences, string $vehicleType): array
    {
        $maxGrade = $preferences['max_grade_pct'] ?? null;
        $maxGrade = $maxGrade === null || $maxGrade === '' ? null : (float) $maxGrade;
        $exceeds = $maxGrade !== null && $elevation['available'] && (float) $elevation['max_grade_pct'] > $maxGrade;

        $penalty = 0.0;

        if ($elevation['available']) {
            $strong = (int) ($preferences['avoid_mountains'] ?? 0) === 1
                || ($preferences['personality'] ?? null) === 'flat'
                || $exceeds;

            $factor = (VehicleType::isLarge($vehicleType) ? self::LARGE_VEHICLE_FACTOR : 1.0) * ($strong ? self::STRONG_FACTOR : 1.0);

            $penalty = $factor * (
                (float) $elevation['total_climb_m'] * self::CLIMB_S_PER_M
                + (int) $elevation['sustained_grade_count'] * self::SUSTAINED_GRADE_S
                + max(0.0, (float) $elevation['max_grade_pct'] - ElevationAnalyzer::SUSTAINED_GRADE_PCT) * self::STEEP_S_PER_PCT
            );
        }

        return [
            'route' => $route,
            'elevation' => $elevation,
            'duration' => $route->durationS,
            'labels' => [],
            'classification' => self::OK,
            'warnings' => $this->warnings($route, $elevation, $preferences, $exceeds, $maxGrade),
            'exceeds_max_grade' => $exceeds,
            'max_grade_limit_pct' => $maxGrade,
            'grade_penalty_s' => round($penalty, 1),
            'score_s' => round($route->durationS + $penalty, 1),
        ];
    }

    /** @return list<array{code: string}> each with the facts it rests on */
    private function warnings(RouteResult $route, array $elevation, array $preferences, bool $exceeds, ?float $maxGrade): array
    {
        $warnings = [];

        foreach ($route->warnings as $text) {
            $warnings[] = ['code' => 'provider', 'text' => $text];
        }

        if ($route->hasTimeRestrictions) {
            $warnings[] = ['code' => 'time_restrictions', 'has_time_restrictions' => true];
        }

        $asked = [
            'avoid_tolls' => ['uses_tolls', 'has_toll', $route->hasToll],
            'avoid_highways' => ['uses_highways', 'has_highway', $route->hasHighway],
            'avoid_ferries' => ['uses_ferry', 'has_ferry', $route->hasFerry],
        ];

        foreach ($asked as $preference => [$code, $fact, $uses]) {
            if ((int) ($preferences[$preference] ?? 0) === 1 && $uses) {
                $warnings[] = ['code' => $code, $preference => true, $fact => true];
            }
        }

        if (!$elevation['available']) {
            $warnings[] = ['code' => 'elevation_unavailable', 'available' => false, 'unavailable_reason' => $elevation['unavailable_reason']];
        } elseif ((float) $elevation['coverage_pct'] < ElevationAnalyzer::MIN_COVERAGE_PCT) {
            $warnings[] = ['code' => 'elevation_partial', 'coverage_pct' => (float) $elevation['coverage_pct']];
        }

        if ($exceeds) {
            $warnings[] = ['code' => 'over_grade_limit', 'max_grade_pct' => (float) $elevation['max_grade_pct'], 'max_grade_limit_pct' => $maxGrade];
        }

        return $warnings;
    }

    private function otherLabel(array $option, array $fastest, array $all): string
    {
        if (!$option['route']->hasHighway && $fastest['route']->hasHighway) {
            return self::SCENIC;
        }

        $climb = $option['elevation']['total_climb_m'];
        $fastestClimb = $fastest['elevation']['total_climb_m'];

        if ($climb !== null && $fastestClimb !== null) {
            $leastClimb = min(array_map(
                static fn (array $other): float => (float) ($other['elevation']['total_climb_m'] ?? INF),
                $all
            ));
            $saving = $fastestClimb - $climb;

            if ($climb <= $leastClimb && $saving >= max(self::FLAT_MIN_SAVING_M, $fastestClimb * self::FLAT_MIN_SAVING_SHARE)) {
                return self::FLAT;
            }
        }

        return self::ALTERNATE;
    }

    private function classify(array $option, array $all): string
    {
        if ($option['exceeds_max_grade'] || $option['warnings'] !== []) {
            return self::RESTRICTED;
        }

        // Only a route with nothing against it can make another one pointless.
        foreach ($all as $other) {
            if ($other['route'] === $option['route'] || $other['warnings'] !== [] || $other['exceeds_max_grade']) {
                continue;
            }

            $noSlower = $other['duration'] <= $option['duration'];
            $noWorse = $other['score_s'] <= $option['score_s'];
            $better = $other['duration'] < $option['duration'] || $other['score_s'] < $option['score_s'];

            if ($noSlower && $noWorse && $better) {
                return self::SUBOPTIMAL;
            }
        }

        return self::OK;
    }

    /** The key of the option lowest on $field; ties go to $preferred, then to provider order. */
    private function lowest(array $options, string $field, ?int $preferred = null): int
    {
        $best = null;

        foreach ($options as $key => $option) {
            if ($best === null || $option[$field] < $options[$best][$field]
                || ($option[$field] == $options[$best][$field] && $key === $preferred)) {
                $best = $key;
            }
        }

        return (int) $best;
    }
}
