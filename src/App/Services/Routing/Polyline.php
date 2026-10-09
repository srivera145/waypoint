<?php

namespace Keel\App\Services\Routing;

/**
 * Google's encoded polyline format. Valhalla writes shapes at precision 6 (polyline6), not the
 * precision 5 Google uses, so 6 is the default here.
 */
class Polyline
{
    public const PRECISION = 6;

    /**
     * @return list<array{0: float, 1: float}> [lat, lng] pairs
     * @throws \InvalidArgumentException for a string that is not a valid polyline
     */
    public static function decode(string $encoded, int $precision = self::PRECISION): array
    {
        // A float factor, so 40000000 / factor is 40.0 rather than the int 40.
        $factor = 10.0 ** $precision;
        $length = strlen($encoded);
        $index = 0;
        $lat = 0;
        $lng = 0;
        $points = [];

        while ($index < $length) {
            $lat += self::nextValue($encoded, $index, $length);
            $lng += self::nextValue($encoded, $index, $length);
            $points[] = [$lat / $factor, $lng / $factor];
        }

        return $points;
    }

    /** @param iterable<array{0: float, 1: float}> $points [lat, lng] pairs */
    public static function encode(iterable $points, int $precision = self::PRECISION): string
    {
        $factor = 10 ** $precision;
        $previousLat = 0;
        $previousLng = 0;
        $encoded = '';

        foreach ($points as [$lat, $lng]) {
            $lat = (int) round($lat * $factor);
            $lng = (int) round($lng * $factor);
            $encoded .= self::encodeValue($lat - $previousLat) . self::encodeValue($lng - $previousLng);
            $previousLat = $lat;
            $previousLng = $lng;
        }

        return $encoded;
    }

    private static function nextValue(string $encoded, int &$index, int $length): int
    {
        $result = 0;
        $shift = 0;

        do {
            if ($index >= $length) {
                throw new \InvalidArgumentException('Polyline ends in the middle of a value.');
            }

            $byte = ord($encoded[$index++]) - 63;

            if ($byte < 0 || $byte > 63) {
                throw new \InvalidArgumentException('Polyline contains a character outside the encoding.');
            }

            $result |= ($byte & 0x1f) << $shift;
            $shift += 5;
        } while ($byte >= 0x20);

        return ($result & 1) ? ~($result >> 1) : ($result >> 1);
    }

    private static function encodeValue(int $value): string
    {
        $value = $value < 0 ? ~($value << 1) : $value << 1;
        $chunk = '';

        while ($value >= 0x20) {
            $chunk .= chr((0x20 | ($value & 0x1f)) + 63);
            $value >>= 5;
        }

        return $chunk . chr($value + 63);
    }
}
