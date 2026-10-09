<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\SavedRoute;
use Keel\App\Models\Vehicle;
use Keel\App\Services\Routing\RoutingProviders;
use Keel\Core\Database;
use Tests\Support\FakeRoutingProvider;
use Tests\Support\TestResponse;
use Tests\TestCase;

class SavedRouteFeatureTest extends TestCase
{
    private const SLC = ['lat' => 40.7608, 'lng' => -111.891, 'label' => 'Salt Lake City'];
    private const PARK_CITY = ['lat' => 40.6461, 'lng' => -111.498, 'label' => 'Park City'];

    private FakeRoutingProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        // Two routes up a canyon: the fastest climbs 900 m at 6%, the slower one 300 m at 1%.
        $this->fake = new FakeRoutingProvider();
        $this->fake->addRoute(2157, 53300, static fn (float $d): float => 1300 + min($d, 15000) * 0.06, ['hasHighway' => true]);
        $this->fake->addRoute(2772, 54100, static fn (float $d): float => 1300 + min($d, 30000) * 0.01, ['hasHighway' => true]);
        RoutingProviders::useForTesting($this->fake);
    }

    private function vehicle(int $userId, string $type = 'car', bool $active = true): int
    {
        $size = $type === 'rv'
            ? ['length_m' => 10.7, 'width_m' => 2.6, 'height_m' => 3.81, 'weight_kg' => 10000, 'axle_count' => 2, 'axle_load_kg' => 6500]
            : ['length_m' => 4.5, 'width_m' => 1.8, 'height_m' => 1.5, 'weight_kg' => 1500];

        return Vehicle::create($userId, ['name' => $type === 'rv' ? 'My RV' : 'My Car', 'type' => $type] + $size, $active);
    }

    private function plan(int $vehicleId): array
    {
        $response = $this->postJson('/api/routes', [
            'origin' => ['lat' => self::SLC['lat'], 'lng' => self::SLC['lng']],
            'destination' => ['lat' => self::PARK_CITY['lat'], 'lng' => self::PARK_CITY['lng']],
            'vehicle_id' => $vehicleId,
        ], ['X-CSRF-Token' => $this->csrfToken()]);

        self::assertSame(200, $response->status, $response->body);

        return $response->json();
    }

    private function save(int $vehicleId, array $option, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/saved-routes', $overrides + [
            'origin' => self::SLC,
            'destination' => self::PARK_CITY,
            'vehicle_id' => $vehicleId,
            'label' => $option['label'],
            'polyline' => $option['polyline'],
        ], ['X-CSRF-Token' => $this->csrfToken()]);
    }

    private function savedRouteFor(int $userId, ?int $vehicleId = null): int
    {
        $vehicleId ??= $this->vehicle($userId);
        $plan = $this->plan($vehicleId);

        return (int) $this->save($vehicleId, $plan['options'][0])->json()['id'];
    }

    public function testSavingStoresTheRouteWaypointPlanned(): void
    {
        $user = $this->actingAs();
        $vehicleId = $this->vehicle((int) $user['id']);
        $plan = $this->plan($vehicleId);
        $option = $plan['options'][1];

        $response = $this->save($vehicleId, $option);
        $json = $response->json();

        self::assertSame(201, $response->status, $response->body);
        self::assertSame('To Park City', $json['name']);
        self::assertSame('/routes/' . $json['id'], $json['url']);
        self::assertCount(1, $this->fake->routeRequests, 'Saving planned again from the cache.');

        $saved = SavedRoute::findForUser((int) $json['id'], (int) $user['id']);
        $summary = json_decode($saved['summary_json'], true);
        $request = json_decode($saved['request_json'], true);

        self::assertSame($vehicleId, (int) $saved['vehicle_id']);
        self::assertSame('fake', $saved['provider']);
        self::assertSame($option['polyline'], $saved['polyline']);
        self::assertSame(54100, (int) $saved['distance_m']);
        self::assertSame(2772, (int) $saved['duration_s']);
        self::assertSame('vehicle_friendly', $summary['option']['label']);
        self::assertSame($option['explanation'], $summary['option']['explanation']);
        self::assertSame($option['elevation']['profile'], $summary['option']['elevation']['profile']);
        self::assertArrayNotHasKey('ranking', $summary['option']);
        self::assertSame([], $summary['notices']);
        self::assertSame(['id' => $vehicleId, 'name' => 'My Car', 'type' => 'car', 'height_m' => 1.5, 'length_m' => 4.5], $summary['vehicle']);
        self::assertSame(self::SLC, $request['origin']);
        self::assertSame(self::PARK_CITY, $request['destination']);
        self::assertSame('fastest', $request['personality']);
    }

    public function testARouteThatNoLongerMatchesIsNotSaved(): void
    {
        $user = $this->actingAs();
        $vehicleId = $this->vehicle((int) $user['id']);
        $option = $this->plan($vehicleId)['options'][0];

        $tampered = $this->save($vehicleId, $option, ['polyline' => $option['polyline'] . 'A']);
        self::assertSame(409, $tampered->status);
        self::assertSame('changed', $tampered->json()['reason']);

        $relabelled = $this->save($vehicleId, $option, ['label' => 'scenic']);
        self::assertSame(409, $relabelled->status);

        self::assertSame([], SavedRoute::forUser((int) $user['id']));
    }

    public function testSavingValidatesAndKeepsToTheUsersOwnVehicle(): void
    {
        $user = $this->actingAs();
        $vehicleId = $this->vehicle((int) $user['id']);
        $option = $this->plan($vehicleId)['options'][0];

        $cases = [
            'origin' => ['origin' => ['lat' => 40.7, 'lng' => -111.8]],
            'destination' => ['destination' => ['lat' => 'north', 'lng' => -111.5, 'label' => 'Park City']],
            'label' => ['label' => 'teleport'],
            'polyline' => ['polyline' => ''],
            'vehicle_id' => ['vehicle_id' => 'mine'],
            'name' => ['name' => str_repeat('n', 101)],
        ];

        foreach ($cases as $field => $overrides) {
            $response = $this->save($vehicleId, $option, $overrides);
            self::assertSame(422, $response->status, $field);
            self::assertArrayHasKey($field, $response->json()['errors'], $field);
        }

        $otherVehicle = $this->vehicle((int) $this->createUser()['id']);
        self::assertSame(404, $this->save($otherVehicle, $option)->status);

        self::assertSame([], SavedRoute::forUser((int) $user['id']));
    }

    public function testAnRvRouteKeepsItsClearanceNoticeWhenSaved(): void
    {
        $user = $this->actingAs();
        $rvId = $this->vehicle((int) $user['id'], 'rv');
        $plan = $this->plan($rvId);

        $id = (int) $this->save($rvId, $plan['options'][0], ['name' => '  Up   the canyon '])->json()['id'];
        $page = $this->get('/routes/' . $id);

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Up the canyon', $page->body);
        self::assertStringContainsString('Clearance not guaranteed', $page->body);
        self::assertStringContainsString('Clearance data comes from OpenStreetMap and may be incomplete. Watch posted clearances.', $page->body);
        self::assertStringContainsString('My RV (12&#039;6&quot; high)', $page->body);
    }

    public function testTheSavedRoutePageRedrawsTheSameLine(): void
    {
        $user = $this->actingAs();
        $id = $this->savedRouteFor((int) $user['id']);
        $saved = SavedRoute::findForUser($id, (int) $user['id']);

        $page = $this->get('/routes/' . $id);
        self::assertSame(200, $page->status);
        self::assertMatchesRegularExpression('/data-route="([^"]+)"/', $page->body);
        preg_match('/data-route="([^"]+)"/', $page->body, $match);
        $route = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);

        self::assertSame($saved['polyline'], $route['polyline']);
        self::assertSame(6, $route['precision']);
        self::assertSame(['lat' => 40.7608, 'lng' => -111.891], $route['origin']);
        self::assertNotEmpty($route['profile']);
        self::assertStringContainsString('/map?from=40.760800%2C-111.891000&amp;from_label=Salt+Lake+City&amp;to=40.646100%2C-111.498000&amp;to_label=Park+City', $page->body);
    }

    public function testTheListShowsOnlyTheUsersRoutes(): void
    {
        $owner = $this->actingAs();
        $id = $this->savedRouteFor((int) $owner['id']);

        $list = $this->get('/routes');
        self::assertSame(200, $list->status);
        self::assertStringContainsString('To Park City', $list->body);
        self::assertStringContainsString('href="/routes/' . $id . '"', $list->body);

        $this->actingAs();
        $other = $this->get('/routes');
        self::assertStringNotContainsString('To Park City', $other->body);
        self::assertStringContainsString('No saved routes yet', $other->body);
    }

    public function testAnotherUserGetsNotFoundOnEveryRouteUrl(): void
    {
        $owner = $this->actingAs();
        $id = $this->savedRouteFor((int) $owner['id']);

        $this->actingAs();
        $csrf = ['_csrf' => $this->csrfToken()];

        self::assertSame(404, $this->get("/routes/{$id}")->status);
        self::assertSame(404, $this->post("/routes/{$id}/rename", $csrf + ['name' => 'Mine now'])->status);
        self::assertSame(404, $this->post("/routes/{$id}/delete", $csrf)->status);
        self::assertSame(404, $this->get('/routes/not-a-number')->status);

        self::assertSame('To Park City', SavedRoute::findForUser($id, (int) $owner['id'])['name']);
    }

    public function testRenameAndDelete(): void
    {
        $user = $this->actingAs();
        $id = $this->savedRouteFor((int) $user['id']);
        $csrf = ['_csrf' => $this->csrfToken()];

        $renamed = $this->post("/routes/{$id}/rename", $csrf + ['name' => ' Ski   day ']);
        self::assertSame(302, $renamed->status);
        self::assertSame("/routes/{$id}?status=renamed", $renamed->header('Location'));
        self::assertSame('Ski day', SavedRoute::findForUser($id, (int) $user['id'])['name']);
        self::assertStringContainsString('Route renamed.', $this->get("/routes/{$id}?status=renamed")->body);

        $blank = $this->post("/routes/{$id}/rename", $csrf + ['name' => '   ']);
        self::assertSame(422, http_response_code());
        self::assertStringContainsString('Give this route a name.', $blank->body);
        self::assertSame('Ski day', SavedRoute::findForUser($id, (int) $user['id'])['name']);

        $deleted = $this->post("/routes/{$id}/delete", $csrf);
        self::assertSame(302, $deleted->status);
        self::assertSame('/routes?status=deleted', $deleted->header('Location'));
        self::assertNull(SavedRoute::findForUser($id, (int) $user['id']));

        $actions = Database::connection()->query("SELECT action FROM activity_log WHERE subject_type = 'SavedRoute' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['saved_route.created', 'saved_route.renamed', 'saved_route.deleted'], $actions);
    }

    public function testSavingNeedsSignInAndACsrfToken(): void
    {
        self::assertSame(401, $this->postJson('/api/saved-routes', [], ['X-CSRF-Token' => $this->csrfToken()])->status);

        $user = $this->actingAs();
        $vehicleId = $this->vehicle((int) $user['id']);
        $option = $this->plan($vehicleId)['options'][0];

        $response = $this->postJson('/api/saved-routes', ['origin' => self::SLC, 'destination' => self::PARK_CITY, 'vehicle_id' => $vehicleId, 'label' => $option['label'], 'polyline' => $option['polyline']]);
        self::assertSame(419, $response->status);
    }
}
