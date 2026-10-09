<?php

namespace Keel\App\Services\Routing;

use Keel\Core\Env;

/**
 * Routes through a self-hosted Valhalla server (docker/valhalla). The server's address and
 * timeouts come from VALHALLA_URL, VALHALLA_CONNECT_TIMEOUT and VALHALLA_TIMEOUT.
 *
 * The request body states only what Waypoint decided: a preference that is off leaves the
 * matching costing option out, so Valhalla's own default applies.
 */
class ValhallaProvider implements RoutingProvider
{
    public const NAME = 'valhalla';

    /** Waypoint vehicle type => Valhalla costing model. */
    public const COSTING = [
        'car' => 'auto',
        'suv' => 'auto',
        'pickup' => 'auto',
        'rv' => 'truck',
        'truck' => 'truck',
        'truck_trailer' => 'truck',
        'motorcycle' => 'motorcycle',
        'bicycle' => 'bicycle',
        'walking' => 'pedestrian',
    ];

    /** Costing models with use_tolls, use_highways and maneuver_penalty. */
    private const MOTOR_COSTINGS = ['auto', 'truck', 'motorcycle'];

    /** Costing models with use_hills, which avoid_mountains turns down. */
    private const HILL_COSTINGS = ['bicycle', 'pedestrian'];

    /** Valhalla reads every use_* option from 0 (avoid) to 1 (prefer); 0.5 is no preference. */
    private const AVOID = 0.0;

    /** "Easy": a little less highway than Valhalla's 0.5, and turns cost 15 s instead of 5 s. */
    private const EASY_USE_HIGHWAYS = 0.4;
    private const EASY_MANEUVER_PENALTY_S = 15;

    /** Valhalla error codes that mean "no route for these points", each with what to tell the user. */
    private const NO_ROUTE_ERRORS = [
        154 => 'Those points are too far apart for one route. Try adding a stop in between.',
        166 => 'Those points are too far apart for one route. Try adding a stop in between.',
        170 => 'No road connects those two points.',
        171 => 'One of those points is not near a road this vehicle can use.',
        172 => 'One of those points is not near a road this vehicle can use.',
        441 => 'No route was found between those points for this vehicle.',
        442 => 'No route was found between those points for this vehicle.',
    ];

    private string $baseUrl;

    /** @var \Closure(string $url, string $body, int $connectTimeoutS, int $timeoutS): array{status: int, body: ?string, error: string} */
    private \Closure $transport;

    /**
     * @param ?\Closure $transport sends one POST; tests pass a fake. Returns the HTTP status, the
     *        body (null when nothing came back) and a transport error ('' on success).
     */
    public function __construct(
        string $baseUrl,
        private int $connectTimeoutS = 3,
        private int $timeoutS = 20,
        ?\Closure $transport = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? $this->curl(...);
    }

    public static function fromEnv(): self
    {
        $url = trim((string) Env::get('VALHALLA_URL', ''));

        if ($url === '') {
            error_log('[Keel] Routing: VALHALLA_URL is not set.');
            throw RoutingException::notConfigured();
        }

        return new self(
            $url,
            max(1, (int) Env::get('VALHALLA_CONNECT_TIMEOUT', 3)),
            max(1, (int) Env::get('VALHALLA_TIMEOUT', 20)),
        );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public static function costingFor(string $vehicleType): string
    {
        return self::COSTING[$vehicleType] ?? self::COSTING['car'];
    }

    public function route(RouteRequest $request): array
    {
        $response = $this->post('/route', $this->routeBody($request));
        $trips = [$response['trip'] ?? null];

        foreach ((array) ($response['alternates'] ?? []) as $alternate) {
            $trips[] = is_array($alternate) ? ($alternate['trip'] ?? null) : null;
        }

        return array_map(fn (mixed $trip): RouteResult => $this->routeFromTrip($trip), $trips);
    }

    public function vehicleLimits(RouteRequest $request): array
    {
        $limits = match (self::costingFor($request->vehicleType)) {
            'truck' => [
                'height_m' => $request->heightM,
                'width_m' => $request->widthM,
                'length_m' => $request->lengthM,
                'weight_kg' => $request->weightKg,
                'axle_load_kg' => $request->axleLoadKg,
            ],
            'auto' => ['height_m' => $request->heightM, 'width_m' => $request->widthM],
            default => [],
        };

        return array_filter($limits, static fn (?float $value): bool => $value !== null);
    }

    public function elevation(string $polyline, float $sampleSpacingM = 50.0): ElevationProfile
    {
        $response = $this->post('/height', [
            'encoded_polyline' => $polyline,
            'shape_format' => 'polyline6',
            'range' => true,
            'resample_distance' => $sampleSpacingM,
            'height_precision' => 1,
        ]);

        $rows = $response['range_height'] ?? null;

        if (!is_array($rows) || count($rows) < 2) {
            $this->logFailure('Valhalla /height response had no range_height', ['rows' => is_array($rows) ? count($rows) : null]);
            throw RoutingException::unavailable();
        }

        $samples = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row[0]) || !is_numeric($row[0])) {
                throw RoutingException::unavailable();
            }

