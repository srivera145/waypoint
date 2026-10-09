<?php

namespace Keel\App\Services\Routing;

/** Heights sampled along a route. */
readonly class ElevationProfile
{
    /**
     * @param list<array{0: float, 1: ?float}> $samples [distance along the route in metres,
     *        height in metres or null where the provider has no elevation data]
     */
    public function __construct(
        public array $samples,
        public float $sampleSpacingM,
    ) {
    }
}
