<?php

namespace Keel\App\Services\Routing;

/**
 * Turns a route's elevation profile into the numbers ranking and explanations use.
 *
 * Elevation tiles are about 30 m per pixel and read the ground, not the road deck, so sample-
 * to-sample differences are noisy. Heights are smoothed before climbing is totalled, and every
 * grade is rise over run across GRADE_WINDOW_M rather than between two neighbouring samples.
 * Bridges and tunnels still read as the ground under or over them.
 *
 * stats() returns:
 *   available               false when there is too little elevation data to say anything
 *   unavailable_reason      'no_data' (the provider has no heights here) or 'fetch_failed'
 *   sample_spacing_m        metres between samples
 *   coverage_pct            share of samples that had a height
 *   max_elevation_m, min_elevation_m
 *   total_climb_m, total_descent_m
 *   max_grade_pct           steepest grade either way, as a positive number
 *   max_uphill_grade_pct, max_downhill_grade_pct
 *   sustained_grade_count   stretches steeper than SUSTAINED_GRADE_PCT for over SUSTAINED_MIN_LENGTH_M
 *   sustained_grades        each one: start_m, length_m, avg_grade_pct, direction ('up' or 'down')
 *   profile                 the smoothed heights as [distance_m, height_m] pairs, at most
 *                           PROFILE_POINTS of them evenly spaced, for drawing; [] when unavailable
 */
class ElevationAnalyzer
{
    public const SUSTAINED_GRADE_PCT = 5.0;
    public const SUSTAINED_MIN_LENGTH_M = 1000.0;

    /** Below this share of samples with a height, a route carries a warning. */
    public const MIN_COVERAGE_PCT = 90.0;

    public const UNAVAILABLE_NO_DATA = 'no_data';
    public const UNAVAILABLE_FETCH_FAILED = 'fetch_failed';

    /** Requested spacing; long routes get wider spacing so no profile exceeds MAX_SAMPLES. */
    private const SAMPLE_SPACING_M = 50.0;
    private const MAX_SAMPLES = 5000;

    private const GRADE_WINDOW_M = 400.0;

    /** Shrinks to a quarter of the route on short routes, so a 150 m route is not averaged flat. */
    private const SMOOTHING_WINDOW_M = 300.0;

    private const CLIMB_THRESHOLD_M = 2.0;

    /** Enough points for a chart the width of a phone, few enough to send with every option. */
    public const PROFILE_POINTS = 120;

    public function __construct(private RoutingProvider $provider)
    {
    }

    /** Samples the route through the provider. Elevation that cannot be fetched comes back unavailable. */
    public function analyze(RouteResult $route): array
    {
        $spacing = round(max(self::SAMPLE_SPACING_M, $route->distanceM / self::MAX_SAMPLES), 1);

        try {
            $profile = $this->provider->elevation($route->polyline, $spacing);
        } catch (RoutingException) {
            return self::unavailable(self::UNAVAILABLE_FETCH_FAILED, $spacing, null);
        }

        return $this->stats($profile);
    }

    public function stats(ElevationProfile $profile): array
    {
        $distances = [];
        $heights = [];

        foreach ($profile->samples as [$distance, $height]) {
            if ($height !== null) {
                $distances[] = (float) $distance;
                $heights[] = (float) $height;
            }
        }

        $total = count($profile->samples);
        $count = count($heights);
        $coverage = $total === 0 ? 0.0 : round($count / $total * 100, 1);

        if ($count < 2 || $distances[$count - 1] <= $distances[0]) {
            return self::unavailable(self::UNAVAILABLE_NO_DATA, $profile->sampleSpacingM, $coverage);
        }

        $smoothed = $this->smooth($distances, $heights, min(self::SMOOTHING_WINDOW_M, ($distances[$count - 1] - $distances[0]) / 4));

        // A change counts once the road has moved CLIMB_THRESHOLD_M from the last counted
        // height, so what smoothing leaves of the noise never adds up. A steady climb still
        // counts in full; only the last few metres of one can go uncounted.
        $climb = 0.0;
        $descent = 0.0;
        $reference = $smoothed[0];
        foreach ($smoothed as $height) {
            if ($height - $reference >= self::CLIMB_THRESHOLD_M) {
                $climb += $height - $reference;
                $reference = $height;
            } elseif ($reference - $height >= self::CLIMB_THRESHOLD_M) {
                $descent += $reference - $height;
                $reference = $height;
            }
        }

        $windows = $this->gradeWindows($distances, $smoothed);
        $grades = array_column($windows, 2);
        $maxUp = max(0.0, max($grades));
        $maxDown = max(0.0, -min($grades));
        $sustained = $this->sustainedGrades($windows, $distances, $smoothed);

        return [
            'available' => true,
            'unavailable_reason' => null,
            'sample_spacing_m' => $profile->sampleSpacingM,
            'coverage_pct' => $coverage,
            'max_elevation_m' => round(max($heights), 1),
            'min_elevation_m' => round(min($heights), 1),
            'total_climb_m' => round($climb),
            'total_descent_m' => round($descent),
            'max_grade_pct' => round(max($maxUp, $maxDown), 1),
            'max_uphill_grade_pct' => round($maxUp, 1),
            'max_downhill_grade_pct' => round($maxDown, 1),
            'sustained_grade_count' => count($sustained),
            'sustained_grades' => $sustained,
            'profile' => $this->profilePoints($distances, $smoothed),
        ];
    }

