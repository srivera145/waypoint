<?php

namespace Keel\App\Services\Geocoding;

/**
 * A place search engine. Photon is the one Waypoint runs today; another self-hosted geocoder
 * (Pelias, Nominatim) can replace it by implementing this and being named in
 * GEOCODING_PROVIDER.
 */
interface GeocodingProvider
{
    /** A short stable name, part of the cache key. */
    public function name(): string;

    /**
     * Places matching $query, best first. Results near ($nearLat, $nearLng) rank higher when
     * a point is given; both are null otherwise.
     *
     * @return list<PlaceResult> empty when nothing matches
     * @throws GeocodingException when the engine is unreachable or answers with something unreadable
     */
    public function search(string $query, ?float $nearLat, ?float $nearLng, int $limit): array;

    /**
     * The nearest named place or address to a point, or PlaceResult::unnamed() when there is
     * nothing near it.
     *
     * @throws GeocodingException when the engine is unreachable or answers with something unreadable
     */
    public function reverse(float $lat, float $lng): PlaceResult;
}
