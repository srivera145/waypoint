<?php

namespace Keel\App\Models;

/**
 * The vehicle types Waypoint routes for: their labels, default dimensions, and which
 * dimension fields apply to each. Not a table — the list matches the ENUM on vehicles.type.
 *
 * Defaults are metric (metres, kilograms). length_m is the vehicle alone; a trailer's length
 * and weight are separate, so the overall length of a combination is the two added together.
 *
 * A type's trailer mode says whether the trailer fields apply:
 *   none     — never (a bicycle, a straight truck: a truck with a trailer is a truck_trailer)
 *   optional — only when has_trailer is on (a pickup towing a camper)
 *   required — always (truck_trailer)
 */
class VehicleType
{
    public const TRAILER_NONE = 'none';
    public const TRAILER_OPTIONAL = 'optional';
    public const TRAILER_REQUIRED = 'required';

    /** Every dimension column on vehicles, in form order, with how its value is entered. */
    public const FIELDS = [
        'length_m' => ['input' => 'length', 'label' => 'Length', 'kind' => 'length'],
        'width_m' => ['input' => 'width', 'label' => 'Width', 'kind' => 'length'],
        'height_m' => ['input' => 'height', 'label' => 'Height', 'kind' => 'length'],
        'weight_kg' => ['input' => 'weight', 'label' => 'Weight', 'kind' => 'weight'],
        'axle_count' => ['input' => 'axle_count', 'label' => 'Axles', 'kind' => 'count'],
        'axle_load_kg' => ['input' => 'axle_load', 'label' => 'Heaviest axle load', 'kind' => 'weight'],
        'trailer_length_m' => ['input' => 'trailer_length', 'label' => 'Trailer length', 'kind' => 'length'],
        'trailer_weight_kg' => ['input' => 'trailer_weight', 'label' => 'Trailer weight', 'kind' => 'weight'],
    ];

    public const TRAILER_FIELDS = ['trailer_length_m', 'trailer_weight_kg'];

    private const BASIC = ['length_m', 'width_m', 'height_m', 'weight_kg'];
    private const HEAVY = ['length_m', 'width_m', 'height_m', 'weight_kg', 'axle_count', 'axle_load_kg'];

    /** A towed trailer for the optional types: a 20 ft travel trailer at about 5,000 lbs. */
    private const TOWED_TRAILER = ['trailer_length_m' => 6.1, 'trailer_weight_kg' => 2300];

