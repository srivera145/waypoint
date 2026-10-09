<?php

namespace Keel\App\Services\Routing;

/**
 * A routing engine. Valhalla is the one Waypoint runs today; a commercial truck-routing API
 * (HERE, TomTom) can replace it by implementing this and being named in ROUTING_PROVIDER.
 */
interface RoutingProvider
{
    /** A short stable name, stored with saved routes and part of the cache key. */
    public function name(): string;

    /**
     * The route plus any alternates, best first as the engine ranks them.
     *
     * @return list<RouteResult> never empty
     * @throws RoutingException when the engine is unreachable or finds no route
     */
    public function route(RouteRequest $request): array;

    /**
     * The vehicle dimensions this provider routes $request with, metric, keyed height_m,
     * width_m, length_m, weight_kg and axle_load_kg; only those it actually applies.
     *
     * @return array<string, float>
     */
    public function vehicleLimits(RouteRequest $request): array;

    /**
     * Heights along an encoded polyline (precision 6), sampled every $sampleSpacingM metres.
     *
     * @throws RoutingException when the engine is unreachable or the shape is refused
     */
    public function elevation(string $polyline, float $sampleSpacingM = 50.0): ElevationProfile;
}
