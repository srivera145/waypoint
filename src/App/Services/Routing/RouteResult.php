<?php

namespace Keel\App\Services\Routing;

/**
 * One route as a provider returned it. Every field comes from the provider's response; the
 * flags are whatever its map data says, so has_toll false means "no toll road in the data".
 */
readonly class RouteResult
{
    /**
     * @param string $polyline the whole route, encoded at precision 6
     * @param list<string> $warnings provider notes about this route, shown to the user
     * @param list<string> $violations hard restrictions the provider says this route breaks for
     *        the vehicle (a commercial truck API reports these). A route with any is impassable
     *        and is never returned to the user. Valhalla enforces its restrictions while
     *        routing instead, so its routes always have none.
     */
    public function __construct(
        public string $polyline,
        public float $distanceM,
        public float $durationS,
        public bool $hasToll = false,
        public bool $hasHighway = false,
        public bool $hasFerry = false,
        public bool $hasTimeRestrictions = false,
        public array $warnings = [],
        public array $violations = [],
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            polyline: (string) $data['polyline'],
            distanceM: (float) $data['distanceM'],
            durationS: (float) $data['durationS'],
            hasToll: (bool) ($data['hasToll'] ?? false),
            hasHighway: (bool) ($data['hasHighway'] ?? false),
            hasFerry: (bool) ($data['hasFerry'] ?? false),
            hasTimeRestrictions: (bool) ($data['hasTimeRestrictions'] ?? false),
            warnings: array_values(array_map('strval', $data['warnings'] ?? [])),
            violations: array_values(array_map('strval', $data['violations'] ?? [])),
        );
    }
}
