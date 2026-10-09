<?php

namespace Keel\App\Controllers;

use Keel\App\Services\Geocoding\GeocodingException;
use Keel\App\Services\Geocoding\PlaceLookup;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Env;
use Keel\Core\RateLimiter;
use Keel\Core\Request;
use Keel\Core\Session;

/**
 * Place search for the map screen's search box, and reverse lookup for "where am I".
 * Session-authenticated JSON like POST /api/routes; both are GETs, so they need no CSRF token.
 *
 * Errors: 422 for bad input, 429 over the per-user limit, 503 when the geocoder is down.
 * No results is not an error: it is a 200 with an empty list.
 */
class PlaceController extends Controller
{
    private const DEFAULT_LIMIT = 6;
    private const MAX_LIMIT = 10;

    /** GET /api/places/search?q=park+city&lat=40.76&lng=-111.89 (lat and lng optional, together) */
    public function search(Request $request): never
    {
        $userId = $this->admit();
        $errors = [];

        $query = is_string($request->input('q')) ? PlaceLookup::normalizeQuery($request->input('q')) : '';

        if ($query === '') {
            $errors['q'] = 'Type a place, address or business to search for.';
        } elseif (mb_strlen($query) > PlaceLookup::MAX_QUERY_LENGTH) {
            $errors['q'] = 'Search for at most ' . PlaceLookup::MAX_QUERY_LENGTH . ' characters.';
        }

        $near = $this->point($request, 'lat', 'lng', false, $errors);

        $limit = $request->input('limit');
        if ($limit === null || $limit === '') {
            $limit = self::DEFAULT_LIMIT;
        } elseif (filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_LIMIT]]) === false) {
            $errors['limit'] = 'limit must be a whole number from 1 to ' . self::MAX_LIMIT . '.';
        }

        if ($errors !== []) {
            $this->json(['error' => 'Check the search.', 'errors' => $errors], 422);
        }

        try {
            $lookup = new PlaceLookup();
            $answer = $lookup->search($userId, $query, $near['lat'] ?? null, $near['lng'] ?? null, (int) $limit);
        } catch (GeocodingException $exception) {
            $this->json(['error' => $exception->getMessage(), 'reason' => $exception->reason], $exception->httpStatus());
        }

        $this->json(['query' => $query, 'provider' => $lookup->providerName(), 'cached' => $answer['cached'], 'results' => $answer['results']]);
    }

    /** GET /api/places/reverse?lat=40.76&lng=-111.89 */
    public function reverse(Request $request): never
    {
        $userId = $this->admit();
        $errors = [];
        $point = $this->point($request, 'lat', 'lng', true, $errors);

        if ($errors !== []) {
            $this->json(['error' => 'Check the location.', 'errors' => $errors], 422);
        }

        try {
            $lookup = new PlaceLookup();
            $answer = $lookup->reverse($userId, $point['lat'], $point['lng']);
        } catch (GeocodingException $exception) {
            $this->json(['error' => $exception->getMessage(), 'reason' => $exception->reason], $exception->httpStatus());
        }

        $this->json(['provider' => $lookup->providerName(), 'cached' => $answer['cached'], 'result' => $answer['result']]);
    }

    /** Applies the per-user limit, then lets go of the session so typing never queues behind a route. */
    private function admit(): int
    {
        $userId = (int) Auth::id();
        $perMinute = max(1, (int) Env::get('PLACES_RATE_LIMIT_PER_MINUTE', 60));

        if (!RateLimiter::attempt('places|user:' . $userId, $perMinute, 1)) {
            $this->json(['error' => 'Too many searches. Please wait a minute and try again.'], 429);
        }

        Session::release();

        return $userId;
    }

    /** @return ?array{lat: float, lng: float} null when optional and absent, or when invalid */
    private function point(Request $request, string $latKey, string $lngKey, bool $required, array &$errors): ?array
    {
        $lat = $request->input($latKey);
        $lng = $request->input($lngKey);
        $absent = static fn (mixed $value): bool => $value === null || $value === '';

        if (!$required && $absent($lat) && $absent($lng)) {
            return null;
        }

        if (!is_numeric($lat) || !is_numeric($lng)) {
            $errors[$latKey] = "Give both {$latKey} and {$lngKey} as numbers.";
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            $errors[$latKey] = 'That is not a real place: latitude runs -90 to 90 and longitude -180 to 180.';
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }
}
