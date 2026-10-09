<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Services\Geocoding\GeocodingException;
use Keel\App\Services\Geocoding\PhotonProvider;
use Keel\App\Services\Geocoding\PlaceCategory;
use PHPUnit\Framework\TestCase;

class PhotonProviderTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

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

    /** A provider whose transport records each URL and answers with $responses in turn. */
    private function provider(array ...$responses): PhotonProvider
    {
        return new PhotonProvider('http://photon.test:2322/', 2, 5, 'en', function (string $url) use (&$responses): array {
            $this->urls[] = $url;

            return array_shift($responses) ?? ['status' => 0, 'body' => null, 'error' => 'no more responses'];
        });
    }

    private static function features(array ...$features): array
    {
        return ['status' => 200, 'body' => json_encode(['type' => 'FeatureCollection', 'features' => $features]), 'error' => ''];
    }

    private static function feature(float $lng, float $lat, array $properties): array
    {
        return ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]], 'properties' => $properties + ['countrycode' => 'US', 'country' => 'United States']];
    }

    /** As Photon answers "Park City" from Salt Lake City (captured from the Utah build). */
    private static function parkCity(): array
    {
        return self::features(
            self::feature(-111.4979963, 40.6460921, ['osm_type' => 'R', 'osm_id' => 198864, 'osm_key' => 'place', 'osm_value' => 'town', 'type' => 'city', 'name' => 'Park City', 'county' => 'Summit', 'state' => 'Utah']),
            self::feature(-111.5076908, 40.6513513, ['osm_type' => 'N', 'osm_id' => 355685310, 'osm_key' => 'leisure', 'osm_value' => 'sports_centre', 'type' => 'house', 'name' => 'Park City Mountain Resort', 'street' => 'Drift Road', 'city' => 'Park City', 'county' => 'Summit', 'state' => 'Utah', 'postcode' => '84060']),
            self::feature(-111.8877012, 40.7722146, ['osm_type' => 'W', 'osm_id' => 33162273, 'osm_key' => 'leisure', 'osm_value' => 'park', 'type' => 'other', 'name' => 'City Creek Park', 'housenumber' => '110', 'street' => 'North State Street', 'city' => 'Salt Lake City', 'county' => 'Salt Lake', 'state' => 'Utah', 'postcode' => '84103']),
        );
    }

    public function testSearchSendsTheQueryBiasLimitAndLanguage(): void
    {
        $this->provider(self::parkCity())->search('Park City', 40.76, -111.89, 6);

        self::assertCount(1, $this->urls);
        $parts = parse_url($this->urls[0]);
        parse_str($parts['query'], $query);

        self::assertSame('/api', $parts['path']);
        self::assertSame(['q' => 'Park City', 'limit' => '6', 'lang' => 'en', 'lat' => '40.76', 'lon' => '-111.89'], $query);
    }

    public function testWithoutABiasPointNoneIsSent(): void
    {
        $this->provider(self::parkCity())->search('Park City', null, null, 3);
        parse_str((string) parse_url($this->urls[0], PHP_URL_QUERY), $query);

        self::assertArrayNotHasKey('lat', $query);
        self::assertArrayNotHasKey('lon', $query);
    }

    public function testFeaturesBecomeLabelledPlaces(): void
    {
        $places = $this->provider(self::parkCity())->search('Park City', 40.76, -111.89, 6);

        self::assertCount(3, $places);

        // A town: its county places it, written the way people say it.
        self::assertSame('Park City', $places[0]->label);
        self::assertSame('Summit County, Utah', $places[0]->secondaryLabel);
        self::assertSame(40.6460921, $places[0]->lat);
        self::assertSame(-111.4979963, $places[0]->lng);
        self::assertSame(PlaceCategory::ADDRESS, $places[0]->category);
        self::assertSame('osm:R198864', $places[0]->sourceRef);

        // A business: its street, then its town.
        self::assertSame('Drift Road, Park City, Utah 84060', $places[1]->secondaryLabel);

        // A named place with a house number, mapped to a category.
        self::assertSame('110 North State Street, Salt Lake City, Utah', $places[2]->secondaryLabel);
        self::assertSame(PlaceCategory::ATTRACTION, $places[2]->category);
    }

    public function testAnAddressWithNoNameIsLabelledByItsStreet(): void
    {
        $places = $this->provider(self::features(
            self::feature(-111.89, 40.76, ['type' => 'house', 'housenumber' => '255', 'street' => 'South State Street', 'city' => 'Salt Lake City', 'state' => 'Utah', 'postcode' => '84111', 'osm_key' => 'building', 'osm_value' => 'yes']),
        ))->search('255 S State', null, null, 5);

        self::assertSame('255 South State Street', $places[0]->label);
        self::assertSame('Salt Lake City, Utah 84111', $places[0]->secondaryLabel);
    }

    public function testCategoriesFollowTheOsmTag(): void
    {
        $cases = [
            ['amenity', 'fuel', PlaceCategory::FUEL],
            ['amenity', 'fast_food', PlaceCategory::FOOD],
            ['tourism', 'motel', PlaceCategory::LODGING],
            ['tourism', 'camp_site', PlaceCategory::CAMPGROUND],
            ['tourism', 'caravan_site', PlaceCategory::CAMPGROUND],
            ['shop', 'supermarket', PlaceCategory::SHOP],
            ['amenity', 'parking', PlaceCategory::PARKING],
            ['highway', 'rest_area', PlaceCategory::PARKING],
            ['tourism', 'viewpoint', PlaceCategory::ATTRACTION],
            ['boundary', 'national_park', PlaceCategory::ATTRACTION],
            ['place', 'city', PlaceCategory::ADDRESS],
            ['highway', 'residential', PlaceCategory::ADDRESS],
        ];

        foreach ($cases as [$key, $value, $category]) {
            self::assertSame($category, PlaceCategory::fromOsm($key, $value), "{$key}={$value}");
        }
    }

    public function testFeaturesWithNoPointOrNameAreSkipped(): void
    {
        $places = $this->provider(self::features(
            ['type' => 'Feature', 'geometry' => null, 'properties' => ['name' => 'Nowhere']],
            self::feature(-111.89, 91.0, ['name' => 'Off the globe']),
            self::feature(-111.89, 40.76, ['osm_key' => 'place']),
            self::feature(-111.89, 40.76, ['name' => 'Kept', 'state' => 'Utah']),
        ))->search('anything', null, null, 5);

        self::assertSame(['Kept'], array_map(static fn ($place): string => $place->label, $places));
    }

    public function testOutsideTheUsTheCountryIsNamed(): void
    {
        $places = $this->provider(self::features(
            ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [-114.07, 51.05]], 'properties' => ['type' => 'city', 'name' => 'Calgary', 'state' => 'Alberta', 'country' => 'Canada', 'countrycode' => 'CA']],
        ))->search('Calgary', null, null, 5);

        self::assertSame('Alberta, Canada', $places[0]->secondaryLabel);
    }

    public function testNoMatchesIsAnEmptyListNotAnError(): void
    {
        self::assertSame([], $this->provider(self::features())->search('zzqx', null, null, 5));
    }

    public function testReverseAsksForTheNearestPlace(): void
    {
        $place = $this->provider(self::features(
            self::feature(-111.8910893, 40.7607304, ['type' => 'street', 'name' => '400 South', 'district' => 'Downtown', 'city' => 'Salt Lake City', 'county' => 'Salt Lake', 'state' => 'Utah', 'postcode' => '84138', 'osm_key' => 'highway', 'osm_value' => 'primary']),
        ))->reverse(40.7608, -111.891);

        parse_str((string) parse_url($this->urls[0], PHP_URL_QUERY), $query);
        self::assertSame('/reverse', parse_url($this->urls[0], PHP_URL_PATH));
        self::assertSame(['lat' => '40.7608', 'lon' => '-111.891', 'limit' => '1', 'lang' => 'en'], $query);
        self::assertSame('400 South', $place->label);
        self::assertSame('Salt Lake City, Utah 84138', $place->secondaryLabel);
    }

    public function testReverseWithNothingNearIsTheBarePoint(): void
    {
        $place = $this->provider(self::features())->reverse(40.5, -113.25);

        self::assertSame('40.50000, -113.25000', $place->label);
        self::assertNull($place->secondaryLabel);
        self::assertNull($place->sourceRef);
    }

    public function testAnUnreachableOrBrokenServerIsUnavailable(): void
    {
        $failures = [
            ['status' => 0, 'body' => null, 'error' => 'Failed to connect to photon.test port 2322: Connection refused'],
            ['status' => 0, 'body' => null, 'error' => 'Operation timed out after 5001 milliseconds'],
            ['status' => 500, 'body' => 'Internal Server Error', 'error' => ''],
            ['status' => 400, 'body' => '{"message": "Unknown query parameter"}', 'error' => ''],
            ['status' => 200, 'body' => '<html>proxy error</html>', 'error' => ''],
        ];

        foreach ($failures as $failure) {
            try {
                $this->provider($failure)->search('Moab', null, null, 5);
                self::fail('Expected unavailable for ' . json_encode($failure));
            } catch (GeocodingException $exception) {
                self::assertSame(GeocodingException::UNAVAILABLE, $exception->reason);
                self::assertSame(503, $exception->httpStatus());
                self::assertStringContainsString('unavailable', $exception->getMessage());
            }
        }
    }

    public function testFromEnvNeedsAUrl(): void
    {
        $previous = $_ENV['PHOTON_URL'] ?? null;
        $_ENV['PHOTON_URL'] = '';

        try {
            PhotonProvider::fromEnv();
            self::fail('Expected not configured');
        } catch (GeocodingException $exception) {
            self::assertSame(GeocodingException::NOT_CONFIGURED, $exception->reason);
        } finally {
            if ($previous === null) {
                unset($_ENV['PHOTON_URL']);
            } else {
                $_ENV['PHOTON_URL'] = $previous;
            }
        }
    }
}