    private const TYPES = [
        'car' => [
            'label' => 'Car',
            'emoji' => '🚗',
            'description' => 'Sedans, hatchbacks and wagons',
            'fields' => self::BASIC,
            'trailer' => self::TRAILER_OPTIONAL,
            'defaults' => ['length_m' => 4.5, 'width_m' => 1.8, 'height_m' => 1.5, 'weight_kg' => 1500],
        ],
        'suv' => [
            'label' => 'SUV',
            'emoji' => '🚙',
            'description' => 'SUVs, crossovers and minivans',
            'fields' => self::BASIC,
            'trailer' => self::TRAILER_OPTIONAL,
            'defaults' => ['length_m' => 4.9, 'width_m' => 2.0, 'height_m' => 1.8, 'weight_kg' => 2200],
        ],
        'pickup' => [
            'label' => 'Pickup',
            'emoji' => '🛻',
            'description' => 'Pickup trucks, with or without a camper',
            'fields' => self::BASIC,
            'trailer' => self::TRAILER_OPTIONAL,
            'defaults' => ['length_m' => 5.9, 'width_m' => 2.0, 'height_m' => 1.95, 'weight_kg' => 2600],
        ],
        'rv' => [
            'label' => 'RV',
            'emoji' => '🚐',
            'description' => 'Motorhomes and camper vans',
            'fields' => self::HEAVY,
            'trailer' => self::TRAILER_OPTIONAL,
            'defaults' => ['length_m' => 10.7, 'width_m' => 2.6, 'height_m' => 3.8, 'weight_kg' => 10000, 'axle_count' => 2, 'axle_load_kg' => 6500],
        ],
        'truck' => [
            'label' => 'Truck',
            'emoji' => '🚚',
            'description' => 'Box trucks and straight trucks',
            'fields' => self::HEAVY,
            'trailer' => self::TRAILER_NONE,
            'defaults' => ['length_m' => 9.1, 'width_m' => 2.6, 'height_m' => 3.8, 'weight_kg' => 11800, 'axle_count' => 2, 'axle_load_kg' => 8000],
        ],
        'truck_trailer' => [
            'label' => 'Truck and trailer',
            'emoji' => '🚛',
            'description' => 'Semis and tractor-trailers',
            'fields' => self::HEAVY,
            'trailer' => self::TRAILER_REQUIRED,
            'defaults' => [
                'length_m' => 6.5, 'width_m' => 2.6, 'height_m' => 4.1, 'weight_kg' => 8200, 'axle_count' => 5, 'axle_load_kg' => 7700,
                'trailer_length_m' => 16.2, 'trailer_weight_kg' => 25000,
            ],
        ],
        'motorcycle' => [
            'label' => 'Motorcycle',
            'emoji' => '🏍️',
            'description' => 'Motorcycles and scooters',
            'fields' => self::BASIC,
            'trailer' => self::TRAILER_NONE,
            'defaults' => ['length_m' => 2.2, 'width_m' => 0.9, 'height_m' => 1.2, 'weight_kg' => 250],
        ],
        'bicycle' => [
            'label' => 'Bicycle',
            'emoji' => '🚲',
            'description' => 'Bike lanes and quiet roads',
            'fields' => [],
            'trailer' => self::TRAILER_NONE,
            'defaults' => [],
        ],
        'walking' => [
            'label' => 'Walking',
            'emoji' => '🚶',
            'description' => 'Sidewalks and footpaths',
            'fields' => [],
            'trailer' => self::TRAILER_NONE,
            'defaults' => [],
        ],
    ];

    public const DEFAULT_TYPE = 'car';

    /** @return array<string, array> every type keyed by its value, in display order */
    public static function all(): array
    {
        return self::TYPES;
    }

    public static function exists(mixed $type): bool
    {
        return is_string($type) && isset(self::TYPES[$type]);
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? ucfirst($type);
    }

    public static function emoji(string $type): string
    {
        return self::TYPES[$type]['emoji'] ?? '🚗';
    }

    public static function trailerMode(string $type): string
    {
        return self::TYPES[$type]['trailer'] ?? self::TRAILER_NONE;
    }

    /**
     * The dimension fields that apply to a type, in form order. Trailer fields are included
     * for a truck_trailer always, and for an optional-trailer type only when it has one.
     *
     * @return list<string>
     */
    public static function fields(string $type, bool $hasTrailer = false): array
    {
        $fields = self::TYPES[$type]['fields'] ?? [];
        $trailer = self::trailerMode($type);

        if ($trailer === self::TRAILER_REQUIRED || ($trailer === self::TRAILER_OPTIONAL && $hasTrailer)) {
            $fields = array_merge($fields, self::TRAILER_FIELDS);
        }

        return $fields;
    }

    public static function applies(string $type, string $field, bool $hasTrailer = false): bool
    {
        return in_array($field, self::fields($type, $hasTrailer), true);
    }

    /**
     * Default dimensions for a type, metric. Includes trailer defaults for a type whose
     * trailer is required or optional, so switching a trailer on has something to fill in.
     *
     * @return array<string, int|float>
     */
    public static function defaults(string $type): array
    {
        $defaults = self::TYPES[$type]['defaults'] ?? [];

        if (self::trailerMode($type) === self::TRAILER_OPTIONAL) {
            $defaults += self::TOWED_TRAILER;
        }

        return $defaults;
    }
}
