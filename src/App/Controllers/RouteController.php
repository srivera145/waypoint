<?php

namespace Keel\App\Controllers;

use Keel\App\Models\User;
use Keel\App\Models\Vehicle;
use Keel\App\Services\Routing\RoutePlanner;
use Keel\App\Services\Routing\RoutingException;
use Keel\App\Services\Units;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Env;
use Keel\Core\RateLimiter;
use Keel\Core\Request;
use Keel\Core\Session;

class RouteController extends Controller
{
    /**
     * POST /api/routes
     *   {"origin": {"lat": 40.76, "lng": -111.89}, "destination": {"lat": 40.65, "lng": -111.50}, "vehicle_id": 3}
     *
     * vehicle_id is optional and defaults to the active vehicle. Answers with up to three labelled
     * options, or an error: 422 for bad input or no route, 404 for a vehicle that is not theirs,
     * 429 over the per-user limit, 503 when routing is down.
     */
    public function store(Request $request): never
    {
        $userId = (int) Auth::id();
        $perMinute = max(1, (int) Env::get('ROUTING_RATE_LIMIT_PER_MINUTE', 20));

        if (!RateLimiter::attempt('routes|user:' . $userId, $perMinute, 1)) {
            $this->json(['error' => 'Too many route requests. Please wait a minute and try again.'], 429);
        }

        // Planning can take seconds; the user's place searches should not queue behind it.
        Session::release();

        $errors = [];
        $origin = $this->point($request->input('origin'), 'starting point', 'origin', $errors);
        $destination = $this->point($request->input('destination'), 'destination', 'destination', $errors);

        if ($origin !== null && $destination !== null && $origin == $destination) {
            $errors['destination'] = 'The destination is the same as the starting point.';
        }

        $vehicleId = $request->input('vehicle_id');
        $hasVehicleId = $vehicleId !== null && $vehicleId !== '';

        if ($hasVehicleId && filter_var($vehicleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $errors['vehicle_id'] = 'vehicle_id must be a vehicle id.';
        }

        if ($errors !== []) {
            $this->json(['error' => 'Check the route request.', 'errors' => $errors], 422);
        }

        $vehicle = $hasVehicleId ? Vehicle::findForUser((int) $vehicleId, $userId) : Vehicle::activeForUser($userId);

        if ($vehicle === null && $hasVehicleId) {
            $this->json(['error' => 'Vehicle not found.'], 404);
        }

        if ($vehicle === null) {
            $this->json(['error' => 'Add a vehicle before planning a route.'], 422);
        }

        $unitSystem = Units::normalizeSystem(User::find($userId)['unit_system'] ?? null);

        try {
            $payload = (new RoutePlanner())->plan($userId, $origin, $destination, $vehicle, $unitSystem);
        } catch (RoutingException $exception) {
            $this->json(['error' => $exception->getMessage(), 'reason' => $exception->reason], $exception->httpStatus());
        }

        $this->json($payload);
    }

    /** @return ?array{lat: float, lng: float} */
    private function point(mixed $value, string $name, string $field, array &$errors): ?array
    {
        $lat = is_array($value) ? ($value['lat'] ?? null) : null;
        $lng = is_array($value) ? ($value['lng'] ?? null) : null;

        if (!$this->isNumber($lat) || !$this->isNumber($lng)) {
            $errors[$field] = "Give the {$name} as {\"lat\": ..., \"lng\": ...}.";
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            $errors[$field] = "The {$name} is not a real place: latitude runs -90 to 90 and longitude -180 to 180.";
            return null;
        }

        return ['lat' => round($lat, 6), 'lng' => round($lng, 6)];
    }

    private function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)));
    }
}
