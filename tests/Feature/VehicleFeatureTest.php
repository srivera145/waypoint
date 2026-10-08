<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Trip;
use Keel\App\Models\Vehicle;
use Keel\Core\Database;
use Tests\TestCase;

class VehicleFeatureTest extends TestCase
{
    private function rvInput(array $overrides = []): array
    {
        return $overrides + [
            '_csrf' => $this->csrfToken(),
            'name' => 'The camper',
            'type' => 'rv',
            'length' => "35'1\"",
            'width' => "8'6\"",
            'height' => "12'6\"",
            'weight' => '22,000 lbs',
            'axle_count' => '2',
            'axle_load' => '14,000 lbs',
        ];
    }

    private function vehicleRow(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM vehicles WHERE id = ?');
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    private function activeCount(int $userId): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM vehicles WHERE user_id = ? AND is_active = 1');
        $statement->execute([$userId]);

        return (int) $statement->fetchColumn();
    }

    private function latestVehicleId(int $userId): int
    {
        $statement = Database::connection()->prepare('SELECT MAX(id) FROM vehicles WHERE user_id = ?');
        $statement->execute([$userId]);

        return (int) $statement->fetchColumn();
    }

    public function testCreateEditActivateAndDeleteKeepExactlyOneActiveVehicle(): void
    {
        $user = $this->actingAs();
        $userId = (int) $user['id'];

        // The first vehicle is active even without asking.
        $created = $this->post('/vehicles', $this->rvInput());
        self::assertSame(302, $created->status);
        self::assertSame('/vehicles?status=created', $created->header('Location'));

        $rvId = $this->latestVehicleId($userId);
        $rv = $this->vehicleRow($rvId);
        self::assertSame('3.810', $rv['height_m']);
        self::assertSame('9979.03', $rv['weight_kg']);
        self::assertSame(1, (int) $rv['is_active']);

        // A second one stays inactive unless asked.
        $this->post('/vehicles', ['_csrf' => $this->csrfToken(), 'name' => 'Runabout', 'type' => 'car', 'length' => '4.5 m', 'width' => '1.8 m', 'height' => '1.5 m', 'weight' => '1500 kg']);
        $carId = $this->latestVehicleId($userId);
        self::assertSame(0, (int) $this->vehicleRow($carId)['is_active']);
        self::assertSame(1, $this->activeCount($userId));

        // A third, made active, takes over.
        $this->post('/vehicles', ['_csrf' => $this->csrfToken(), 'name' => 'Bike', 'type' => 'bicycle', 'make_active' => '1']);
        $bikeId = $this->latestVehicleId($userId);
        self::assertSame(1, (int) $this->vehicleRow($bikeId)['is_active']);
        self::assertSame(0, (int) $this->vehicleRow($rvId)['is_active']);
        self::assertSame(1, $this->activeCount($userId));

        // The edit form shows stored metric values in the user's imperial units.
        $edit = $this->get("/vehicles/{$rvId}/edit");
        self::assertSame(200, $edit->status);
        self::assertStringContainsString('value="12&#039;6&quot;"', $edit->body);
        self::assertStringContainsString('value="22,000 lbs"', $edit->body);

        $updated = $this->post("/vehicles/{$rvId}/update", $this->rvInput(['height' => '13 ft']));
        self::assertSame('/vehicles?status=updated', $updated->header('Location'));
        self::assertSame('3.962', $this->vehicleRow($rvId)['height_m']);

        // Activating clears the others.
        $activated = $this->post("/vehicles/{$carId}/activate", ['_csrf' => $this->csrfToken()]);
        self::assertSame('/vehicles?status=activated', $activated->header('Location'));
        self::assertSame(1, (int) $this->vehicleRow($carId)['is_active']);
        self::assertSame(1, $this->activeCount($userId));

        // Deleting the active vehicle hands "active" to another.
        $deleted = $this->post("/vehicles/{$carId}/delete", ['_csrf' => $this->csrfToken()]);
        self::assertSame('/vehicles?status=deleted', $deleted->header('Location'));
        self::assertNull($this->vehicleRow($carId));
        self::assertSame(1, $this->activeCount($userId));
    }

    public function testAnotherUserGetsNotFoundOnEveryVehicleUrl(): void
    {
        $owner = $this->actingAs(['email' => 'owner@example.test']);
        $this->post('/vehicles', $this->rvInput());
        $vehicleId = $this->latestVehicleId((int) $owner['id']);
        $before = $this->vehicleRow($vehicleId);

        $this->actingAs(['email' => 'intruder@example.test']);
        $csrf = ['_csrf' => $this->csrfToken()];

        $responses = [
            'edit' => $this->get("/vehicles/{$vehicleId}/edit"),
            'update' => $this->post("/vehicles/{$vehicleId}/update", $this->rvInput(['name' => 'Mine now', 'height' => '4 m'])),
            'activate' => $this->post("/vehicles/{$vehicleId}/activate", $csrf),
            'delete' => $this->post("/vehicles/{$vehicleId}/delete", $csrf),
        ];

        foreach ($responses as $action => $response) {
            self::assertSame(404, $response->status, $action);
            self::assertStringContainsString('Page not found', $response->body, $action);
        }

        self::assertSame($before, $this->vehicleRow($vehicleId), "The owner's vehicle is untouched.");

        $list = $this->get('/vehicles');
        self::assertSame(200, $list->status);
        self::assertStringNotContainsString('The camper', $list->body);

        self::assertSame(404, $this->get('/vehicles/abc/edit')->status);
    }