    public static function unavailable(string $reason, float $sampleSpacingM, ?float $coveragePct): array
    {
        return [
            'available' => false,
            'unavailable_reason' => $reason,
            'sample_spacing_m' => $sampleSpacingM,
            'coverage_pct' => $coveragePct,
            'max_elevation_m' => null,
            'min_elevation_m' => null,
            'total_climb_m' => null,
            'total_descent_m' => null,
            'max_grade_pct' => null,
            'max_uphill_grade_pct' => null,
            'max_downhill_grade_pct' => null,
            'sustained_grade_count' => null,
            'sustained_grades' => [],
            'profile' => [],
        ];
    }

    /**
     * Centred moving average over $windowM of distance. Near either end the window narrows to
     * stay centred, so the first and last heights are the real ones rather than being pulled
     * toward the middle of the route.
     *
     * @param list<float> $distances ascending
     * @param list<float> $heights
     * @return list<float>
     */
    private function smooth(array $distances, array $heights, float $windowM): array
    {
        $count = count($heights);
        $first = $distances[0];
        $last = $distances[$count - 1];
        $prefix = [0.0];

        foreach ($heights as $index => $height) {
            $prefix[$index + 1] = $prefix[$index] + $height;
        }

        $smoothed = [];

        for ($i = 0; $i < $count; $i++) {
            $half = min($windowM / 2, $distances[$i] - $first, $last - $distances[$i]);
            $low = $this->firstAbove($distances, $distances[$i] - $half - 1e-6);
            $high = $this->firstAbove($distances, $distances[$i] + $half + 1e-6) - 1;

            $smoothed[] = ($prefix[$high + 1] - $prefix[$low]) / ($high - $low + 1);
        }

        return $smoothed;
    }

    /**
     * The smoothed heights at PROFILE_POINTS evenly spaced distances, read off the samples by
     * linear interpolation. A short route with fewer samples than that keeps every sample.
     *
     * @return list<array{0: int, 1: float}>
     */
    private function profilePoints(array $distances, array $smoothed): array
    {
        $count = count($distances);

        if ($count <= self::PROFILE_POINTS) {
            return array_map(static fn (float $distance, float $height): array => [(int) round($distance), round($height, 1)], $distances, $smoothed);
        }

        $first = $distances[0];
        $step = ($distances[$count - 1] - $first) / (self::PROFILE_POINTS - 1);
        $points = [];
        $j = 0;

        for ($i = 0; $i < self::PROFILE_POINTS; $i++) {
            $target = $i === self::PROFILE_POINTS - 1 ? $distances[$count - 1] : $first + $i * $step;

            while ($j < $count - 2 && $distances[$j + 1] < $target) {
                $j++;
            }

            $span = $distances[$j + 1] - $distances[$j];
            $share = $span > 0 ? min(1.0, max(0.0, ($target - $distances[$j]) / $span)) : 0.0;
            $points[] = [(int) round($target), round($smoothed[$j] + ($smoothed[$j + 1] - $smoothed[$j]) * $share, 1)];
        }

        return $points;
    }

    /** Index of the first distance greater than $target (binary search), or the count if none is. */
    private function firstAbove(array $distances, float $target): int
    {
        $low = 0;
        $high = count($distances);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($distances[$middle] <= $target) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * The grade from each sample to the first sample at least GRADE_WINDOW_M further on. A
     * route shorter than one window gets a single grade, end to end.
     *
     * @return list<array{0: int, 1: int, 2: float}> [start index, end index, grade %]
     */
    private function gradeWindows(array $distances, array $smoothed): array
    {
        $count = count($distances);
        $windows = [];
        $end = 1;

        for ($start = 0; $start < $count; $start++) {
            $end = max($end, $start + 1);

            while ($end < $count && $distances[$end] - $distances[$start] < self::GRADE_WINDOW_M) {
                $end++;
            }

            if ($end >= $count) {
                break;
            }

            $windows[] = [$start, $end, $this->grade($distances, $smoothed, $start, $end)];
        }

        if ($windows === []) {
            $windows[] = [0, $count - 1, $this->grade($distances, $smoothed, 0, $count - 1)];
        }

        return $windows;
    }

    /**
     * Runs of consecutive windows all steeper than SUSTAINED_GRADE_PCT in the same direction,
     * kept when the stretch they cover is longer than SUSTAINED_MIN_LENGTH_M.
     */
    private function sustainedGrades(array $windows, array $distances, array $smoothed): array
    {
        $found = [];
        $run = null;

        $close = function (?array $run) use (&$found, $distances, $smoothed): void {
            if ($run === null) {
                return;
            }

            $length = $distances[$run['end']] - $distances[$run['start']];

            if ($length > self::SUSTAINED_MIN_LENGTH_M) {
                $found[] = [
                    'start_m' => round($distances[$run['start']]),
                    'length_m' => round($length),
                    'avg_grade_pct' => round(abs($this->grade($distances, $smoothed, $run['start'], $run['end'])), 1),
                    'direction' => $run['direction'],
                ];
            }
        };

        foreach ($windows as [$start, $end, $grade]) {
            $direction = match (true) {
                $grade > self::SUSTAINED_GRADE_PCT => 'up',
                $grade < -self::SUSTAINED_GRADE_PCT => 'down',
                default => null,
            };

            if ($run !== null && $direction === $run['direction'] && $start === $run['last_start'] + 1) {
                $run['end'] = $end;
                $run['last_start'] = $start;
                continue;
            }

            $close($run);
            $run = $direction === null ? null : ['direction' => $direction, 'start' => $start, 'end' => $end, 'last_start' => $start];
        }

        $close($run);

        return $found;
    }

    private function grade(array $distances, array $smoothed, int $start, int $end): float
    {
        $run = $distances[$end] - $distances[$start];

        return $run <= 0 ? 0.0 : ($smoothed[$end] - $smoothed[$start]) / $run * 100;
    }
}
