<?php

namespace Keel\App\Services\Geocoding;

use Keel\Core\Env;

/**
 * Searches a self-hosted Photon server (docker/photon). Its address, timeouts and result
 * language come from PHOTON_URL, PHOTON_CONNECT_TIMEOUT, PHOTON_TIMEOUT and PHOTON_LANGUAGE.
 *
 * Photon answers GeoJSON: one Feature per place, with the OSM tag and the address parts in
 * its properties. Those become a PlaceResult's two labels and its category.
 */
class PhotonProvider implements GeocodingProvider
{
    public const NAME = 'photon';

    /** Photon types that name an area: their county places them better than a town would. */
    private const AREA_TYPES = ['city', 'locality', 'district', 'county'];

    /** Photon types that are a street address, which read with a postcode. */
    private const ADDRESS_TYPES = ['house', 'street'];

    private string $baseUrl;

    /** @var \Closure(string $url, int $connectTimeoutS, int $timeoutS): array{status: int, body: ?string, error: string} */
    private \Closure $transport;

    /**
     * @param ?\Closure $transport sends one GET; tests pass a fake. Returns the HTTP status, the
     *        body (null when nothing came back) and a transport error ('' on success).
     */
    public function __construct(
        string $baseUrl,
        private int $connectTimeoutS = 2,
        private int $timeoutS = 5,
        private string $language = 'en',
        ?\Closure $transport = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? $this->curl(...);
    }

    public static function fromEnv(): self
    {
        $url = trim((string) Env::get('PHOTON_URL', ''));

        if ($url === '') {
            error_log('[Keel] Geocoding: PHOTON_URL is not set.');
            throw GeocodingException::notConfigured();
        }

        return new self(
            $url,
            max(1, (int) Env::get('PHOTON_CONNECT_TIMEOUT', 2)),
            max(1, (int) Env::get('PHOTON_TIMEOUT', 5)),
            trim((string) Env::get('PHOTON_LANGUAGE', 'en')) ?: 'en',
        );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function search(string $query, ?float $nearLat, ?float $nearLng, int $limit): array
    {
        $parameters = ['q' => $query, 'limit' => max(1, $limit), 'lang' => $this->language];

        if ($nearLat !== null && $nearLng !== null) {
            $parameters += ['lat' => $nearLat, 'lon' => $nearLng];
        }

        $places = [];

        foreach ($this->features('/api', $parameters) as $feature) {
            $place = $this->placeFromFeature($feature);

            if ($place !== null) {
                $places[] = $place;
            }
        }

        return $places;
    }

    public function reverse(float $lat, float $lng): PlaceResult
    {
        foreach ($this->features('/reverse', ['lat' => $lat, 'lon' => $lng, 'limit' => 1, 'lang' => $this->language]) as $feature) {
            $place = $this->placeFromFeature($feature);

            if ($place !== null) {
                return $place;
            }
        }

        return PlaceResult::unnamed($lat, $lng);
    }

    /** One Photon Feature as a PlaceResult, or null when it has no usable point or name. */
    public function placeFromFeature(mixed $feature): ?PlaceResult
    {
        $coordinates = is_array($feature) ? ($feature['geometry']['coordinates'] ?? null) : null;
        $properties = is_array($feature) && is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

        if (!is_array($coordinates) || !is_numeric($coordinates[0] ?? null) || !is_numeric($coordinates[1] ?? null)) {
            return null;
        }

        $lng = (float) $coordinates[0];
        $lat = (float) $coordinates[1];

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        $text = static fn (string $key): ?string => is_scalar($properties[$key] ?? null) && trim((string) $properties[$key]) !== ''
            ? trim((string) $properties[$key])
            : null;

        $type = $text('type');
        $street = $text('street');
        $number = $text('housenumber');
        $streetLine = $street === null ? null : trim(($number ?? '') . ' ' . $street);
        $town = $text('city') ?? $text('locality') ?? $text('district');
        $state = $text('state');
        $isUs = strtoupper((string) $text('countrycode')) === 'US';
        $county = $text('county');

        // OSM names most US counties bare ("Summit"); "Summit County" is how people say it.
        if ($county !== null && $isUs && !preg_match('/\b(County|Parish|Borough|Census Area|Municipality|City)$/i', $county)) {
            $county .= ' County';
        }

        $label = $text('name') ?? $streetLine ?? $town ?? $county ?? $state;

        if ($label === null) {
            return null;
        }

        if ($state !== null && in_array($type, self::ADDRESS_TYPES, true) && $text('postcode') !== null) {
            $state .= ' ' . $text('postcode');
        }

        $parts = [
            $label === $text('name') ? $streetLine : null,
            $town,
            $town === null || in_array($type, self::AREA_TYPES, true) ? $county : null,
            $state,
            $isUs ? null : $text('country'),
        ];

        $secondary = [];
        foreach ($parts as $part) {
            if ($part !== null && $part !== $label && !in_array($part, $secondary, true)) {
                $secondary[] = $part;
            }
        }

        $osmType = $text('osm_type');
        $osmId = $text('osm_id');

        return new PlaceResult(
            label: $label,
            secondaryLabel: $secondary === [] ? null : implode(', ', $secondary),
            lat: round($lat, 7),
            lng: round($lng, 7),
            category: PlaceCategory::fromOsm($text('osm_key'), $text('osm_value')),
            sourceRef: $osmType !== null && $osmId !== null ? 'osm:' . $osmType . $osmId : null,
        );
    }

    /** @return list<mixed> the response's features */
    private function features(string $path, array $parameters): array
    {
        $url = $this->baseUrl . $path . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $result = ($this->transport)($url, $this->connectTimeoutS, $this->timeoutS);
        $status = (int) ($result['status'] ?? 0);
        $raw = $result['body'] ?? null;

        if (!is_string($raw) || ($result['error'] ?? '') !== '') {
            $this->logFailure('Photon request failed', ['path' => $path, 'status' => $status, 'error' => $result['error'] ?? '']);
            throw GeocodingException::unavailable();
        }

        $decoded = json_decode($raw, true);

        if ($status !== 200 || !is_array($decoded) || !is_array($decoded['features'] ?? null)) {
            $this->logFailure('Photon returned an error', [
                'path' => $path,
                'status' => $status,
                'error' => is_array($decoded) ? ($decoded['message'] ?? null) : substr($raw, 0, 200),
            ]);
            throw GeocodingException::unavailable();
        }

        return array_values($decoded['features']);
    }

    /** @return array{status: int, body: ?string, error: string} */
    private function curl(string $url, int $connectTimeoutS, int $timeoutS): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutS,
            CURLOPT_TIMEOUT => $timeoutS,
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        return ['status' => $status, 'body' => is_string($response) ? $response : null, 'error' => $error];
    }

    private function logFailure(string $message, array $context): void
    {
        error_log('[Keel] Geocoding: ' . $message . ': ' . json_encode($context));
    }
}