            $samples[] = [(float) $row[0], isset($row[1]) && is_numeric($row[1]) ? (float) $row[1] : null];
        }

        return new ElevationProfile($samples, $sampleSpacingM);
    }

    /** The /route body for a request. Public so tests can inspect exactly what is sent. */
    public function routeBody(RouteRequest $request): array
    {
        $costing = self::costingFor($request->vehicleType);

        return [
            'locations' => [
                ['lat' => $request->originLat, 'lon' => $request->originLng],
                ['lat' => $request->destinationLat, 'lon' => $request->destinationLng],
            ],
            'costing' => $costing,
            'costing_options' => [$costing => (object) $this->costingOptions($costing, $request)],
            'alternates' => $request->alternates,
            'units' => 'kilometers',
            'directions_type' => 'none',
        ];
    }

    private function costingOptions(string $costing, RouteRequest $request): array
    {
        $options = [];

        if ($costing === 'truck') {
            // Valhalla takes metres and metric tons.
            $options += array_filter([
                'height' => $request->heightM,
                'width' => $request->widthM,
                'length' => $request->lengthM,
                'weight' => $request->weightKg === null ? null : round($request->weightKg / 1000, 2),
                'axle_load' => $request->axleLoadKg === null ? null : round($request->axleLoadKg / 1000, 2),
                'axle_count' => $request->axleCount,
            ], static fn (mixed $value): bool => $value !== null);
        } elseif ($costing === 'auto') {
            // Auto costing checks height and width limits too; a pickup with a camper is tall.
            $options += array_filter(['height' => $request->heightM, 'width' => $request->widthM], static fn (mixed $value): bool => $value !== null);
        }

        if (in_array($costing, self::MOTOR_COSTINGS, true)) {
            if ($request->avoidTolls) {
                $options['use_tolls'] = self::AVOID;
            }

            if ($request->avoidHighways) {
                $options['use_highways'] = self::AVOID;
            } elseif ($request->personality === 'easy') {
                $options['use_highways'] = self::EASY_USE_HIGHWAYS;
            }

            if ($request->personality === 'easy') {
                $options['maneuver_penalty'] = self::EASY_MANEUVER_PENALTY_S;
            }
        }

        if ($request->avoidFerries) {
            $options['use_ferry'] = self::AVOID;
        }

        if ($request->avoidMountains && in_array($costing, self::HILL_COSTINGS, true)) {
            $options['use_hills'] = self::AVOID;
        }

        return $options;
    }

    private function routeFromTrip(mixed $trip): RouteResult
    {
        $summary = is_array($trip) ? ($trip['summary'] ?? null) : null;
        $legs = is_array($trip) ? ($trip['legs'] ?? null) : null;

        if (!is_array($summary) || !is_array($legs) || $legs === [] || !isset($summary['length'], $summary['time'])) {
            $this->logFailure('Valhalla /route trip was missing its summary or legs', []);
            throw RoutingException::unavailable();
        }

        try {
            $points = [];

            foreach ($legs as $leg) {
                $legPoints = Polyline::decode((string) ($leg['shape'] ?? ''));

                // Each leg starts where the last one ended; keep that point once.
                if ($points !== [] && $legPoints !== [] && $legPoints[0] === $points[count($points) - 1]) {
                    array_shift($legPoints);
                }

                array_push($points, ...$legPoints);
            }
        } catch (\InvalidArgumentException $exception) {
            $this->logFailure('Valhalla returned an undecodable shape', ['error' => $exception->getMessage()]);
            throw RoutingException::unavailable(previous: $exception);
        }

        if (count($points) < 2) {
            $this->logFailure('Valhalla returned a route with fewer than two shape points', []);
            throw RoutingException::unavailable();
        }

        $warnings = [];
        foreach ((array) ($trip['warnings'] ?? []) as $warning) {
            $text = trim((string) (is_array($warning) ? ($warning['text'] ?? '') : $warning));

            if ($text !== '') {
                $warnings[] = $text;
            }
        }

        return new RouteResult(
            polyline: count($legs) === 1 ? (string) $legs[0]['shape'] : Polyline::encode($points),
            distanceM: round((float) $summary['length'] * 1000, 1),
            durationS: (float) $summary['time'],
            hasToll: (bool) ($summary['has_toll'] ?? false),
            hasHighway: (bool) ($summary['has_highway'] ?? false),
            hasFerry: (bool) ($summary['has_ferry'] ?? false),
            hasTimeRestrictions: (bool) ($summary['has_time_restrictions'] ?? false),
            warnings: $warnings,
        );
    }

    private function post(string $path, array $body): array
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        if ($json === false) {
            throw RoutingException::unavailable();
        }

        $result = ($this->transport)($this->baseUrl . $path, $json, $this->connectTimeoutS, $this->timeoutS);
        $status = (int) ($result['status'] ?? 0);
        $raw = $result['body'] ?? null;

        if (!is_string($raw) || ($result['error'] ?? '') !== '') {
            $this->logFailure('Valhalla request failed', ['path' => $path, 'status' => $status, 'error' => $result['error'] ?? '']);
            throw RoutingException::unavailable();
        }

        $decoded = json_decode($raw, true);

        if ($status === 200 && is_array($decoded)) {
            return $decoded;
        }

        $code = is_array($decoded) ? (int) ($decoded['error_code'] ?? 0) : 0;

        if ($status >= 400 && $status < 500 && isset(self::NO_ROUTE_ERRORS[$code])) {
            throw RoutingException::noRoute(self::NO_ROUTE_ERRORS[$code]);
        }

        $this->logFailure('Valhalla returned an error', [
            'path' => $path,
            'status' => $status,
            'error_code' => $code,
            'error' => is_array($decoded) ? ($decoded['error'] ?? null) : substr($raw, 0, 200),
        ]);

        throw RoutingException::unavailable();
    }

    /** @return array{status: int, body: ?string, error: string} */
    private function curl(string $url, string $body, int $connectTimeoutS, int $timeoutS): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
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
        error_log('[Keel] Routing: ' . $message . ': ' . json_encode($context));
    }
}
