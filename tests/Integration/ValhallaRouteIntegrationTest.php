<?php

declare(strict_types=1);

namespace Tests\Integration;

use Keel\App\Models\RoutePreference;
use Keel\App\Models\Vehicle;
use Keel\App\Services\Routing\Polyline;
use Keel\App\Services\Routing\RoutePlanner;
use Keel\App\Services\Routing\RouteRequest;
use Keel\App\Services\Routing\ValhallaProvider;
use Keel\Core\Env;
use Tests\TestCase;

/**
 * Against the Valhalla at VALHALLA_URL (docker/valhalla with the Utah extract), end to end
 * through POST /api/routes. Skips itself when nothing answers there.
 */
class ValhallaRouteIntegrationTest extends TestCase
{
    private const SLC = ['lat' => 40.7608, 'lng' => -111.891];
    private const PARK_CITY = ['lat' => 40.6461, 'lng' => -111.498];

    protected function setUp(): void
    {
        parent::setUp();

        $url = rtrim(trim((string) Env::get('VALHALLA_URL', '')), '/');

        if ($url === '' || !$this->answers($url . '/status')) {
            self::markTestSkipped("No Valhalla answering at '{$url}/status'. Start docker/valhalla (see its README) to run these.");
        }
    }

    public function testCarFromSaltLakeCityToParkCity(): void
    {
        $user = $this->actingAs();
        Vehicle::create((int) $user['id'], ['name' => 'Runabout', 'type' => 'car', 'length_m' => 4.5, 'width_m' => 1.8, 'height_m' => 1.5, 'weight_kg' => 1500], true);

        $json = $this->planSlcToParkCity();

        self::assertSame('valhalla', $json['provider']);
        self::assertSame([], $json['notices']);
        $this->assertValidRoutes($json['options']);
    }

    public function testRvOfThreePointEightMetresAndTenTonnesFromSaltLakeCityToParkCity(): void
    {
        $user = $this->actingAs();
        Vehicle::create((int) $user['id'], [
            'name' => 'The camper', 'type' => 'rv', 'length_m' => 10.7, 'width_m' => 2.6, 'height_m' => 3.8,
            'weight_kg' => 10000, 'axle_count' => 2, 'axle_load_kg' => 6500,
        ], true);

        $json = $this->planSlcToParkCity();

        self::assertSame([RoutePlanner::CLEARANCE_NOTICE], $json['notices']);
        $this->assertValidRoutes($json['options']);

        foreach ($json['options'] as $option) {
            self::assertStringContainsString("Planned with OpenStreetMap's road limits for your vehicle's height (12'6\")", $option['explanation']);
        }
    }

    public function testTheProviderRoutesTheRvAsATruckAndSamplesHeights(): void
    {
        $provider = ValhallaProvider::fromEnv();
        $rv = ['id' => 1, 'type' => 'rv', 'length_m' => '10.700', 'width_m' => '2.600', 'height_m' => '3.800', 'weight_kg' => '10000.00', 'axle_count' => 2, 'axle_load_kg' => '6500.00', 'has_trailer' => 0];
        $request = RouteRequest::forVehicle(self::SLC, self::PARK_CITY, $rv, RoutePreference::DEFAULTS);

        self::assertSame('truck', $provider->routeBody($request)['costing']);

        $routes = $provider->route($request);
        self::assertGreaterThanOrEqual(1, count($routes));
        self::assertLessThanOrEqual(3, count($routes));

        $profile = $provider->elevation($routes[0]->polyline, 100.0);
        self::assertGreaterThan(100, count($profile->samples));
        self::assertSame(0.0, $profile->samples[0][0]);
        self::assertEqualsWithDelta(1300, $profile->samples[0][1], 60, 'Salt Lake City sits near 1,300 m.');
    }

    private function planSlcToParkCity(): array
    {
        $response = $this->postJson('/api/routes', ['origin' => self::SLC, 'destination' => self::PARK_CITY], ['X-CSRF-Token' => $this->csrfToken()]);

        self::assertSame(200, $response->status, $response->body);

        return $response->json();
    }

    private function assertValidRoutes(array $options): void
    {
        self::assertGreaterThanOrEqual(1, count($options));
        self::assertLessThanOrEqual(3, count($options));
        self::assertSame('fastest', $options[0]['label']);

        foreach ($options as $index => $option) {
            $where = "option {$index} ({$option['title']})";
            $elevation = $option['elevation'];
            $points = Polyline::decode($option['polyline']);

            self::assertContains($option['classification'], ['OK', 'RESTRICTED', 'SUBOPTIMAL'], $where);
            self::assertNotSame('', $option['explanation'], $where);

            // About 54 km and 35 to 50 minutes by I-80 through Parleys Canyon.
            self::assertGreaterThan(40000, $option['summary']['distance_m'], $where);
            self::assertLessThan(90000, $option['summary']['distance_m'], $where);
            self::assertGreaterThan(20 * 60, $option['summary']['duration_s'], $where);
            self::assertLessThan(100 * 60, $option['summary']['duration_s'], $where);
            self::assertEqualsWithDelta(self::SLC['lat'], $points[0][0], 0.01, $where);
            self::assertEqualsWithDelta(self::PARK_CITY['lng'], $points[count($points) - 1][1], 0.01, $where);

            // Valid elevation stats: Salt Lake City near 1,300 m, Parleys Summit near 2,145 m.
            self::assertTrue($elevation['available'], $where);
            self::assertGreaterThanOrEqual(90.0, $elevation['coverage_pct'], $where);
            self::assertEqualsWithDelta(1300, $elevation['min_elevation_m'], 80, $where);
            self::assertGreaterThan(2000, $elevation['max_elevation_m'], $where);
            self::assertLessThan(2700, $elevation['max_elevation_m'], $where);
            self::assertGreaterThan(700, $elevation['total_climb_m'], $where);
            self::assertLessThan(2500, $elevation['total_climb_m'], $where);
            self::assertGreaterThan(3.0, $elevation['max_grade_pct'], $where);
            self::assertLessThan(15.0, $elevation['max_grade_pct'], $where);
            self::assertGreaterThanOrEqual(1, $elevation['sustained_grade_count'], "{$where}: Parleys Canyon is a long 5-6% grade");
            self::assertCount($elevation['sustained_grade_count'], $elevation['sustained_grades'], $where);
        }
    }

    private function answers(string $url): bool
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return $status === 200 && is_string($body) && str_contains($body, 'version');
    }
}
