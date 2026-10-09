<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Models\RoutePreference;
use Keel\App\Services\Routing\Polyline;
use Keel\App\Services\Routing\RouteRequest;
use Keel\App\Services\Routing\RoutingException;
use Keel\App\Services\Routing\ValhallaProvider;
use PHPUnit\Framework\TestCase;

class ValhallaProviderTest extends TestCase
{
    private const SLC = ['lat' => 40.7608, 'lng' => -111.891];
    private const PARK_CITY = ['lat' => 40.6461, 'lng' => -111.498];

    /** @var list<array{url: string, body: array}> */
    private array $sent = [];

    private string|false $errorLog = false;

    protected function setUp(): void
    {
        // The provider logs every failure; the failures here are on purpose.
        $this->errorLog = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->errorLog);
    }

    private function vehicle(string $type, array $overrides = []): array
    {
        return $overrides + [
            'id' => 1,
            'type' => $type,
            'length_m' => '10.700',
            'width_m' => '2.600',
            'height_m' => '3.800',
            'weight_kg' => '10000.00',
            'axle_count' => 2,
            'axle_load_kg' => '6500.00',
            'has_trailer' => 0,
            'trailer_length_m' => null,
            'trailer_weight_kg' => null,
        ];
    }

    private function request(array $vehicle, array $preferences = []): RouteRequest
    {
        return RouteRequest::forVehicle(self::SLC, self::PARK_CITY, $vehicle, $preferences + RoutePreference::DEFAULTS);
    }

    /** A provider whose transport records each request body and answers with $responses in turn. */
    private function provider(array ...$responses): ValhallaProvider
    {
        return new ValhallaProvider('http://valhalla.test:8002/', 3, 20, function (string $url, string $body) use (&$responses): array {
            $this->sent[] = ['url' => $url, 'body' => json_decode($body, true, flags: JSON_THROW_ON_ERROR)];

            return array_shift($responses) ?? ['status' => 0, 'body' => null, 'error' => 'no response queued'];
        });
    }

    private function ok(array $json): array
    {
        return ['status' => 200, 'body' => json_encode($json), 'error' => ''];
    }

    private function trip(float $km, float $seconds, array $summary = []): array
    {
        return [
            'legs' => [['shape' => Polyline::encode([[40.7608, -111.891], [40.70, -111.70], [40.6461, -111.498]])]],
            'summary' => $summary + ['length' => $km, 'time' => $seconds, 'has_toll' => false, 'has_highway' => true, 'has_ferry' => false, 'has_time_restrictions' => false],
        ];
    }

    /** What actually went over the wire to /route, decoded. */
    private function sentRouteBody(RouteRequest $request): array
    {
        $this->sent = [];
        $this->provider($this->ok(['trip' => $this->trip(53.3, 2157)]))->route($request);

        self::assertSame('http://valhalla.test:8002/route', $this->sent[0]['url']);

        return $this->sent[0]['body'];
    }

    public function testRvAtFourPointSixMetresSendsTruckCostingWithThatHeight(): void
    {
        $body = $this->sentRouteBody($this->request($this->vehicle('rv', ['height_m' => '4.600'])));

        self::assertSame('truck', $body['costing']);
        self::assertSame(4.6, $body['costing_options']['truck']['height']);
        self::assertSame(2.6, $body['costing_options']['truck']['width']);
        self::assertSame(10.7, $body['costing_options']['truck']['length']);
        self::assertSame(10.0, $body['costing_options']['truck']['weight'], 'Valhalla takes metric tons.');
        self::assertSame(6.5, $body['costing_options']['truck']['axle_load']);
        self::assertSame(2, $body['costing_options']['truck']['axle_count']);
        self::assertSame(2, $body['alternates']);
        self::assertSame([['lat' => 40.7608, 'lon' => -111.891], ['lat' => 40.6461, 'lon' => -111.498]], $body['locations']);
    }

    public function testAvoidTollsSendsUseTollsZero(): void
    {
        $avoiding = $this->sentRouteBody($this->request($this->vehicle('car'), ['avoid_tolls' => 1]));
        self::assertSame(0.0, $avoiding['costing_options']['auto']['use_tolls']);

        $notAvoiding = $this->sentRouteBody($this->request($this->vehicle('car')));
        self::assertArrayNotHasKey('use_tolls', $notAvoiding['costing_options']['auto'], "Off means Valhalla's own default.");
    }

    public function testEveryVehicleTypeMapsToItsCosting(): void
    {
        $expected = [
            'car' => 'auto', 'suv' => 'auto', 'pickup' => 'auto',
            'rv' => 'truck', 'truck' => 'truck', 'truck_trailer' => 'truck',
            'motorcycle' => 'motorcycle', 'bicycle' => 'bicycle', 'walking' => 'pedestrian',
        ];

        foreach ($expected as $type => $costing) {
            $body = $this->sentRouteBody($this->request($this->vehicle($type)));
            self::assertSame($costing, $body['costing'], $type);
            self::assertArrayHasKey($costing, $body['costing_options'], $type);
        }
    }

    public function testTrailerAddsToLengthAndWeight(): void
    {
        $semi = $this->vehicle('truck_trailer', [
            'length_m' => '6.500', 'weight_kg' => '8200.00', 'has_trailer' => 1,
            'trailer_length_m' => '16.200', 'trailer_weight_kg' => '25000.00', 'axle_count' => 5,
        ]);
        $truck = $this->sentRouteBody($this->request($semi))['costing_options']['truck'];

        self::assertSame(22.7, $truck['length']);
        self::assertSame(33.2, $truck['weight']);
        self::assertSame(5, $truck['axle_count']);

        // A trailer left over on a type without one does not count.
        $boxTruck = $this->vehicle('truck', ['trailer_length_m' => '5.000', 'trailer_weight_kg' => '3000.00']);
        self::assertSame(10.7, $this->sentRouteBody($this->request($boxTruck))['costing_options']['truck']['length']);
    }

    public function testPreferencesAndEasyPersonalityMapToCostingOptions(): void
    {
        $avoidAll = $this->sentRouteBody($this->request($this->vehicle('rv'), ['avoid_highways' => 1, 'avoid_ferries' => 1]))['costing_options']['truck'];
        self::assertSame(0.0, $avoidAll['use_highways']);
        self::assertSame(0.0, $avoidAll['use_ferry']);

        $easy = $this->sentRouteBody($this->request($this->vehicle('car'), ['personality' => 'easy']))['costing_options']['auto'];
        self::assertSame(0.4, $easy['use_highways'], "A little under Valhalla's 0.5.");
        self::assertSame(15, $easy['maneuver_penalty'], "Up from Valhalla's 5 s.");

        // Avoiding highways outright wins over easy's slight dislike.
        $both = $this->sentRouteBody($this->request($this->vehicle('car'), ['personality' => 'easy', 'avoid_highways' => 1]))['costing_options']['auto'];
        self::assertSame(0.0, $both['use_highways']);

        $fastest = $this->sentRouteBody($this->request($this->vehicle('car')))['costing_options']['auto'];
        self::assertArrayNotHasKey('use_highways', $fastest);
        self::assertArrayNotHasKey('maneuver_penalty', $fastest);

        // Hills: only bicycle and pedestrian costings have use_hills.
        $bike = $this->sentRouteBody($this->request($this->vehicle('bicycle'), ['avoid_mountains' => 1, 'avoid_tolls' => 1]))['costing_options']['bicycle'];
        self::assertSame(['use_hills' => 0.0], $bike, 'No toll option on a bicycle.');
    }

    public function testBicycleWithNoOptionsStillSendsAnObject(): void
    {
        $this->sent = [];
        $this->provider($this->ok(['trip' => $this->trip(5, 900)]))->route($this->request(['id' => 1, 'type' => 'bicycle', 'length_m' => null]));

        $raw = json_encode($this->sent[0]['body']);
        self::assertSame([], $this->sent[0]['body']['costing_options']['bicycle']);
        self::assertStringContainsString('"costing":"bicycle"', $raw);
    }

    public function testParsesTheRouteAndItsAlternates(): void
    {
        $provider = $this->provider($this->ok([
            'trip' => $this->trip(53.265, 2157.2, ['has_toll' => true]),
            'alternates' => [
                ['trip' => $this->trip(54.1, 2772, ['has_highway' => false])],
                ['trip' => $this->trip(60.0, 3000, ['has_time_restrictions' => true]) + ['warnings' => [['code' => 1, 'text' => 'Something was clamped']]]],
            ],
        ]));

        $routes = $provider->route($this->request($this->vehicle('car')));

        self::assertCount(3, $routes);
        self::assertSame(53265.0, $routes[0]->distanceM);
        self::assertSame(2157.2, $routes[0]->durationS);
        self::assertTrue($routes[0]->hasToll);
        self::assertFalse($routes[1]->hasHighway);
        self::assertTrue($routes[2]->hasTimeRestrictions);
        self::assertSame(['Something was clamped'], $routes[2]->warnings);
        self::assertSame([], $routes[0]->violations);
        self::assertCount(3, Polyline::decode($routes[0]->polyline));
    }

    public function testMultipleLegsAreJoinedIntoOnePolyline(): void
    {
        $trip = $this->trip(10, 600);
        $trip['legs'] = [
            ['shape' => Polyline::encode([[40.0, -111.0], [40.1, -111.1]])],
            ['shape' => Polyline::encode([[40.1, -111.1], [40.2, -111.2]])],
        ];

        $routes = $this->provider($this->ok(['trip' => $trip]))->route($this->request($this->vehicle('car')));

        self::assertSame([[40.0, -111.0], [40.1, -111.1], [40.2, -111.2]], Polyline::decode($routes[0]->polyline));
    }

    public function testElevationAsksForASampledRangeProfile(): void
    {
        $this->sent = [];
        $provider = $this->provider($this->ok(['range_height' => [[0, 1300], [50, 1301.5], [100, null]]]));

        $profile = $provider->elevation('abc', 75.0);

        self::assertSame('http://valhalla.test:8002/height', $this->sent[0]['url']);
        self::assertSame(['encoded_polyline' => 'abc', 'shape_format' => 'polyline6', 'range' => true, 'resample_distance' => 75.0, 'height_precision' => 1], $this->sent[0]['body']);
        self::assertSame([[0.0, 1300.0], [50.0, 1301.5], [100.0, null]], $profile->samples);
    }

    public function testNoPathIsANoRouteError(): void
    {
        $provider = $this->provider(['status' => 400, 'body' => json_encode(['error_code' => 442, 'error' => 'No path could be found for input', 'status_code' => 400]), 'error' => '']);

        try {
            $provider->route($this->request($this->vehicle('rv')));
            self::fail('Expected a RoutingException.');
        } catch (RoutingException $exception) {
            self::assertSame(RoutingException::NO_ROUTE, $exception->reason);
            self::assertSame(422, $exception->httpStatus());
        }
    }

    public function testUnreachableServerIsUnavailableNotAFakeRoute(): void
    {
        $cases = [
            'connection refused' => ['status' => 0, 'body' => null, 'error' => 'Failed to connect to valhalla.test port 8002'],
            'server error' => ['status' => 500, 'body' => '{"error_code": 199, "error": "Unknown"}', 'error' => ''],
            'not json' => ['status' => 200, 'body' => '<html>proxy error</html>', 'error' => ''],
            'no legs' => $this->ok(['trip' => ['summary' => ['length' => 1, 'time' => 1], 'legs' => []]]),
            'bad shape' => $this->ok(['trip' => ['summary' => ['length' => 1, 'time' => 1], 'legs' => [['shape' => '_']]]]),
        ];

        foreach ($cases as $case => $response) {
            try {
                $this->provider($response)->route($this->request($this->vehicle('car')));
                self::fail("Expected a RoutingException for {$case}.");
            } catch (RoutingException $exception) {
                self::assertSame(RoutingException::UNAVAILABLE, $exception->reason, $case);
                self::assertSame(503, $exception->httpStatus(), $case);
            }
        }
    }

    public function testFromEnvNeedsAUrl(): void
    {
        $previous = $_ENV['VALHALLA_URL'] ?? null;
        $_ENV['VALHALLA_URL'] = '';

        try {
            ValhallaProvider::fromEnv();
            self::fail('Expected a RoutingException.');
        } catch (RoutingException $exception) {
            self::assertSame(RoutingException::NOT_CONFIGURED, $exception->reason);
        } finally {
            if ($previous === null) {
                unset($_ENV['VALHALLA_URL']);
            } else {
                $_ENV['VALHALLA_URL'] = $previous;
            }
        }
    }

    public function testVehicleLimitsNameOnlyWhatTheCostingApplies(): void
    {
        $provider = $this->provider();

        self::assertSame(
            ['height_m' => 3.8, 'width_m' => 2.6, 'length_m' => 10.7, 'weight_kg' => 10000.0, 'axle_load_kg' => 6500.0],
            $provider->vehicleLimits($this->request($this->vehicle('rv')))
        );
        self::assertSame(['height_m' => 3.8, 'width_m' => 2.6], $provider->vehicleLimits($this->request($this->vehicle('pickup'))));
        self::assertSame([], $provider->vehicleLimits($this->request($this->vehicle('motorcycle'))));
    }
}
