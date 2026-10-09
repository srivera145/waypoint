<?php

namespace Keel\App\Services\Maps;

/**
 * The map screen's address and the trip it carries in its query string:
 *
 *   /map?q=Park+City                              opens with that search typed in
 *   /map?to=40.6461,-111.498&to_label=Park+City   opens routing there from the user's location
 *   ...&from=40.7608,-111.891&from_label=SLC      ...or from a chosen starting point
 *
 * Other screens send the user back to it (after switching vehicle, say) with the trip intact,
 * so a return path is accepted only when it is this screen's.
 */
class MapScreen
{
    public const PATH = '/map';

    public const LABEL_MAX = 200;

    /** $value when it is a path to the map screen, and null for anything else, so it cannot redirect off-site. */
    public static function returnPath(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('#^/map(\?[^\s\#\\\\]*)?$#', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * A "lat,lng" query value and its label as a place, or null when either is missing or wrong.
     *
     * @return ?array{lat: float, lng: float, label: string}
     */
    public static function place(mixed $point, mixed $label): ?array
    {
        if (!is_string($point) || !preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $point, $match)) {
            return null;
        }

        $lat = (float) $match[1];
        $lng = (float) $match[2];
        $label = is_string($label) ? trim((string) preg_replace('/\s+/u', ' ', $label)) : '';

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || $label === '' || mb_strlen($label) > self::LABEL_MAX) {
            return null;
        }

        return ['lat' => round($lat, 6), 'lng' => round($lng, 6), 'label' => $label];
    }
}
