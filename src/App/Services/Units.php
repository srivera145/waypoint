<?php

namespace Keel\App\Services;

/**
 * Converts between what a person types or reads and what Waypoint stores.
 *
 * Storage is always metric: metres and kilograms. A user's unit_system only decides how a
 * value is displayed and which unit a bare number ("12.5") is read in. Display precision
 * (nearest inch or centimetre, nearest lb or kg) is coarser than storage precision
 * (millimetres, hundredths of a kilogram), so a value survives display -> parse -> store
 * -> display unchanged.
 */
class Units
{
    public const IMPERIAL = 'imperial';
    public const METRIC = 'metric';
    public const SYSTEMS = [self::IMPERIAL, self::METRIC];

    public const METRES_PER_FOOT = 0.3048;
    public const METRES_PER_INCH = 0.0254;
    public const KG_PER_POUND = 0.45359237;
    public const KG_PER_SHORT_TON = 907.18474;

    private const NUMBER = '(\d+(?:\.\d+)?)';
    private const FEET = "(?:'|ft|feet|foot)";
    private const INCHES = '(?:"|in|inch|inches)';

    public static function normalizeSystem(mixed $value): string
    {
        return is_string($value) && in_array(strtolower(trim($value)), self::SYSTEMS, true)
            ? strtolower(trim($value))
            : self::IMPERIAL;
    }

    /**
     * Reads a length in metres from 12'6", 12' 6", 12 ft 6 in, 12.5 ft, 150 in, 3.8 m or 381 cm.
     * A bare number is feet for an imperial user and metres for a metric one. Returns null
     * for anything else, including inches of 12 or more after a feet value.
     */
    public static function parseLength(string $input, string $system = self::IMPERIAL): ?float
    {
        $text = self::normalizeInput($input);

        if ($text === '') {
            return null;
        }

        if (preg_match('/^' . self::NUMBER . '\s*' . self::FEET . '\s*' . self::NUMBER . '\s*' . self::INCHES . '?$/', $text, $match)) {
            $inches = (float) $match[2];

            return $inches < 12 ? (float) $match[1] * self::METRES_PER_FOOT + $inches * self::METRES_PER_INCH : null;
        }

        $patterns = [
            '/^' . self::NUMBER . '\s*' . self::FEET . '$/' => self::METRES_PER_FOOT,
            '/^' . self::NUMBER . '\s*' . self::INCHES . '$/' => self::METRES_PER_INCH,
            '/^' . self::NUMBER . '\s*(?:m|meters?|metres?)$/' => 1.0,
            '/^' . self::NUMBER . '\s*(?:cm|centimeters?|centimetres?)$/' => 0.01,
            '/^' . self::NUMBER . '\s*(?:mm|millimeters?|millimetres?)$/' => 0.001,
            '/^' . self::NUMBER . '$/' => self::normalizeSystem($system) === self::METRIC ? 1.0 : self::METRES_PER_FOOT,
        ];

        foreach ($patterns as $pattern => $metresPerUnit) {
            if (preg_match($pattern, $text, $match)) {
                return (float) $match[1] * $metresPerUnit;
            }
        }

        return null;
    }

    /**
     * Reads a weight in kilograms from 22,000 lbs, 22000 lb, 22k lbs, 9,979 kg, 10 t (tonnes)
     * or 11 tons (US short tons). A bare number is pounds for an imperial user and kilograms
     * for a metric one. Returns null for anything else.
     */
    public static function parseWeight(string $input, string $system = self::IMPERIAL): ?float
    {
        $text = self::normalizeInput($input);

        // The k multiplier ("22k lbs") must not be the k of "kg".
        if (!preg_match('/^' . self::NUMBER . '\s*(k(?![a-z]))?\s*([a-z#]*)$/', $text, $match)) {
            return null;
        }

        $value = (float) $match[1] * ($match[2] === 'k' ? 1000 : 1);
        $unit = $match[3];

        if ($unit === '') {
            $unit = self::normalizeSystem($system) === self::METRIC ? 'kg' : 'lb';
        }

        $kgPerUnit = match ($unit) {
            'lb', 'lbs', 'pound', 'pounds', '#' => self::KG_PER_POUND,
            'kg', 'kgs', 'kilo', 'kilos', 'kilogram', 'kilograms' => 1.0,
            't', 'tonne', 'tonnes' => 1000.0,
            'ton', 'tons' => self::KG_PER_SHORT_TON,
            default => null,
        };

        return $kgPerUnit === null ? null : $value * $kgPerUnit;
    }

    /** 12'6" for imperial (nearest inch), 3.81 m for metric (nearest centimetre). */
    public static function formatLength(float|string|null $metres, string $system = self::IMPERIAL): string
    {
        if ($metres === null || $metres === '') {
            return '';
        }

        if (self::normalizeSystem($system) === self::METRIC) {
            return self::trimDecimals(number_format((float) $metres, 2, '.', '')) . ' m';
        }

        $totalInches = (int) round((float) $metres / self::METRES_PER_INCH);

        return intdiv($totalInches, 12) . "'" . ($totalInches % 12) . '"';
    }

    /** 22,000 lbs for imperial, 9,979 kg for metric, both to the nearest whole unit. */
    public static function formatWeight(float|string|null $kilograms, string $system = self::IMPERIAL): string
    {
        if ($kilograms === null || $kilograms === '') {
            return '';
        }

        if (self::normalizeSystem($system) === self::METRIC) {
            return number_format((float) $kilograms) . ' kg';
        }

        return number_format((float) $kilograms / self::KG_PER_POUND) . ' lbs';
    }

    /** Example inputs for help text, in the order a user of that system would reach for them. */
    public static function lengthExamples(string $system = self::IMPERIAL): string
    {
        return self::normalizeSystem($system) === self::METRIC
            ? '3.8 m or 12\'6"'
            : '12\'6", 12.5 ft or 3.8 m';
    }

    public static function weightExamples(string $system = self::IMPERIAL): string
    {
        return self::normalizeSystem($system) === self::METRIC
            ? '9,979 kg or 22,000 lbs'
            : '22,000 lbs or 9,979 kg';
    }

    /**
     * Lower-cases, folds typographic primes and quotes (which phone keyboards insert) to ' and ",
     * and resolves a comma: 22,000 is a thousands separator, 3,8 is a decimal comma.
     */
    private static function normalizeInput(string $input): string
    {
        $text = strtolower(trim($input));
        $text = str_replace(['′', '’', '‘', '`', '´'], "'", $text);
        $text = str_replace(['″', '“', '”', "''"], '"', $text);
        $text = preg_replace('/(?<=\d),(?=\d{3}(?:\D|$))/', '', $text) ?? $text;
        $text = preg_replace('/(?<=\d),(?=\d)/', '.', $text) ?? $text;

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private static function trimDecimals(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
