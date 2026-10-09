<?php

namespace Keel\App\Services\Routing;

use Keel\App\Models\RoutePreference;
use Keel\App\Models\Vehicle;
use Keel\App\Models\VehicleType;

/**
 * What to route, in provider-neutral terms: two points, the vehicle's size in metric, and the
 * preferences that change how the engine picks roads. Preferences that only change how the
 * results are ranked (max_grade_pct) stay out, so they never split the cache.
 */
readonly class RouteRequest
{
    public function __construct(
        public float $originLat,
        public float $originLng,
        public float $destinationLat,
        public float $destinationLng,
        public string $vehicleType,
        public ?float $heightM = null,
        public ?float $widthM = null,
        /** Vehicle plus trailer. */
        public ?float $lengthM = null,
        /** Vehicle plus trailer: bridge weight limits are on the whole combination. */
        public ?float $weightKg = null,
        public ?float $axleLoadKg = null,
        public ?int $axleCount = null,
        public bool $avoidTolls = false,
        public bool $avoidHighways = false,
        public bool $avoidFerries = false,
        public bool $avoidMountains = false,
        public string $personality = RoutePreference::DEFAULT_PERSONALITY,
        public int $alternates = 2,
    ) {
    }

    /**
     * @param array{lat: float, lng: float} $origin
     * @param array{lat: float, lng: float} $destination
     * @param array $vehicle a vehicles row
     * @param array $preferences a route_preferences row, or RoutePreference::DEFAULTS
     */
    public static function forVehicle(array $origin, array $destination, array $vehicle, array $preferences): self
    {
        $type = (string) $vehicle['type'];
        $hasTrailer = (int) ($vehicle['has_trailer'] ?? 0) === 1;

        $weight = self::number($vehicle['weight_kg'] ?? null);
        if ($weight !== null && VehicleType::applies($type, 'trailer_weight_kg', $hasTrailer)) {
            $weight += self::number($vehicle['trailer_weight_kg'] ?? null) ?? 0.0;
        }

        $axleCount = $vehicle['axle_count'] ?? null;

        return new self(
            originLat: (float) $origin['lat'],
            originLng: (float) $origin['lng'],
            destinationLat: (float) $destination['lat'],
            destinationLng: (float) $destination['lng'],
            vehicleType: $type,
            heightM: self::number($vehicle['height_m'] ?? null),
            widthM: self::number($vehicle['width_m'] ?? null),
            lengthM: Vehicle::overallLength($vehicle),
            weightKg: $weight,
            axleLoadKg: self::number($vehicle['axle_load_kg'] ?? null),
            axleCount: $axleCount === null ? null : (int) $axleCount,
            avoidTolls: (int) ($preferences['avoid_tolls'] ?? 0) === 1,
            avoidHighways: (int) ($preferences['avoid_highways'] ?? 0) === 1,
            avoidFerries: (int) ($preferences['avoid_ferries'] ?? 0) === 1,
            avoidMountains: (int) ($preferences['avoid_mountains'] ?? 0) === 1,
            personality: (string) ($preferences['personality'] ?? RoutePreference::DEFAULT_PERSONALITY),
        );
    }

    /** Identical requests have identical fingerprints; any changed field changes it. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode(get_object_vars($this), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
