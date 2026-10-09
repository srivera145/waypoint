<?php

declare(strict_types=1);

namespace Tests\Support;

use Keel\App\Services\Routing\ElevationProfile;
use Keel\App\Services\Routing\Polyline;
use Keel\App\Services\Routing\RouteRequest;
use Keel\App\Services\Routing\RouteResult;
use Keel\App\Services\Routing\RoutingException;
use Keel\App\Services\Routing\RoutingProvider;

/**
 * A provider that returns canned routes and elevation profiles, records what it was asked, and
 * can be made to fail like a downed or routeless engine.
 */
class FakeRoutingProvider implements RoutingProvider
{
    /** @var list<RouteRequest> */
    public array $routeRequests = [];

    public int $elevationCalls = 0;

    /** @var array<string, ElevationProfile> polyline => profile */
    private array $profiles = [];

    /** @param list<RouteResult> $routes */
    public function __construct(
        private array $routes = [],
        private ?RoutingException $routeFailure = null,
        private ?RoutingException $elevationFailure = null,
    ) {
    }

    public static function down(): self
    {
        return new self([], RoutingException::unavailable(), RoutingException::unavailable());
    }

    /** A route along a straight line, with its elevation profile at 50 m spacing. */
    public function addRoute(float $durationS, float $distanceM, callable $heightAt, array $flags = []): RouteResult
    {
        $index = count($this->routes);
        $polyline = Polyline::encode([[40.76 + $index * 0.001, -111.89], [40.65, -111.50 - $index * 0.001]]);
        $route = new RouteResult($polyline, $distanceM, $durationS, ...$flags);

        $samples = [];
        for ($distance = 0.0; $distance <= $distanceM; $distance += 50.0) {
            $samples[] = [$distance, $heightAt($distance)];
        }

        $this->routes[] = $route;
        $this->profiles[$polyline] = new ElevationProfile($samples, 50.0);

        return $route;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function route(RouteRequest $request): array
    {
        $this->routeRequests[] = $request;

        if ($this->routeFailure !== null) {
            throw $this->routeFailure;
        }

        return $this->routes;
    }

    public function vehicleLimits(RouteRequest $request): array
    {
        return array_filter(['height_m' => $request->heightM, 'weight_kg' => $request->weightKg], static fn (?float $value): bool => $value !== null);
    }

    public function elevation(string $polyline, float $sampleSpacingM = 50.0): ElevationProfile
    {
        $this->elevationCalls++;

        if ($this->elevationFailure !== null) {
            throw $this->elevationFailure;
        }

        return $this->profiles[$polyline] ?? throw RoutingException::unavailable();
    }
}
