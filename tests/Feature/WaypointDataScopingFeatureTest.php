<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\RoutePreference;
use Keel\App\Models\SavedRoute;
use Keel\App\Models\Trip;
use Keel\App\Models\TripStop;
use Keel\App\Models\Vehicle;
use Tests\TestCase;

/** The models below the vehicle screens: relationships work for the owner and for nobody else. */
class WaypointDataScopingFeatureTest extends TestCase
{
    private function vehicleFor(int $userId, string $name): int
    {
        return Vehicle::create($userId, ['name' => $name, 'type' => 'car', 'length_m' => 4.5, 'width_m' => 1.8, 'height_m' => 1.5, 'weight_kg' => 1500], true);
    }

    public function testRelationshipsResolveForTheOwner(): void
    {
        $ownerId = (int) $this->createUser()['id'];
        $vehicleId = $this->vehicleFor($ownerId, 'Wagon');

        $tripId = Trip::create($ownerId, ['vehicle_id' => $vehicleId, 'name' => 'Coast run', 'departure_at' => '2026-11-01 08:00:00']);
        self::assertNotNull($tripId);

        TripStop::create($tripId, $ownerId, ['label' => 'Start', 'address' => '1 Main St', 'lat' => 36.6002, 'lng' => -121.8947]);
        TripStop::create($tripId, $ownerId, ['label' => 'Lunch', 'address' => 'Big Sur', 'lat' => 36.2704, 'lng' => -121.8081]);

        $routeId = SavedRoute::create($ownerId, [
            'trip_id' => $tripId,
            'vehicle_id' => $vehicleId,
            'name' => 'Highway 1',
            'provider' => 'test',
            'request' => ['avoid' => ['tolls']],
            'summary' => ['legs' => 1],
            'polyline' => 'abc123',
            'distance_m' => 48000,
            'duration_s' => 3600,
        ]);
        self::assertNotNull($routeId);
        self::assertTrue(RoutePreference::savePersonality($ownerId, 'scenic', $vehicleId));

        $trip = Trip::findForUser($tripId, $ownerId);
        self::assertSame(['Start', 'Lunch'], array_column(Trip::stops($tripId, $ownerId), 'label'));
        self::assertSame([0, 1], array_map('intval', array_column(Trip::stops($tripId, $ownerId), 'position')));
        self::assertSame($vehicleId, (int) Trip::vehicle($trip, $ownerId)['id']);
        self::assertSame([$routeId], array_map('intval', array_column(Trip::savedRoutes($tripId, $ownerId), 'id')));

        $route = SavedRoute::findForUser($routeId, $ownerId);
        self::assertSame($tripId, (int) SavedRoute::trip($route, $ownerId)['id']);
        self::assertSame($vehicleId, (int) SavedRoute::vehicle($route, $ownerId)['id']);
        self::assertSame(['avoid' => ['tolls']], json_decode($route['request_json'], true));

        self::assertCount(1, Vehicle::trips($vehicleId, $ownerId));
        self::assertCount(1, Vehicle::savedRoutes($vehicleId, $ownerId));
        self::assertSame('scenic', Vehicle::routePreference($vehicleId, $ownerId)['personality']);

        $stop = TripStop::forTrip($tripId, $ownerId)[0];
        self::assertSame($tripId, (int) TripStop::trip($stop, $ownerId)['id']);

        // Deleting the trip keeps the saved route, detached.
        self::assertTrue(Trip::delete($tripId, $ownerId));
        self::assertNull(SavedRoute::findForUser($routeId, $ownerId)['trip_id']);
        self::assertSame([], TripStop::forTrip($tripId, $ownerId));
    }

    public function testNothingResolvesOrWritesForAnotherUser(): void
    {
        $ownerId = (int) $this->createUser()['id'];
        $otherId = (int) $this->createUser()['id'];
        $vehicleId = $this->vehicleFor($ownerId, 'Wagon');
        $tripId = (int) Trip::create($ownerId, ['vehicle_id' => $vehicleId, 'name' => 'Coast run']);
        $stopId = (int) TripStop::create($tripId, $ownerId, ['label' => 'Start', 'address' => '1 Main St', 'lat' => 36.6, 'lng' => -121.9]);
        $routeId = (int) SavedRoute::create($ownerId, ['vehicle_id' => $vehicleId, 'name' => 'R', 'provider' => 'test', 'polyline' => 'x', 'distance_m' => 1, 'duration_s' => 1]);

        // Reads
        self::assertNull(Vehicle::findForUser($vehicleId, $otherId));
        self::assertSame([], Vehicle::forUser($otherId));
        self::assertNull(Trip::findForUser($tripId, $otherId));
        self::assertSame([], TripStop::forTrip($tripId, $otherId));
        self::assertNull(TripStop::findForUser($stopId, $otherId));
        self::assertNull(SavedRoute::findForUser($routeId, $otherId));
        self::assertSame([], Vehicle::trips($vehicleId, $otherId));

        // Writes that point at someone else's rows
        self::assertNull(Trip::create($otherId, ['vehicle_id' => $vehicleId, 'name' => 'Borrowed']));
        self::assertNull(TripStop::create($tripId, $otherId, ['label' => 'X', 'address' => 'X', 'lat' => 0, 'lng' => 0]));
        self::assertNull(SavedRoute::create($otherId, ['vehicle_id' => $vehicleId, 'name' => 'R', 'provider' => 't', 'polyline' => 'x', 'distance_m' => 1, 'duration_s' => 1]));
        self::assertFalse(RoutePreference::savePersonality($otherId, 'flat', $vehicleId));

        // Updates and deletes
        self::assertFalse(Trip::updateStatus($tripId, $otherId, 'completed'));
        self::assertFalse(TripStop::delete($stopId, $otherId));
        self::assertFalse(SavedRoute::delete($routeId, $otherId));
        self::assertFalse(Trip::delete($tripId, $otherId));
        self::assertFalse(Vehicle::markActive($vehicleId, $otherId));
        self::assertFalse(Vehicle::delete($vehicleId, $otherId));

        // ...and the owner's rows are all still there.
        self::assertNotNull(Vehicle::findForUser($vehicleId, $ownerId));
        self::assertSame('planned', Trip::findForUser($tripId, $ownerId)['status']);
        self::assertNotNull(TripStop::findForUser($stopId, $ownerId));
        self::assertNotNull(SavedRoute::findForUser($routeId, $ownerId));
        self::assertNull(RoutePreference::forVehicle($vehicleId, $ownerId));
    }
}
