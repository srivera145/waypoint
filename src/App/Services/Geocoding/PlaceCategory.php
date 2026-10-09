<?php

namespace Keel\App\Services\Geocoding;

/**
 * The handful of kinds of place Waypoint tells apart in search results, and how an
 * OpenStreetMap key and value map onto them. Anything without a better match is an address:
 * a house, a street, a town.
 */
class PlaceCategory
{
    public const FUEL = 'fuel';
    public const FOOD = 'food';
    public const LODGING = 'lodging';
    public const CAMPGROUND = 'campground';
    public const SHOP = 'shop';
    public const PARKING = 'parking';
    public const ATTRACTION = 'attraction';
    public const ADDRESS = 'address';

    private const CATEGORIES = [
        self::FUEL => ['label' => 'Fuel', 'emoji' => '⛽'],
        self::FOOD => ['label' => 'Food', 'emoji' => '🍴'],
        self::LODGING => ['label' => 'Lodging', 'emoji' => '🛏️'],
        self::CAMPGROUND => ['label' => 'Campground', 'emoji' => '⛺'],
        self::SHOP => ['label' => 'Shop', 'emoji' => '🛍️'],
        self::PARKING => ['label' => 'Parking', 'emoji' => '🅿️'],
        self::ATTRACTION => ['label' => 'Attraction', 'emoji' => '⭐'],
        self::ADDRESS => ['label' => 'Address', 'emoji' => '📍'],
    ];

    /** OSM key => value => category. A value of '*' matches every value of that key. */
    private const OSM = [
        'amenity' => [
            'fuel' => self::FUEL,
            'charging_station' => self::FUEL,
            'restaurant' => self::FOOD,
            'fast_food' => self::FOOD,
            'cafe' => self::FOOD,
            'food_court' => self::FOOD,
            'bar' => self::FOOD,
            'pub' => self::FOOD,
            'biergarten' => self::FOOD,
            'ice_cream' => self::FOOD,
            'parking' => self::PARKING,
            'parking_entrance' => self::PARKING,
            'parking_space' => self::PARKING,
            'motorcycle_parking' => self::PARKING,
            'marketplace' => self::SHOP,
        ],
        'shop' => ['*' => self::SHOP],
        'tourism' => [
            'hotel' => self::LODGING,
            'motel' => self::LODGING,
            'hostel' => self::LODGING,
            'guest_house' => self::LODGING,
            'chalet' => self::LODGING,
            'apartment' => self::LODGING,
            'alpine_hut' => self::LODGING,
            'wilderness_hut' => self::LODGING,
            'camp_site' => self::CAMPGROUND,
            'caravan_site' => self::CAMPGROUND,
            'camp_pitch' => self::CAMPGROUND,
            'attraction' => self::ATTRACTION,
            'museum' => self::ATTRACTION,
            'gallery' => self::ATTRACTION,
            'viewpoint' => self::ATTRACTION,
            'zoo' => self::ATTRACTION,
            'aquarium' => self::ATTRACTION,
            'theme_park' => self::ATTRACTION,
            'picnic_site' => self::ATTRACTION,
        ],
        'highway' => [
            // Motorway service areas: fuel is the reason to stop at one.
            'services' => self::FUEL,
            'rest_area' => self::PARKING,
        ],
        'leisure' => [
            'resort' => self::ATTRACTION,
            'park' => self::ATTRACTION,
            'nature_reserve' => self::ATTRACTION,
            'water_park' => self::ATTRACTION,
        ],
        'boundary' => [
            'national_park' => self::ATTRACTION,
            'protected_area' => self::ATTRACTION,
        ],
        'historic' => ['*' => self::ATTRACTION],
        'natural' => [
            'arch' => self::ATTRACTION,
            'peak' => self::ATTRACTION,
            'waterfall' => self::ATTRACTION,
            'hot_spring' => self::ATTRACTION,
            'geyser' => self::ATTRACTION,
            'cave_entrance' => self::ATTRACTION,
        ],
    ];

    /** @return array<string, array{label: string, emoji: string}> every category, keyed by value */
    public static function all(): array
    {
        return self::CATEGORIES;
    }

    public static function exists(mixed $category): bool
    {
        return is_string($category) && isset(self::CATEGORIES[$category]);
    }

    public static function fromOsm(?string $key, ?string $value): string
    {
        $values = self::OSM[(string) $key] ?? [];

        return $values[(string) $value] ?? $values['*'] ?? self::ADDRESS;
    }
}
