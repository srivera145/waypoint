<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\RoutePreference;
use Keel\App\Models\Vehicle;
use Tests\TestCase;

class MapScreenFeatureTest extends TestCase
{
    private array $envBackup = [];

    private string $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archive = tempnam(sys_get_temp_dir(), 'tiles');
        file_put_contents($this->archive, 'PMTiles');
        $this->envBackup = ['MAP_TILES_PATH' => $_ENV['MAP_TILES_PATH'] ?? null, 'MAP_TILES_URL' => $_ENV['MAP_TILES_URL'] ?? null];
        $_ENV['MAP_TILES_PATH'] = $this->archive;
        $_ENV['MAP_TILES_URL'] = '';
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            $_ENV[$key] = $value;
        }

        @unlink($this->archive);

        parent::tearDown();
    }

    private function rv(int $userId, bool $active = true): int
    {
        return Vehicle::create($userId, ['name' => 'My RV', 'type' => 'rv', 'length_m' => 10.7, 'width_m' => 2.6, 'height_m' => 3.81, 'weight_kg' => 10000, 'axle_count' => 2, 'axle_load_kg' => 6500], $active);
    }

    /** The JSON in one of the map screen's data- attributes. */
    private static function data(string $body, string $attribute): mixed
    {
        self::assertMatchesRegularExpression("/{$attribute}=\"([^\"]*)\"/", $body);
        preg_match("/{$attribute}=\"([^\"]*)\"/", $body, $match);

        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
    }

    public function testTheMapNeedsSignIn(): void
    {
        $response = $this->get('/map');

        self::assertSame(302, $response->status);
        self::assertSame('/login', $response->header('Location'));
    }

    public function testTheMapShowsTheActiveVehicleTravelModeAndBasemap(): void
    {
        $user = $this->actingAs();
        $rvId = $this->rv((int) $user['id']);
        RoutePreference::savePersonality((int) $user['id'], 'flat');

        $page = $this->get('/map')->body;

        self::assertStringContainsString('My RV · 12&#039;6&quot;', $page);
        self::assertStringContainsString('href="/vehicles?return_to=%2Fmap"', $page);
        self::assertStringContainsString('data-tiles-url="/tiles/basemap.pmtiles"', $page);
        self::assertSame(['id' => $rvId, 'name' => 'My RV', 'type' => 'rv', 'emoji' => '🚐', 'large' => true, 'edit_url' => "/vehicles/{$rvId}/edit"], self::data($page, 'data-vehicle'));
        // Flat is one of Custom's modes, so Custom is open and Flat is the pressed one.
        self::assertMatchesRegularExpression('/value="flat" class="chip" aria-pressed="true"/', $page);
        self::assertMatchesRegularExpression('/aria-pressed="true" aria-expanded="true" aria-controls="custom-modes"/', $page);
        self::assertStringNotContainsString('id="custom-modes" class="cluster cluster-tight" role="group" aria-label="Custom travel modes" hidden', $page);
        // Every category the search box can show has a label and an icon.
        self::assertSame(['fuel', 'food', 'lodging', 'campground', 'shop', 'parking', 'attraction', 'address'], array_keys(self::data($page, 'data-categories')));
        // The libraries load from this site.
        self::assertStringContainsString('"maplibre-gl":"/vendor/maplibre-gl@6.13.0/maplibre-gl.mjs"', $page);
        self::assertStringNotContainsString('unpkg.com', $page);
        self::assertStringNotContainsString('protomaps.github.io', $page);
    }

    public function testATripInTheQueryStringIsCarriedIn(): void
    {
        $user = $this->actingAs();
        $this->rv((int) $user['id']);

        $page = $this->get('/map?to=40.646092,-111.497996&to_label=Park+City&from=40.7608,-111.891&from_label=Salt+Lake+City&q=ignored')->body;
        self::assertSame([
            'q' => 'ignored',
            'from' => ['lat' => 40.7608, 'lng' => -111.891, 'label' => 'Salt Lake City'],
            'to' => ['lat' => 40.646092, 'lng' => -111.497996, 'label' => 'Park City'],
        ], self::data($page, 'data-initial'));

        $bad = self::data($this->get('/map?to=999,1&to_label=Nowhere&from=40.7,-111.8&q=%3Cscript%3E')->body, 'data-initial');
        self::assertNull($bad['to']);
        self::assertNull($bad['from'], 'A point without a label is ignored.');
        self::assertSame('<script>', $bad['q']);
    }

    public function testWithoutABasemapTheMapScreenStillLoads(): void
    {
        $this->actingAs();
        $_ENV['MAP_TILES_PATH'] = '';

        $response = $this->get('/map');

        self::assertSame(200, http_response_code());
        self::assertStringContainsString('data-tiles-url=""', $response->body);
        self::assertStringContainsString('Add a vehicle', $response->body);
    }

    public function testAConfiguredTileUrlIsUsedInstead(): void
    {
        $this->actingAs();
        $_ENV['MAP_TILES_URL'] = 'https://tiles.example.test/us.pmtiles';

        self::assertStringContainsString('data-tiles-url="https://tiles.example.test/us.pmtiles"', $this->get('/map')->body);
    }

    public function testTheMapsTravelModeSelectorAnswersJson(): void
    {
        $user = $this->actingAs();
        $headers = ['X-CSRF-Token' => $this->csrfToken()];

        $saved = $this->postJson('/preferences/travel-mode', ['personality' => 'easy'], $headers);
        self::assertSame(200, $saved->status);
        self::assertSame(['personality' => 'easy', 'label' => 'Easy'], $saved->json());
        self::assertSame('easy', RoutePreference::personalityForUser((int) $user['id']));

        $refused = $this->postJson('/preferences/travel-mode', ['personality' => 'teleport'], $headers);
        self::assertSame(422, $refused->status);
        self::assertSame('easy', RoutePreference::personalityForUser((int) $user['id']));

        // Without scripts the form posts and comes back to the map, never anywhere else.
        $csrf = ['_csrf' => $this->csrfToken()];
        self::assertSame('/map', $this->post('/preferences/travel-mode', $csrf + ['personality' => 'scenic', 'return_to' => '/map'])->header('Location'));
        self::assertSame('/dashboard#travel-mode', $this->post('/preferences/travel-mode', $csrf + ['personality' => 'scenic', 'return_to' => 'https://evil.test/map'])->header('Location'));
    }

    public function testSwitchingVehicleFromTheMapReturnsToTheTrip(): void
    {
        $user = $this->actingAs();
        $carId = Vehicle::create((int) $user['id'], ['name' => 'My Car', 'type' => 'car', 'length_m' => 4.5, 'width_m' => 1.8, 'height_m' => 1.5, 'weight_kg' => 1500], true);
        $rvId = $this->rv((int) $user['id'], false);
        $trip = '/map?to=40.646092,-111.497996&to_label=Park+City';

        $list = $this->get('/vehicles?return_to=' . rawurlencode($trip))->body;
        self::assertStringContainsString('Back to the map', $list);
        self::assertStringContainsString('name="return_to" value="' . htmlspecialchars($trip) . '"', $list);

        $csrf = ['_csrf' => $this->csrfToken()];
        $switched = $this->post("/vehicles/{$rvId}/activate", $csrf + ['return_to' => $trip]);
        self::assertSame($trip, $switched->header('Location'));
        self::assertSame($rvId, (int) Vehicle::activeForUser((int) $user['id'])['id']);

        self::assertSame('/vehicles?status=activated', $this->post("/vehicles/{$carId}/activate", $csrf + ['return_to' => '//evil.test/map'])->header('Location'));
        self::assertStringNotContainsString('Back to the map', $this->get('/vehicles?return_to=https://evil.test')->body);
    }

    public function testHomeSearchAndPlanATripOpenTheMap(): void
    {
        $this->actingAs();

        $home = $this->get('/dashboard')->body;

        self::assertMatchesRegularExpression('#<form class="search" role="search" method="GET" action="/map">#', $home);
        self::assertStringContainsString('name="q"', $home);
        self::assertMatchesRegularExpression('#<a class="btn btn-sm btn-soft btn-round" href="/map">\s*<svg[^>]*>.*?</svg>\s*Plan a Trip#s', $home);
        self::assertStringNotContainsString('Search is not available yet', $home);
    }
}