    public function testInvalidDimensionsAreRejectedWithFriendlyErrors(): void
    {
        $user = $this->actingAs();

        $response = $this->post('/vehicles', $this->rvInput(['height' => '20 ft', 'weight' => 'heavy']));

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Height must be between 3&#039;4&quot; and 14&#039;9&quot;.', $response->body);
        self::assertStringContainsString('We couldn&#039;t read that weight.', $response->body);
        self::assertStringContainsString('value="20 ft"', $response->body, 'What they typed is kept.');
        self::assertSame(0, Vehicle::countForUser((int) $user['id']));
    }

    public function testTheOnlyVehicleCannotBeDeleted(): void
    {
        $user = $this->actingAs();
        $this->post('/vehicles', $this->rvInput());
        $vehicleId = $this->latestVehicleId((int) $user['id']);

        $response = $this->post("/vehicles/{$vehicleId}/delete", ['_csrf' => $this->csrfToken()]);

        self::assertSame('/vehicles?error=last_vehicle', $response->header('Location'));
        self::assertNotNull($this->vehicleRow($vehicleId));
        self::assertStringContainsString('You need at least one vehicle.', $this->get('/vehicles?error=last_vehicle')->body);
    }

    public function testAVehicleUsedByATripCannotBeDeleted(): void
    {
        $user = $this->actingAs();
        $userId = (int) $user['id'];
        $this->post('/vehicles', $this->rvInput());
        $rvId = $this->latestVehicleId($userId);
        $this->post('/vehicles', $this->rvInput(['name' => 'Spare']));
        Trip::create($userId, ['vehicle_id' => $rvId, 'name' => 'Coast run']);

        $response = $this->post("/vehicles/{$rvId}/delete", ['_csrf' => $this->csrfToken()]);

        self::assertSame('/vehicles?error=in_use', $response->header('Location'));
        self::assertNotNull($this->vehicleRow($rvId));
    }

    public function testFirstSignInCreatesMyCarOnce(): void
    {
        $email = 'new-driver@example.test';

        for ($signIn = 1; $signIn <= 2; $signIn++) {
            $this->postJson('/auth/otp/request', ['email' => $email], ['X-CSRF-Token' => $this->csrfToken()]);
            preg_match_all('/\b(\d{6})\b/', $this->latestMailLog(), $codes);
            $verify = $this->postJson('/auth/otp/verify', ['email' => $email, 'code' => end($codes[1])], ['X-CSRF-Token' => $this->csrfToken()]);
            self::assertSame(200, $verify->status, "sign-in {$signIn}");
        }

        $userId = (int) $_SESSION['user_id'];
        $vehicles = Vehicle::forUser($userId);

        self::assertCount(1, $vehicles);
        self::assertSame('My Car', $vehicles[0]['name']);
        self::assertSame('car', $vehicles[0]['type']);
        self::assertSame(1, (int) $vehicles[0]['is_active']);
        self::assertSame('4.500', $vehicles[0]['length_m']);
        self::assertNull($vehicles[0]['trailer_length_m']);
    }

    public function testPickingATypePrefillsItsDefaults(): void
    {
        $this->actingAs();

        $chooser = $this->get('/vehicles/create');
        self::assertSame(200, $chooser->status);
        self::assertStringContainsString('href="/vehicles/create?type=truck_trailer"', $chooser->body);

        $rv = $this->get('/vehicles/create?type=rv');
        self::assertStringContainsString('value="35&#039;1&quot;"', $rv->body);
        self::assertStringContainsString('value="12&#039;6&quot;"', $rv->body);
        self::assertStringContainsString('value="22,046 lbs"', $rv->body);
        // The script's copy of every type's defaults travels with the form.
        self::assertStringContainsString('data-vehicle-types="{&quot;car&quot;', $rv->body);

        $this->actingAs(['email' => 'metric@example.test']);
        Database::connection()->exec("UPDATE users SET unit_system = 'metric' WHERE email = 'metric@example.test'");
        $metric = $this->get('/vehicles/create?type=rv');
        self::assertStringContainsString('value="10.7 m"', $metric->body);
        self::assertStringContainsString('value="3.8 m"', $metric->body);
        self::assertStringContainsString('value="10,000 kg"', $metric->body);
    }

    public function testBicycleIsStoredWithoutDimensions(): void
    {
        $user = $this->actingAs();

        $this->post('/vehicles', ['_csrf' => $this->csrfToken(), 'name' => 'Commuter', 'type' => 'bicycle', 'length' => '6 ft', 'height' => 'tall']);
        $bike = $this->vehicleRow($this->latestVehicleId((int) $user['id']));

        foreach (['length_m', 'width_m', 'height_m', 'weight_kg', 'axle_count', 'trailer_length_m'] as $column) {
            self::assertNull($bike[$column], $column);
        }
    }

    public function testUnitSettingChangesHowDimensionsRead(): void
    {
        $user = $this->actingAs();
        $this->post('/vehicles', $this->rvInput());

        $response = $this->post('/settings/units', ['_csrf' => $this->csrfToken(), 'unit_system' => 'metric', 'return_to' => '/vehicles']);
        self::assertSame('/vehicles', $response->header('Location'));
        self::assertStringContainsString('3.81 m high', $this->get('/vehicles')->body);

        self::assertSame(422, $this->post('/settings/units', ['_csrf' => $this->csrfToken(), 'unit_system' => 'cubits'])->status);

        $statement = Database::connection()->prepare('SELECT unit_system FROM users WHERE id = ?');
        $statement->execute([(int) $user['id']]);
        self::assertSame('metric', $statement->fetchColumn());
    }
}
