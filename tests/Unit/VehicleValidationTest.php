<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Services\Units;
use Keel\App\Services\VehicleService;
use PHPUnit\Framework\TestCase;

class VehicleValidationTest extends TestCase
{
    private function validate(array $overrides, string $unitSystem = Units::IMPERIAL): array
    {
        return (new VehicleService())->validate($overrides + [
            'name' => 'The camper',
            'type' => 'rv',
            'length' => "35'1\"",
            'width' => "8'6\"",
            'height' => "12'6\"",
            'weight' => '22,000 lbs',
            'axle_count' => '2',
            'axle_load' => '14,000 lbs',
        ], $unitSystem);
    }

    public function testValidInputBecomesMetricAttributes(): void
    {
        $result = $this->validate([]);

        self::assertSame([], $result['errors']);
        self::assertSame(3.81, $result['attributes']['height_m']);
        self::assertSame(9979.03, $result['attributes']['weight_kg']);
        self::assertSame(2, $result['attributes']['axle_count']);
        self::assertNull($result['attributes']['trailer_length_m']);
    }

    public function testOutOfRangeHeightNamesTheLimitsInTheUsersUnits(): void
    {
        $imperial = $this->validate(['height' => '20 ft']);
        $metric = $this->validate(['height' => '0.5 m'], Units::METRIC);

        // The 1 m floor reads 3'4" rather than 3'3", because 3'3" (0.99 m) would itself be refused.
        self::assertSame("Height must be between 3'4\" and 14'9\".", $imperial['errors']['height']);
        self::assertSame('Height must be between 1 m and 4.5 m.', $metric['errors']['height']);
        self::assertNull($imperial['attributes']['height_m']);
    }

    public function testTheLimitsNamedInTheMessageAreThemselvesAccepted(): void
    {
        self::assertArrayNotHasKey('height', $this->validate(['height' => "3'4\""])['errors']);
        self::assertArrayNotHasKey('height', $this->validate(['height' => "14'9\""])['errors']);
        self::assertArrayHasKey('height', $this->validate(['height' => "14'10\""])['errors']);
        self::assertArrayNotHasKey('weight', $this->validate(['weight' => '88,184 lbs'])['errors']);
        self::assertArrayHasKey('weight', $this->validate(['weight' => '88,186 lbs'])['errors']);
    }

    public function testWeightMustBeMoreThanZeroAndAtMostFortyTonnes(): void
    {
        self::assertSame('Weight must be more than 0 and at most 88,184 lbs.', $this->validate(['weight' => '0'])['errors']['weight']);
        self::assertSame('Weight must be more than 0 and at most 40,000 kg.', $this->validate(['weight' => '41 t'], Units::METRIC)['errors']['weight']);
    }

    public function testUnreadableAndMissingValuesGetFriendlyMessages(): void
    {
        $errors = $this->validate(['length' => 'pretty long', 'width' => '', 'axle_count' => '2.5', 'name' => '', 'type' => 'spaceship'])['errors'];

        self::assertSame("We couldn't read that length. Try something like 12'6\", 12.5 ft or 3.8 m.", $errors['length']);
        self::assertSame('Give this vehicle a name.', $errors['name']);
        self::assertSame('Choose a vehicle type.', $errors['type']);
        // An unknown type is checked as a car, which has no axle fields.
        self::assertArrayNotHasKey('axle_count', $errors);

        $rvErrors = $this->validate(['width' => '', 'axle_count' => '2.5'])['errors'];
        self::assertSame('Enter the width.', $rvErrors['width']);
        self::assertSame('Enter a whole number of axles from 2 to 10.', $rvErrors['axle_count']);
    }

    public function testFieldsThatDoNotApplyAreIgnoredAndStoredAsNull(): void
    {
        $bicycle = $this->validate(['type' => 'bicycle', 'height' => 'not a height', 'has_trailer' => '1']);

        self::assertSame([], $bicycle['errors']);
        self::assertNull($bicycle['attributes']['height_m']);
        self::assertNull($bicycle['attributes']['length_m']);
        self::assertSame(0, $bicycle['attributes']['has_trailer']);

        $pickupWithoutTrailer = $this->validate(['type' => 'pickup', 'trailer_length' => '20 ft', 'axle_count' => '9']);
        self::assertSame([], $pickupWithoutTrailer['errors']);
        self::assertNull($pickupWithoutTrailer['attributes']['trailer_length_m']);
        self::assertNull($pickupWithoutTrailer['attributes']['axle_count']);
    }

    public function testTrailerFieldsFollowTheTrailerMode(): void
    {
        $semi = $this->validate(['type' => 'truck_trailer', 'axle_count' => '5', 'trailer_length' => '53 ft', 'trailer_weight' => '50,000 lbs']);
        self::assertSame([], $semi['errors']);
        self::assertSame(1, $semi['attributes']['has_trailer']);
        self::assertSame(16.154, $semi['attributes']['trailer_length_m']);

        $semiMissingTrailer = $this->validate(['type' => 'truck_trailer']);
        self::assertSame('Enter the trailer length.', $semiMissingTrailer['errors']['trailer_length']);

        $pickupTowing = $this->validate(['type' => 'pickup', 'has_trailer' => '1', 'trailer_length' => '24 ft', 'trailer_weight' => '6,000 lbs']);
        self::assertSame([], $pickupTowing['errors']);
        self::assertSame(1, $pickupTowing['attributes']['has_trailer']);
    }
}
