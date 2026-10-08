<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Models\VehicleType;
use Keel\App\Services\Units;
use Keel\App\Services\VehicleService;
use PHPUnit\Framework\TestCase;

class VehicleTypeTest extends TestCase
{
    public function testRvDefaults(): void
    {
        $defaults = VehicleType::defaults('rv');

        self::assertSame(10.7, $defaults['length_m']);
        self::assertSame(2.6, $defaults['width_m']);
        self::assertSame(3.8, $defaults['height_m']);
        self::assertSame(10000, $defaults['weight_kg']);
    }

    public function testBicycleAndWalkingNeedNoDimensions(): void
    {
        foreach (['bicycle', 'walking'] as $type) {
            self::assertSame([], VehicleType::fields($type), $type);
            self::assertSame([], VehicleType::fields($type, true), $type);
            self::assertSame([], VehicleType::defaults($type), $type);
        }
    }

    public function testTrailerFieldsApplyByTrailerMode(): void
    {
        // Required: always, whatever has_trailer says.
        self::assertTrue(VehicleType::applies('truck_trailer', 'trailer_length_m'));
        self::assertTrue(VehicleType::applies('truck_trailer', 'trailer_weight_kg'));

        // Optional: only with a trailer.
        self::assertFalse(VehicleType::applies('pickup', 'trailer_length_m'));
        self::assertTrue(VehicleType::applies('pickup', 'trailer_length_m', true));

        // None: never.
        self::assertFalse(VehicleType::applies('truck', 'trailer_length_m', true));
        self::assertFalse(VehicleType::applies('motorcycle', 'trailer_weight_kg', true));
    }

    public function testAxleFieldsApplyToHeavyVehiclesOnly(): void
    {
        foreach (['rv', 'truck', 'truck_trailer'] as $type) {
            self::assertTrue(VehicleType::applies($type, 'axle_count'), $type);
        }

        foreach (['car', 'suv', 'pickup', 'motorcycle'] as $type) {
            self::assertFalse(VehicleType::applies($type, 'axle_count'), $type);
        }
    }

    public function testTypesMatchTheVehiclesTypeEnum(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/011_create_vehicles_table.sql');
        preg_match("/type ENUM\(([^)]*)\)/", $sql, $match);
        preg_match_all("/'([a-z_]+)'/", $match[1] ?? '', $values);

        self::assertSame($values[1], array_keys(VehicleType::all()));
    }

    /** A type's defaults have to pass the form's own validation, in both unit systems. */
    public function testEveryTypesDefaultsPassValidation(): void
    {
        $service = new VehicleService();

        foreach ([Units::IMPERIAL, Units::METRIC] as $unitSystem) {
            foreach (array_keys(VehicleType::all()) as $type) {
                $input = $service->defaultFormValues($type, $unitSystem);
                $input['name'] = 'Default ' . $type;
                $input['has_trailer'] = '1';

                $result = $service->validate($input, $unitSystem);

                self::assertSame([], $result['errors'], "{$type} ({$unitSystem})");

                foreach (VehicleType::fields($type, true) as $column) {
                    // Half a display unit: an inch or centimetre, a pound or kilogram.
                    $tolerance = match (VehicleType::FIELDS[$column]['kind']) {
                        'length' => $unitSystem === Units::METRIC ? 0.005 : Units::METRES_PER_INCH / 2,
                        'weight' => $unitSystem === Units::METRIC ? 0.5 : Units::KG_PER_POUND / 2,
                        default => 0,
                    };

                    self::assertEqualsWithDelta(
                        VehicleType::defaults($type)[$column],
                        $result['attributes'][$column],
                        $tolerance,
                        "{$type}.{$column} ({$unitSystem})"
                    );
                }
            }
        }
    }
}
