<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Vehicle;
use Keel\App\Services\Routing\ElevationAnalyzer;
use Keel\App\Services\Routing\RoutePlanner;
use Keel\App\Services\Routing\RoutingException;
use Keel\App\Services\Routing\RoutingProviders;
use Keel\Core\Database;
use Tests\Support\FakeRoutingProvider;
use Tests\Support\TestResponse;
use Tests\TestCase;

class RouteApiFeatureTest extends TestCase
{
    private const SLC = ['lat' => 40.7608, 'lng' => -111.891];
    private const PARK_CITY = ['lat' => 40.6461, 'lng' => -111.498];

    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->envBackup = [
            'ROUTING_RATE_LIMIT_PER_MINUTE' => $_ENV['ROUTING_RATE_LIMIT_PER_MINUTE'] ?? null,
            'VALHALLA_URL' => $_ENV['VALHALLA_URL'] ?? null,
            'error_log' => ini_get('error_log'),
        ];

        // Failures here are on purpose; keep the routing log lines out of the test output.
        ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
    }

    protected function tearDown(): void
    {
        foreach (['ROUTING_RATE_LIMIT_PER_MINUTE', 'VALHALLA_URL'] as $key) {
            if ($this->envBackup[$key] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $this->envBackup[$key];
            }
        }

        ini_set('error_log', (string) $this->envBackup['error_log']);

        parent::tearDown();
    }

    private function car(int $userId, bool $active = true): int
    {
        return Vehicle::create($userId, ['name' => 'Runabout', 'type' => 'car', 'length_m' => 4.5, 'width_m' => 1.8, 'height_m' => 1.5, 'weight_kg' => 1500], $active);
    }

    private function heavy(int $userId, string $type = 'rv', bool $active = false): int
    {
        return Vehicle::create($userId, [
            'name' => 'The camper', 'type' => $type, 'length_m' => 10.7, 'width_m' => 2.6, 'height_m' => 3.8,
            'weight_kg' => 10000, 'axle_count' => 2, 'axle_load_kg' => 6500,
            'has_trailer' => $type === 'truck_trailer' ? 1 : 0,
            'trailer_length_m' => $type === 'truck_trailer' ? 16.2 : null,
            'trailer_weight_kg' => $type === 'truck_trailer' ? 20000 : null,
        ], $active);
    }

    /** Two routes up a canyon: the fastest climbs 900 m at 6%, the slower one 300 m at 1%. */
    private function fakeProvider(): FakeRoutingProvider
    {
        $fake = new FakeRoutingProvider();
        $fake->addRoute(2157, 53300, static fn (float $d): float => 1300 + min($d, 15000) * 0.06, ['hasHighway' => true]);
        $fake->addRoute(2772, 54100, static fn (float $d): float => 1300 + min($d, 30000) * 0.01, ['hasHighway' => true]);
        RoutingProviders::useForTesting($fake);

        return $fake;
    }

    private function plan(array $body = []): TestResponse
    {
        $body += ['origin' => self::SLC, 'destination' => self::PARK_CITY];

        return $this->postJson('/api/routes', array_filter($body, static fn (mixed $value): bool => $value !== null), ['X-CSRF-Token' => $this->csrfToken()]);
    }

    public function testACarGetsLabelledOptionsWithElevationAndExplanations(): void
    {
        $user = $this->actingAs();
        $carId = $this->car((int) $user['id']);
        $fake = $this->fakeProvider();

        $response = $this->plan();
        $json = $response->json();

        self::assertSame(200, $response->status, $response->body);
        self::assertSame('fake', $json['provider']);
        self::assertFalse($json['cached']);
        self::assertSame(['id' => $carId, 'name' => 'Runabout', 'type' => 'car'], $json['vehicle']);
        self::assertSame([], $json['notices'], 'Cars get no clearance notice.');
        self::assertCount(2, $json['options']);

        $fastest = $json['options'][0];
        self::assertSame('fastest', $fastest['label']);
        self::assertSame('Fastest', $fastest['title']);
        self::assertSame('OK', $fastest['classification']);
        self::assertSame(53300, (int) $fastest['summary']['distance_m']);
        self::assertSame(2157, (int) $fastest['summary']['duration_s']);
        self::assertSame(6, $fastest['polyline_precision']);
        self::assertNotSame('', $fastest['polyline']);
        self::assertTrue($fastest['elevation']['available']);
        self::assertEqualsWithDelta(900, $fastest['elevation']['total_climb_m'], 2);
        self::assertSame(1, $fastest['elevation']['sustained_grade_count']);
        self::assertStringStartsWith('The quickest of the 2 routes found, at 36 min.', $fastest['explanation']);
        self::assertSame([], $fastest['warnings']);

        $friendly = $json['options'][1];
        self::assertSame('vehicle_friendly', $friendly['label']);
        self::assertStringContainsString('less climbing than the fastest route', $friendly['explanation']);
        self::assertContains('time_slower', array_column($friendly['reasons'], 'code'));

        self::assertSame('car', $fake->routeRequests[0]->vehicleType);
        self::assertSame(1.5, $fake->routeRequests[0]->heightM);
    }

    public function testRvTruckAndSemiRoutesAlwaysCarryTheClearanceNotice(): void
    {
        $user = $this->actingAs();
        $this->car((int) $user['id']);

        foreach (['rv', 'truck', 'truck_trailer'] as $type) {
            $vehicleId = $this->heavy((int) $user['id'], $type);
            $fake = $this->fakeProvider();

            $json = $this->plan(['vehicle_id' => $vehicleId])->json();

            self::assertSame(['Clearance data comes from OpenStreetMap and may be incomplete. Watch posted clearances.'], $json['notices'], $type);
            self::assertSame(RoutePlanner::CLEARANCE_NOTICE, $json['notices'][0]);
            self::assertSame($type, $fake->routeRequests[0]->vehicleType);
            self::assertSame(3.8, $fake->routeRequests[0]->heightM, $type);

            foreach ($json['options'] as $option) {
                self::assertDoesNotMatchRegularExpression('/\b(clears|guarantee\w*)\b/i', $option['explanation'], $type);
            }
        }

        self::assertSame(30000.0, $fake->routeRequests[0]->weightKg, 'A semi routes at tractor plus trailer weight.');
    }

    public function testDefaultsToTheActiveVehicle(): void
    {
        $user = $this->actingAs();
        $this->car((int) $user['id'], false);
        $this->heavy((int) $user['id'], 'rv', true);
        $fake = $this->fakeProvider();

        self::assertSame(200, $this->plan()->status);
        self::assertSame('rv', $fake->routeRequests[0]->vehicleType);
    }

    public function testAnotherUsersVehicleIsNotFound(): void
    {
        $owner = $this->actingAs(['email' => 'owner@example.test']);
        $rvId = $this->heavy((int) $owner['id']);

        $intruder = $this->actingAs(['email' => 'intruder@example.test']);
        $this->car((int) $intruder['id']);
        $fake = $this->fakeProvider();

        $response = $this->plan(['vehicle_id' => $rvId]);

        self::assertSame(404, $response->status);
        self::assertSame(['error' => 'Vehicle not found.'], $response->json());
        self::assertSame([], $fake->routeRequests, 'Nothing is routed for a vehicle that is not theirs.');
    }

    public function testBadInputIsRejectedBeforeRouting(): void
    {
        $user = $this->actingAs();
        $this->car((int) $user['id']);
        $fake = $this->fakeProvider();

        $cases = [
            'origin' => ['origin' => ['lat' => 40.7]],
            'destination' => ['destination' => ['lat' => 95, 'lng' => -111.5]],
            'vehicle_id' => ['vehicle_id' => 'abc'],
        ];

        foreach ($cases as $field => $body) {
            $response = $this->plan($body);
            self::assertSame(422, $response->status, $field);
            self::assertArrayHasKey($field, $response->json()['errors'], $field);
        }

        $same = $this->plan(['destination' => self::SLC]);
        self::assertSame('The destination is the same as the starting point.', $same->json()['errors']['destination']);

        self::assertSame(422, $this->postJson('/api/routes', [], ['X-CSRF-Token' => $this->csrfToken()])->status);
        self::assertSame([], $fake->routeRequests);
    }

    public function testRoutingDownIsAClearErrorAndNeverAStandInRoute(): void
    {
        $user = $this->actingAs();
        $this->car((int) $user['id']);
        RoutingProviders::useForTesting(FakeRoutingProvider::down());

        $response = $this->plan();

        self::assertSame(503, $response->status);
        self::assertSame([
            'error' => 'The routing service is unavailable right now. Please try again in a few minutes.',
            'reason' => 'unavailable',
        ], $response->json());
    }

    public function testNoRouteIsAClearError(): void
    {
        $user = $this->actingAs();
        $this->car((int) $user['id']);
        RoutingProviders::useForTesting(new FakeRoutingProvider([], RoutingException::noRoute('No road connects those two points.')));

        $response = $this->plan();

        self::assertSame(422, $response->status);
        self::assertSame(['error' => 'No road connects those two points.', 'reason' => 'no_route'], $response->json());
    }

    public function testUnconfiguredRoutingSaysSo(): void
    {
        $user = $this->actingAs();
        $this->car((int) $user['id']);
        $_ENV['VALHALLA_URL'] = '';

        $response = $this->plan();

        self::assertSame(503, $response->status);
        self::assertSame('not_configured', $response->json()['reason']);
    }

    public function testIdenticalRequestsAreCachedForTenMinutesPerUser(): void
    {
        $user = $this->actingAs();
        $this->car((int) $user['id']);
        $fake = $this->fakeProvider();

        $first = $this->plan()->json();
        $second = $this->plan()->json();

        self::assertFalse($first['cached']);
        self::assertTrue($second['cached']);
        self::assertCount(1, $fake->routeRequests, 'The repeat never reached the provider.');
        self::assertSame(2, $fake->elevationCalls);
        self::assertSame($first['options'], $second['options']);

        $ttl = (int) Database::connection()->query('SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) FROM route_cache')->fetchColumn();
        self::assertGreaterThan(590, $ttl);
        self::assertLessThanOrEqual(600, $ttl);

        // Ranking-only preferences re-rank the cached routes rather than missing the cache.
        Database::connection()->prepare('INSERT INTO route_preferences (user_id, max_grade_pct) VALUES (?, 3.0)')->execute([(int) $user['id']]);
        $reRanked = $this->plan()->json();
        self::assertTrue($reRanked['cached']);
        self::assertSame('RESTRICTED', $reRanked['options'][0]['classification']);
        self::assertSame('Its steepest grade, 6%, is over your 3% limit.', $reRanked['options'][0]['warnings'][0]);

        // A different destination is a different request.
        $this->plan(['destination' => ['lat' => 40.6, 'lng' => -111.5]]);
        self::assertCount(2, $fake->routeRequests);

        // An expired entry is not served.
        Database::connection()->exec('UPDATE route_cache SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)');
        self::assertFalse($this->plan()->json()['cached']);
        self::assertCount(3, $fake->routeRequests);

        // Nor is one user's entry served to another.
        $other = $this->actingAs(['email' => 'other@example.test']);
        $this->car((int) $other['id']);
        self::assertFalse($this->plan()->json()['cached']);
    }

    public function testAFailedElevationFetchIsShownAndNotCached(): void
    {
        $user = $this->actingAs();
        $this->car((int) $user['id']);
        $fake = new FakeRoutingProvider([], null, RoutingException::unavailable());
        $fake->addRoute(2157, 53300, static fn (): float => 1300);
        RoutingProviders::useForTesting($fake);

        $json = $this->plan()->json();

        self::assertSame('RESTRICTED', $json['options'][0]['classification']);
        self::assertSame(["Elevation data isn't available for this route, so its grades weren't checked."], $json['options'][0]['warnings']);
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM route_cache')->fetchColumn());
    }

    public function testTheVehiclesOwnPreferencesWinOverTheDefault(): void
    {
        $user = $this->actingAs();
        $userId = (int) $user['id'];
        $carId = $this->car($userId);
        $rvId = $this->heavy($userId);
        $insert = Database::connection()->prepare('INSERT INTO route_preferences (user_id, vehicle_id, personality, avoid_tolls) VALUES (?, ?, ?, ?)');
        $insert->execute([$userId, null, 'easy', 0]);
        $insert->execute([$userId, $rvId, 'fastest', 1]);
        $fake = $this->fakeProvider();

        $this->plan(['vehicle_id' => $carId]);
        $this->plan(['vehicle_id' => $rvId]);

        self::assertSame('easy', $fake->routeRequests[0]->personality, 'The car has no row of its own.');
        self::assertFalse($fake->routeRequests[0]->avoidTolls);
        self::assertSame('fastest', $fake->routeRequests[1]->personality);
        self::assertTrue($fake->routeRequests[1]->avoidTolls);
    }

    public function testRequestsAreRateLimitedPerUser(): void
    {
        $_ENV['ROUTING_RATE_LIMIT_PER_MINUTE'] = '2';
        $user = $this->actingAs();
        $this->car((int) $user['id']);
        $this->fakeProvider();

        self::assertSame(200, $this->plan()->status);
        self::assertSame(200, $this->plan()->status);

        $limited = $this->plan();
        self::assertSame(429, $limited->status);
        self::assertSame(['error' => 'Too many route requests. Please wait a minute and try again.'], $limited->json());

        $other = $this->actingAs(['email' => 'other@example.test']);
        $this->car((int) $other['id']);
        self::assertSame(200, $this->plan()->status, 'Another user has their own allowance.');
    }

    public function testSignedOutRequestsAreRefused(): void
    {
        $this->fakeProvider();

        self::assertSame(401, $this->plan()->status);
    }

    public function testTheMapScreensRecommendationAndFiguresComeWithEachOption(): void
    {
        $user = $this->actingAs();
        $this->car((int) $user['id']);
        $rvId = $this->heavy((int) $user['id']);
        $this->fakeProvider();

        // A car is recommended the fastest route; an RV, the Vehicle-Friendly one.
        $car = $this->plan()->json();
        self::assertSame([true, false], array_column($car['options'], 'recommended'));
        self::assertSame('imperial', $car['unit_system']);

        $rv = $this->plan(['vehicle_id' => $rvId])->json();
        self::assertSame(['fastest', 'vehicle_friendly'], array_column($rv['options'], 'label'));
        self::assertSame([false, true], array_column($rv['options'], 'recommended'));

        self::assertSame([
            'distance' => '33 mi',
            'duration' => '36 min',
            'vs_fastest' => null,
            'max_grade' => '6%',
            // 899 m measured of the 900 m climbed: the 2 m hysteresis leaves the last metre or so.
            'total_climb' => '2,949 ft',
            // 2,200 m and 1,300 m.
            'highest' => '7,218 ft',
            'lowest' => '4,265 ft',
        ], $car['options'][0]['display']);
        self::assertSame('+10 min', $car['options'][1]['display']['vs_fastest']);

        // A profile to draw: evenly spaced, the whole route, at most PROFILE_POINTS points.
        $profile = $car['options'][0]['elevation']['profile'];
        self::assertCount(ElevationAnalyzer::PROFILE_POINTS, $profile);
        self::assertSame(0, $profile[0][0]);
        self::assertSame(53300, $profile[ElevationAnalyzer::PROFILE_POINTS - 1][0]);
    }

    public function testWhenNoOptionIsVehicleFriendlyTheFastestIsRecommended(): void
    {
        $user = $this->actingAs();
        $rvId = $this->heavy((int) $user['id'], 'rv', true);
        $fake = new FakeRoutingProvider();
        $fake->addRoute(2157, 53300, static fn (float $d): float => 1300.0);
        RoutingProviders::useForTesting($fake);

        $options = $this->plan(['vehicle_id' => $rvId])->json()['options'];

        self::assertCount(1, $options);
        self::assertTrue($options[0]['recommended']);
    }
}
