<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\VehicleType;
use Keel\App\Services\VehicleService;
use Keel\Core\Database;
use Tests\TestCase;

class DashboardFeatureTest extends TestCase
{
    private function giveVehicle(int $userId, string $type, string $name): int
    {
        $attributes = ['name' => $name, 'type' => $type, 'has_trailer' => 0];

        foreach (VehicleType::fields($type) as $column) {
            $attributes[$column] = VehicleType::defaults($type)[$column];
        }

        return (new VehicleService())->create($userId, $attributes, true);
    }

    private function preferenceRows(int $userId): array
    {
        $statement = Database::connection()->prepare('SELECT vehicle_id, personality FROM route_preferences WHERE user_id = ?');
        $statement->execute([$userId]);

        return $statement->fetchAll();
    }

    public function testHomeShowsTheActiveVehicleCard(): void
    {
        $user = $this->actingAs();
        $this->giveVehicle((int) $user['id'], 'rv', 'The camper');

        $home = $this->get('/dashboard');

        self::assertSame(200, $home->status);
        self::assertStringContainsString('The camper', $home->body);
        self::assertStringContainsString('35&#039;1&quot;', $home->body, 'length');
        self::assertStringContainsString('12&#039;6&quot;', $home->body, 'height');
        self::assertStringContainsString('href="/vehicles">Change vehicle</a>', $home->body);
        self::assertStringContainsString('placeholder="Where to?"', $home->body);

        foreach (['Home', 'Work', 'Favorites', 'Plan a Trip', 'Ask Waypoint', 'Fastest', 'Scenic', 'Easy', 'Custom'] as $label) {
            self::assertStringContainsString($label, $home->body, $label);
        }
    }

    public function testATrailerCombinationShowsItsOverallLength(): void
    {
        $user = $this->actingAs();
        $userId = (int) $user['id'];
        $attributes = ['name' => 'Big rig', 'type' => 'truck_trailer', 'has_trailer' => 1] + VehicleType::defaults('truck_trailer');
        (new VehicleService())->create($userId, $attributes, true);

        $home = $this->get('/dashboard');

        // 6.5 m tractor + 16.2 m trailer = 22.7 m = 74'6".
        self::assertStringContainsString('Length with trailer', $home->body);
        self::assertStringContainsString('74&#039;6&quot;', $home->body);
    }

    public function testHomeWithoutAVehicleOffersToAddOne(): void
    {
        $this->actingAs();

        $home = $this->get('/dashboard');

        self::assertSame(200, $home->status);
        self::assertStringContainsString('href="/vehicles">Add vehicle</a>', $home->body);
    }

    public function testTravelModeSavesToTheUsersDefaultRoutePreferences(): void
    {
        $user = $this->actingAs();
        $userId = (int) $user['id'];

        $saved = $this->post('/preferences/travel-mode', ['_csrf' => $this->csrfToken(), 'personality' => 'scenic']);
        self::assertSame('/dashboard#travel-mode', $saved->header('Location'));
        self::assertSame([['vehicle_id' => null, 'personality' => 'scenic']], $this->preferenceRows($userId));

        $this->post('/preferences/travel-mode', ['_csrf' => $this->csrfToken(), 'personality' => 'easy']);
        self::assertSame([['vehicle_id' => null, 'personality' => 'easy']], $this->preferenceRows($userId), 'Updated, not duplicated.');

        $home = $this->get('/dashboard');
        self::assertMatchesRegularExpression('/value="easy" aria-pressed="true"/', $home->body);
        self::assertMatchesRegularExpression('/value="fastest" aria-pressed="false"/', $home->body);
    }

    public function testCustomOffersTheOtherPersonalities(): void
    {
        $user = $this->actingAs();

        $custom = $this->get('/dashboard?mode=custom');
        self::assertStringContainsString('value="economical"', $custom->body);
        self::assertStringContainsString('RV-friendly', $custom->body);
        self::assertStringContainsString('routes stay on Fastest', $custom->body);

        $this->post('/preferences/travel-mode', ['_csrf' => $this->csrfToken(), 'personality' => 'rv']);
        self::assertSame('rv', $this->preferenceRows((int) $user['id'])[0]['personality']);

        // A saved custom mode keeps Custom selected without the query string.
        $home = $this->get('/dashboard');
        self::assertStringContainsString('#travel-mode" aria-current="true">Custom</a>', $home->body);
        self::assertMatchesRegularExpression('/value="rv" class="chip" aria-pressed="true"/', $home->body);
    }

    public function testUnknownTravelModeIsRejected(): void
    {
        $user = $this->actingAs();

        $response = $this->post('/preferences/travel-mode', ['_csrf' => $this->csrfToken(), 'personality' => 'warp']);

        self::assertSame(422, $response->status);
        self::assertSame([], $this->preferenceRows((int) $user['id']));
    }
}
