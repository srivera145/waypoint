<?php

declare(strict_types=1);

namespace Tests\Support;

use Keel\App\Services\Geocoding\GeocodingException;
use Keel\App\Services\Geocoding\GeocodingProvider;
use Keel\App\Services\Geocoding\PlaceResult;

/** A geocoder that answers from a fixed list, records what it was asked, and can be made to fail. */
class FakeGeocodingProvider implements GeocodingProvider
{
    /** @var list<array{query: string, lat: ?float, lng: ?float, limit: int}> */
    public array $searches = [];

    /** @var list<array{lat: float, lng: float}> */
    public array $reverses = [];

    /** @param list<PlaceResult> $places */
    public function __construct(private array $places = [], private ?GeocodingException $failure = null)
    {
    }

    public static function down(): self
    {
        return new self([], GeocodingException::unavailable());
    }

    public function name(): string
    {
        return 'fake';
    }

    public function search(string $query, ?float $nearLat, ?float $nearLng, int $limit): array
    {
        $this->searches[] = ['query' => $query, 'lat' => $nearLat, 'lng' => $nearLng, 'limit' => $limit];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $matches = array_filter($this->places, static fn (PlaceResult $place): bool => stripos($place->label, $query) !== false);

        return array_slice(array_values($matches), 0, $limit);
    }

    public function reverse(float $lat, float $lng): PlaceResult
    {
        $this->reverses[] = ['lat' => $lat, 'lng' => $lng];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->places[0] ?? PlaceResult::unnamed($lat, $lng);
    }
}
