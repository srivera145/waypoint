<?php

namespace Keel\App\Services\Geocoding;

use Keel\App\Models\PlaceCache;
use Keel\Core\Env;

/**
 * Place search and reverse lookup for one user, through the configured geocoder, with each
 * answer kept for PLACES_CACHE_TTL_MINUTES. Failures are never cached.
 *
 * Points are rounded before they reach the geocoder or the cache key, so nearby requests share
 * an entry and the geocoder never sees a precise position it does not need.
 */
class PlaceLookup
{
    /** About a kilometre: a search's bias point only has to say roughly where the user is. */
    private const NEAR_DECIMALS = 2;

    /** About ten metres, inside a phone's GPS error. */
    private const REVERSE_DECIMALS = 4;

    public const MAX_QUERY_LENGTH = 200;

    private GeocodingProvider $provider;

    /** @throws GeocodingException when no provider is configured */
    public function __construct(?GeocodingProvider $provider = null)
    {
        $this->provider = $provider ?? GeocodingProviders::resolve();
    }

    /**
     * @return array{results: list<array>, cached: bool} each result is a PlaceResult::toArray()
     * @throws GeocodingException
     */
    public function search(int $userId, string $query, ?float $nearLat, ?float $nearLng, int $limit): array
    {
        $query = self::normalizeQuery($query);
        $hasNear = $nearLat !== null && $nearLng !== null;
        $nearLat = $hasNear ? round($nearLat, self::NEAR_DECIMALS) : null;
        $nearLng = $hasNear ? round($nearLng, self::NEAR_DECIMALS) : null;

        $key = $this->key(['search', mb_strtolower($query), $nearLat, $nearLng, $limit]);
        $cached = PlaceCache::get($userId, $key);

        if ($cached !== null && is_array($cached['results'] ?? null)) {
            return ['results' => $cached['results'], 'cached' => true];
        }

        $results = array_map(
            static fn (PlaceResult $place): array => $place->toArray(),
            $this->provider->search($query, $nearLat, $nearLng, $limit),
        );

        PlaceCache::put($userId, $key, ['results' => $results], $this->ttlMinutes());

        return ['results' => $results, 'cached' => false];
    }

    /**
     * @return array{result: array, cached: bool} the result is a PlaceResult::toArray()
     * @throws GeocodingException
     */
    public function reverse(int $userId, float $lat, float $lng): array
    {
        $lat = round($lat, self::REVERSE_DECIMALS);
        $lng = round($lng, self::REVERSE_DECIMALS);

        $key = $this->key(['reverse', $lat, $lng]);
        $cached = PlaceCache::get($userId, $key);

        if ($cached !== null && is_array($cached['result'] ?? null)) {
            return ['result' => $cached['result'], 'cached' => true];
        }

        $result = $this->provider->reverse($lat, $lng)->toArray();
        PlaceCache::put($userId, $key, ['result' => $result], $this->ttlMinutes());

        return ['result' => $result, 'cached' => false];
    }

    public function providerName(): string
    {
        return $this->provider->name();
    }

    /** Trimmed, with every run of whitespace as one space. */
    public static function normalizeQuery(string $query): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $query));
    }

    private function key(array $parts): string
    {
        return hash('sha256', $this->provider->name() . '|' . json_encode($parts, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) Env::get('PLACES_CACHE_TTL_MINUTES', 5));
    }
}
