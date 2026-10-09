<?php

namespace Keel\App\Services\Routing;

use Keel\App\Models\RouteCache;
use Keel\App\Models\RoutePreference;
use Keel\App\Models\VehicleType;
use Keel\App\Services\Units;
use Keel\Core\Env;

/**
 * Plans the route options for one request: provider routes, elevation for each, then ranking
 * and explanations.
 *
 * What is cached is the expensive part, the provider's routes and their elevation stats, keyed
 * on everything that changes them. Ranking and wording run every time, so a new max grade or
 * unit setting takes effect at once instead of after the cache expires.
 */
class RoutePlanner
{
    public const CLEARANCE_NOTICE = 'Clearance data comes from OpenStreetMap and may be incomplete. Watch posted clearances.';

    private RoutingProvider $provider;

    /** @throws RoutingException when no provider is configured */
    public function __construct(?RoutingProvider $provider = null)
    {
        $this->provider = $provider ?? RoutingProviders::resolve();
    }

    /**
     * @param array{lat: float, lng: float} $origin
     * @param array{lat: float, lng: float} $destination
     * @param array $vehicle a vehicles row the user owns
     * @return array the response body for POST /api/routes
     * @throws RoutingException when the engine is down or there is no route; never a stand-in route
     */
    public function plan(int $userId, array $origin, array $destination, array $vehicle, string $unitSystem): array
    {
        $preferences = RoutePreference::effectiveForVehicle((int) $vehicle['id'], $userId);
        $request = RouteRequest::forVehicle($origin, $destination, $vehicle, $preferences);
        [$candidates, $cached] = $this->candidates($userId, $request);

        $options = (new RouteRanker())->rank($candidates, $preferences, $request->vehicleType);

        if ($options === []) {
            throw RoutingException::noRoute('Every route found breaks a restriction for this vehicle.');
        }

        $explainer = new RouteExplainer($unitSystem);
        $limits = $this->provider->vehicleLimits($request);
        $recommended = self::recommendedIndex($options, $request->vehicleType);
        $present = fn (array $option, int $index): array => $this->present($option, $options[0], count($options), $request, $limits, $explainer, $unitSystem)
            + ['recommended' => $index === $recommended];

        return [
            'provider' => $this->provider->name(),
            'cached' => $cached,
            'unit_system' => $unitSystem,
            'vehicle' => ['id' => (int) $vehicle['id'], 'name' => (string) $vehicle['name'], 'type' => $request->vehicleType],
            'notices' => VehicleType::isLarge($request->vehicleType) ? [self::CLEARANCE_NOTICE] : [],
            'options' => array_map($present, $options, array_keys($options)),
        ];
    }

    /**
     * The option to preselect: Vehicle-Friendly for an RV, truck or semi, where grades matter
     * most, and Fastest for everything else. Falls back to the fastest (always first) when no
     * option carries the label, as when there is one route or grades went unmeasured.
     *
     * @param list<array> $options ranked options, each with its labels
     */
    public static function recommendedIndex(array $options, string $vehicleType): int
    {
        $wanted = VehicleType::isLarge($vehicleType) ? RouteRanker::VEHICLE_FRIENDLY : RouteRanker::FASTEST;

        foreach ($options as $index => $option) {
            if (in_array($wanted, $option['labels'], true)) {
                return $index;
            }
        }

        return 0;
    }

    /** @return array{0: list<array{route: RouteResult, elevation: array}>, 1: bool} candidates, and whether they came from the cache */
    private function candidates(int $userId, RouteRequest $request): array
    {
        $key = hash('sha256', $this->provider->name() . '|' . $request->fingerprint());
        $cached = RouteCache::get($userId, $key);

        if ($cached !== null && isset($cached['candidates']) && is_array($cached['candidates'])) {
            return [array_map(static fn (array $candidate): array => [
                'route' => RouteResult::fromArray($candidate['route']),
                'elevation' => $candidate['elevation'],
            ], $cached['candidates']), true];
        }

        $analyzer = new ElevationAnalyzer($this->provider);
        $candidates = [];

        foreach ($this->provider->route($request) as $route) {
            $candidates[] = ['route' => $route, 'elevation' => $analyzer->analyze($route)];
        }

        if ($candidates === []) {
            throw RoutingException::noRoute();
        }

        // A failed elevation fetch is most likely a passing outage: not worth keeping.
        $reasons = array_map(static fn (array $candidate): ?string => $candidate['elevation']['unavailable_reason'], $candidates);

        if (!in_array(ElevationAnalyzer::UNAVAILABLE_FETCH_FAILED, $reasons, true)) {
            RouteCache::put($userId, $key, [
                'candidates' => array_map(static fn (array $candidate): array => [
                    'route' => $candidate['route']->toArray(),
                    'elevation' => $candidate['elevation'],
                ], $candidates),
            ], (int) Env::get('ROUTING_CACHE_TTL_MINUTES', 10));
        }

        return [$candidates, false];
    }

    private function present(array $option, array $fastest, int $count, RouteRequest $request, array $limits, RouteExplainer $explainer, string $unitSystem): array
    {
        $route = $option['route'];
        $elevation = $option['elevation'];
        $explanation = $explainer->explain($option, $fastest, $count, $request, $limits);
        $slower = $route->durationS - $fastest['route']->durationS;

        return [
            'label' => $option['labels'][0],
            'labels' => $option['labels'],
            'title' => implode(' · ', array_map(static fn (string $label): string => RouteRanker::LABELS[$label], $option['labels'])),
            'classification' => $option['classification'],
            'summary' => [
                'distance_m' => $route->distanceM,
                'duration_s' => $route->durationS,
                'has_toll' => $route->hasToll,
                'has_highway' => $route->hasHighway,
                'has_ferry' => $route->hasFerry,
                'has_time_restrictions' => $route->hasTimeRestrictions,
            ],
            // The same figures, worded in the user's units, for the comparison cards.
            'display' => [
                'distance' => Units::formatDistance($route->distanceM, $unitSystem),
                'duration' => Units::formatDuration($route->durationS),
                'vs_fastest' => $slower >= RouteExplainer::SAME_TIME_S ? '+' . Units::formatDuration($slower) : null,
                'max_grade' => $elevation['available'] ? RouteExplainer::percent((float) $elevation['max_grade_pct']) : null,
                'total_climb' => $elevation['available'] ? Units::formatElevation((float) $elevation['total_climb_m'], $unitSystem) : null,
                'highest' => $elevation['available'] ? Units::formatElevation((float) $elevation['max_elevation_m'], $unitSystem) : null,
                'lowest' => $elevation['available'] ? Units::formatElevation((float) $elevation['min_elevation_m'], $unitSystem) : null,
            ],
            'polyline' => $route->polyline,
            'polyline_precision' => Polyline::PRECISION,
            'elevation' => $elevation,
            'ranking' => ['grade_penalty_s' => $option['grade_penalty_s'], 'score_s' => $option['score_s']],
            'warnings' => array_column($explanation['warnings'], 'text'),
            'explanation' => $explanation['text'],
            'reasons' => array_map(static fn (array $reason): array => ['code' => $reason['code'], 'text' => $reason['text']], $explanation['reasons']),
        ];
    }
}
