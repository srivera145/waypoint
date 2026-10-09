<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Services\Geocoding\GeocodingProviders;
use Keel\App\Services\Geocoding\PlaceCategory;
use Keel\App\Services\Geocoding\PlaceResult;
use Keel\Core\Database;
use Tests\Support\FakeGeocodingProvider;
use Tests\TestCase;

class PlaceApiFeatureTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->envBackup = ['PLACES_RATE_LIMIT_PER_MINUTE' => $_ENV['PLACES_RATE_LIMIT_PER_MINUTE'] ?? null, 'error_log' => ini_get('error_log')];
        ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
    }

    protected function tearDown(): void
    {
        if ($this->envBackup['PLACES_RATE_LIMIT_PER_MINUTE'] === null) {
            unset($_ENV['PLACES_RATE_LIMIT_PER_MINUTE']);
        } else {
            $_ENV['PLACES_RATE_LIMIT_PER_MINUTE'] = $this->envBackup['PLACES_RATE_LIMIT_PER_MINUTE'];
        }

        ini_set('error_log', (string) $this->envBackup['error_log']);

        parent::tearDown();
    }

    private function fake(): FakeGeocodingProvider
    {
        $fake = new FakeGeocodingProvider([
            new PlaceResult('Park City', 'Summit County, Utah', 40.6460921, -111.4979963, PlaceCategory::ADDRESS, 'osm:R198864'),
            new PlaceResult('Park City Mountain Resort', 'Summit County, Utah 84068', 40.654146, -111.562396, PlaceCategory::ATTRACTION, 'osm:W918681867'),
            new PlaceResult('Moab', 'Grand County, Utah', 38.5733, -109.5498, PlaceCategory::ADDRESS, 'osm:R197380'),
        ]);
        GeocodingProviders::useForTesting($fake);

        return $fake;
    }

    public function testSearchReturnsLabelledPlacesBiasedTowardTheUser(): void
    {
        $this->actingAs();
        $fake = $this->fake();

        $response = $this->getJson('/api/places/search?q=Park+City&lat=40.7608&lng=-111.891');
        $json = $response->json();

        self::assertSame(200, $response->status, $response->body);
        self::assertSame('Park City', $json['query']);
        self::assertFalse($json['cached']);
        self::assertSame([
            'label' => 'Park City',
            'secondary_label' => 'Summit County, Utah',
            'lat' => 40.6460921,
            'lng' => -111.4979963,
            'category' => 'address',
            'source_ref' => 'osm:R198864',
        ], $json['results'][0]);
        self::assertSame(['Park City', 'Park City Mountain Resort'], array_column($json['results'], 'label'));

        // The bias point is rounded to about a kilometre before it leaves Waypoint.
        self::assertSame(['query' => 'Park City', 'lat' => 40.76, 'lng' => -111.89, 'limit' => 6], $fake->searches[0]);
    }

    public function testIdenticalSearchesAreCachedForFiveMinutesPerUser(): void
    {
        $user = $this->actingAs();
        $fake = $this->fake();

        $first = $this->getJson('/api/places/search?q=Park+City&lat=40.7608&lng=-111.891')->json();
        // Same words, different spacing and case, a few hundred metres away: the same search.
        $second = $this->getJson('/api/places/search?q=%20park%20%20city&lat=40.7621&lng=-111.8932')->json();

        self::assertFalse($first['cached']);
        self::assertTrue($second['cached']);
        self::assertSame($first['results'], $second['results']);
        self::assertCount(1, $fake->searches);

        $expires = Database::connection()->query("SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) FROM place_cache WHERE user_id = {$user['id']}")->fetchColumn();
        self::assertEqualsWithDelta(300, (int) $expires, 5);

        // Another user's identical search is theirs alone.
        $this->actingAs();
        self::assertFalse($this->getJson('/api/places/search?q=Park+City&lat=40.7608&lng=-111.891')->json()['cached']);
        self::assertCount(2, $fake->searches);

        // Once it expires, the geocoder is asked again.
        Database::connection()->exec('UPDATE place_cache SET expires_at = NOW() - INTERVAL 1 SECOND');
        $this->getJson('/api/places/search?q=Park+City&lat=40.7608&lng=-111.891');
        self::assertCount(3, $fake->searches);
    }

    public function testNoMatchesIsAnEmptyList(): void
    {
        $this->actingAs();
        $this->fake();

        $response = $this->getJson('/api/places/search?q=zzqxq');

        self::assertSame(200, $response->status);
        self::assertSame([], $response->json()['results']);
    }

    public function testBadInputIsRejectedBeforeSearching(): void
    {
        $this->actingAs();
        $fake = $this->fake();

        $cases = [
            '/api/places/search' => 'q',
            '/api/places/search?q=%20%20' => 'q',
            '/api/places/search?q=' . str_repeat('a', 201) => 'q',
            '/api/places/search?q=moab&lat=40.7' => 'lat',
            '/api/places/search?q=moab&lat=95&lng=-111' => 'lat',
            '/api/places/search?q=moab&lat=north&lng=west' => 'lat',
            '/api/places/search?q=moab&limit=50' => 'limit',
            '/api/places/search?q[]=moab' => 'q',
            '/api/places/reverse?lat=40.7' => 'lat',
        ];

        foreach ($cases as $uri => $field) {
            $response = $this->getJson($uri);
            self::assertSame(422, $response->status, $uri);
            self::assertArrayHasKey($field, $response->json()['errors'], $uri);
        }

        self::assertSame([], $fake->searches);
        self::assertSame([], $fake->reverses);
    }

    public function testGeocoderDownIsAClearErrorAndIsNotCached(): void
    {
        $this->actingAs();
        $fake = FakeGeocodingProvider::down();
        GeocodingProviders::useForTesting($fake);

        $response = $this->getJson('/api/places/search?q=Moab');

        self::assertSame(503, $response->status);
        self::assertSame('Place search is unavailable right now. Please try again in a few minutes.', $response->json()['error']);
        self::assertSame('unavailable', $response->json()['reason']);

        $this->getJson('/api/places/search?q=Moab');
        self::assertCount(2, $fake->searches);
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM place_cache')->fetchColumn());
    }

    public function testUnconfiguredGeocodingSaysSo(): void
    {
        $this->actingAs();
        $previous = $_ENV['PHOTON_URL'] ?? null;
        $_ENV['PHOTON_URL'] = '';

        try {
            $response = $this->getJson('/api/places/search?q=Moab');
        } finally {
            $_ENV['PHOTON_URL'] = $previous;
        }

        self::assertSame(503, $response->status);
        self::assertSame('not_configured', $response->json()['reason']);
    }

    public function testReverseNamesTheNearestPlace(): void
    {
        $this->actingAs();
        $fake = $this->fake();

        $response = $this->getJson('/api/places/reverse?lat=40.760812&lng=-111.891047');

        self::assertSame(200, $response->status, $response->body);
        self::assertSame('Park City', $response->json()['result']['label']);
        // About ten metres is plenty for "where am I".
        self::assertSame(['lat' => 40.7608, 'lng' => -111.891], $fake->reverses[0]);

        self::assertTrue($this->getJson('/api/places/reverse?lat=40.76081&lng=-111.89102')->json()['cached']);
    }

    public function testSearchesAreRateLimitedPerUser(): void
    {
        $_ENV['PLACES_RATE_LIMIT_PER_MINUTE'] = '2';
        $this->actingAs();
        $this->fake();

        self::assertSame(200, $this->getJson('/api/places/search?q=Moab')->status);
        self::assertSame(200, $this->getJson('/api/places/search?q=Park')->status);

        $limited = $this->getJson('/api/places/search?q=Park+City');
        self::assertSame(429, $limited->status);
        self::assertStringContainsString('Too many searches', $limited->json()['error']);

        $this->actingAs();
        self::assertSame(200, $this->getJson('/api/places/search?q=Moab')->status, 'The limit is per user.');
    }

    public function testSignedOutRequestsAreRefused(): void
    {
        $fake = $this->fake();

        self::assertSame(401, $this->getJson('/api/places/search?q=Moab')->status);
        self::assertSame(401, $this->getJson('/api/places/reverse?lat=40.76&lng=-111.89')->status);
        self::assertSame([], $fake->searches);
    }
}
