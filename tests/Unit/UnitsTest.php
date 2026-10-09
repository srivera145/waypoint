<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Services\Units;
use PHPUnit\Framework\TestCase;

class UnitsTest extends TestCase
{
    public function testFeetAndInchesParseToMetres(): void
    {
        foreach (["12'6\"", "12' 6\"", '12 ft 6 in', '12ft6in', '12 feet 6 inches', "12'6", "12′6″", "12'6''"] as $input) {
            self::assertEqualsWithDelta(3.81, Units::parseLength($input), 0.0005, $input);
        }
    }

    public function testDecimalFeetInchesAndMetricLengthsParse(): void
    {
        self::assertEqualsWithDelta(3.81, Units::parseLength('12.5 ft'), 0.0005);
        self::assertEqualsWithDelta(3.81, Units::parseLength('150 in'), 0.0005);
        self::assertEqualsWithDelta(3.8, Units::parseLength('3.8 m'), 0.0005);
        self::assertEqualsWithDelta(3.8, Units::parseLength('3.8m'), 0.0005);
        self::assertEqualsWithDelta(3.8, Units::parseLength('3,8 m'), 0.0005);
        self::assertEqualsWithDelta(3.81, Units::parseLength('381 cm'), 0.0005);
    }

    public function testPoundsParseToKilograms(): void
    {
        $kilograms = Units::parseWeight('22,000 lbs');

        self::assertNotNull($kilograms);
        self::assertSame(9979, (int) round($kilograms));
        self::assertSame('9,979 kg', Units::formatWeight($kilograms, Units::METRIC));

        foreach (['22000 lb', '22k lbs', '11 tons', '22000 pounds'] as $input) {
            self::assertEqualsWithDelta($kilograms, Units::parseWeight($input), 0.001, $input);
        }

        self::assertEqualsWithDelta(9979.0, Units::parseWeight('9,979 kg'), 0.001);
        self::assertEqualsWithDelta(10000.0, Units::parseWeight('10 t'), 0.001);
    }

    public function testBareNumberIsReadInTheUsersUnits(): void
    {
        self::assertEqualsWithDelta(12 * 0.3048, Units::parseLength('12', Units::IMPERIAL), 0.0001);
        self::assertEqualsWithDelta(12.0, Units::parseLength('12', Units::METRIC), 0.0001);
        self::assertEqualsWithDelta(22000 * 0.45359237, Units::parseWeight('22000', Units::IMPERIAL), 0.0001);
        self::assertEqualsWithDelta(22000.0, Units::parseWeight('22,000', Units::METRIC), 0.0001);
    }

    public function testUnreadableInputIsRejected(): void
    {
        foreach (['', '   ', 'tall', '-3 m', "12'14\"", '12 ft 6 ft', 'm'] as $input) {
            self::assertNull(Units::parseLength($input), "length: {$input}");
        }

        foreach (['', 'heavy', '-5 kg', '5 g', 'lbs', '22,000 stone'] as $input) {
            self::assertNull(Units::parseWeight($input), "weight: {$input}");
        }
    }

    public function testFormatting(): void
    {
        self::assertSame("12'6\"", Units::formatLength(3.81));
        self::assertSame("35'1\"", Units::formatLength(10.7));
        self::assertSame("12'0\"", Units::formatLength(3.6576));
        self::assertSame('3.81 m', Units::formatLength('3.810', Units::METRIC));
        self::assertSame('3.8 m', Units::formatLength(3.8, Units::METRIC));
        self::assertSame('4 m', Units::formatLength(4.0, Units::METRIC));
        self::assertSame('22,000 lbs', Units::formatWeight(9979.03));
        self::assertSame('', Units::formatLength(null));
        self::assertSame('', Units::formatWeight(null));
    }

    public function testUnknownUnitSystemFallsBackToImperial(): void
    {
        self::assertSame(Units::METRIC, Units::normalizeSystem(' Metric '));
        self::assertSame(Units::IMPERIAL, Units::normalizeSystem('nautical'));
        self::assertSame(Units::IMPERIAL, Units::normalizeSystem(null));
    }

