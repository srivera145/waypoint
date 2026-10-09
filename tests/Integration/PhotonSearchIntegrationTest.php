<?php

declare(strict_types=1);

namespace Tests\Integration;

use Keel\App\Services\Geocoding\PlaceCategory;
use Keel\Core\Env;
use Tests\TestCase;

/**
 * Against the Photon at PHOTON_URL (docker/photon with the Utah build), end to end through
 * GET /api/places. Skips itself when nothing answers there.
 */
class PhotonSearchIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $url = rtrim(trim((string) Env::get('PHOTON_URL', '')), '/');

        if ($url === '' || !$this->answers($url . '/status')) {
            self::markTestSkipped("No Photon answering at '{$url}/status'. Start docker/photon (see its README) to run these.");
        }
    }

    public function testParkCityFromSaltLakeCity(): void
    {
        $this->actingAs();

        $response = $this->getJson('/api/places/search?q=Park+City&lat=40.7608&lng=-111.891');
        $first = $response->json()['results'][0] ?? null;

        self::assertSame(200, $response->status, $response->body);
        self::assertSame('photon', $response->json()['provider']);
        self::assertSame('Park City', $first['label']);
        self::assertSame('Summit County, Utah', $first['secondary_label']);
        self::assertEqualsWithDelta(40.646, $first['lat'], 0.01);
        self::assertEqualsWithDelta(-111.498, $first['lng'], 0.01);
        self::assertMatchesRegularExpression('/^osm:[NWR]\d+$/', $first['source_ref']);
    }

    public function testBusinessesGetTheirCategory(): void
    {
        $this->actingAs();

        $categories = static fn (array $json): array => array_column($json['results'], 'category');

        self::assertContains(PlaceCategory::FUEL, $categories($this->getJson('/api/places/search?q=Chevron&lat=40.7608&lng=-111.891')->json()));
        self::assertContains(PlaceCategory::LODGING, $categories($this->getJson('/api/places/search?q=Motel+6&lat=40.7608&lng=-111.891')->json()));
        self::assertContains(PlaceCategory::CAMPGROUND, $categories($this->getJson('/api/places/search?q=camp+ground&lat=40.7608&lng=-111.891')->json()));
    }

    public function testReverseInDowntownSaltLakeCity(): void
    {
        $this->actingAs();

        $result = $this->getJson('/api/places/reverse?lat=40.7608&lng=-111.891')->json()['result'];

        self::assertStringContainsString('Salt Lake City', (string) $result['secondary_label']);
    }

    private function answers(string $url): bool
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return $status === 200 && is_string($body) && str_contains($body, '"status":"Ok"');
    }
}