    /**
     * Every inch from 1 ft to 50 ft: what is displayed parses back to the same length, and
     * still displays the same after being stored to the millimetre (DECIMAL(6,3)).
     */
    public function testImperialLengthsRoundTripWithinDisplayPrecision(): void
    {
        for ($inches = 12; $inches <= 600; $inches++) {
            $metres = $inches * Units::METRES_PER_INCH;
            $display = Units::formatLength($metres);
            $parsed = Units::parseLength($display);

            self::assertNotNull($parsed, $display);
            self::assertEqualsWithDelta($metres, $parsed, Units::METRES_PER_INCH / 2, $display);
            self::assertSame($display, Units::formatLength(round($parsed, 3)), $display);
        }
    }

    public function testMetricLengthsRoundTripWithinDisplayPrecision(): void
    {
        for ($centimetres = 50; $centimetres <= 2500; $centimetres++) {
            $display = Units::formatLength($centimetres / 100, Units::METRIC);
            $parsed = Units::parseLength($display, Units::METRIC);

            self::assertNotNull($parsed, $display);
            self::assertEqualsWithDelta($centimetres / 100, $parsed, 0.005, $display);
            self::assertSame($display, Units::formatLength(round($parsed, 3), Units::METRIC), $display);
        }
    }

    /** Weights survive display, parse and storage to the hundredth of a kilogram (DECIMAL(8,2)). */
    public function testWeightsRoundTripWithinDisplayPrecision(): void
    {
        for ($pounds = 1; $pounds <= 88184; $pounds += 7) {
            $display = Units::formatWeight($pounds * Units::KG_PER_POUND);
            $parsed = Units::parseWeight($display);

            self::assertNotNull($parsed, $display);
            self::assertEqualsWithDelta($pounds * Units::KG_PER_POUND, $parsed, Units::KG_PER_POUND / 2, $display);
            self::assertSame($display, Units::formatWeight(round($parsed, 2)), $display);
        }

        for ($kilograms = 1; $kilograms <= 40000; $kilograms += 3) {
            $display = Units::formatWeight($kilograms, Units::METRIC);
            $parsed = Units::parseWeight($display, Units::METRIC);

            self::assertNotNull($parsed, $display);
            self::assertSame($display, Units::formatWeight(round($parsed, 2), Units::METRIC), $display);
        }
    }

    public function testDistancesReadInMilesOrKilometres(): void
    {
        self::assertSame('33 mi', Units::formatDistance(53300), 'Whole units from 10 up.');
        self::assertSame('6.2 mi', Units::formatDistance(10000));
        self::assertSame('0.6 mi', Units::formatDistance(1000));
        self::assertSame('152 mi', Units::formatDistance(244620));
        self::assertSame('330 ft', Units::formatDistance(100));
        self::assertSame('1,204 mi', Units::formatDistance(1937650));

        self::assertSame('53 km', Units::formatDistance(53300, Units::METRIC));
        self::assertSame('6.6 km', Units::formatDistance(6600, Units::METRIC));
        self::assertSame('1.0 km', Units::formatDistance(1000, Units::METRIC));
        self::assertSame('800 m', Units::formatDistance(803, Units::METRIC));
        self::assertSame('152 km', Units::formatDistance(152000, Units::METRIC));
    }

    public function testElevationsAndDurations(): void
    {
        self::assertSame('7,034 ft', Units::formatElevation(2144));
        self::assertSame('2,144 m', Units::formatElevation(2144, Units::METRIC));

        self::assertSame('36 min', Units::formatDuration(2157));
        self::assertSame('1 h', Units::formatDuration(3600));
        self::assertSame('1 h 5 min', Units::formatDuration(3900));
        self::assertSame('under 1 min', Units::formatDuration(20));
    }
}
