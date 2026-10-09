<?php

namespace Keel\App\Controllers;

use Keel\App\Models\SavedRoute;
use Keel\App\Models\User;
use Keel\App\Models\Vehicle;
use Keel\App\Services\Maps\MapTiles;
use Keel\App\Services\Routing\RouteRanker;
use Keel\App\Services\Routing\RoutingException;
use Keel\App\Services\SavedRouteService;
use Keel\App\Services\Units;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Env;
use Keel\Core\ErrorHandler;
use Keel\Core\RateLimiter;
use Keel\Core\Request;
use Keel\Core\Session;

/**
 * The saved routes screen: list, view on the map, rename, delete. Saving happens from the map
 * screen through POST /api/saved-routes. Another user's route is a 404 everywhere.
 */
class SavedRouteController extends Controller
{
    /** ?status= is looked up here, never echoed, so a link cannot put words on the page. */
    private const STATUS_MESSAGES = [
        'renamed' => 'Route renamed.',
        'deleted' => 'Saved route deleted.',
    ];

    public function index(Request $request): void
    {
        $user = $this->currentUser();
        $userId = (int) $user['id'];

        $this->view('routes.index', [
            'title' => 'Saved routes',
            'routes' => SavedRoute::forUser($userId),
            'vehicles' => array_column(Vehicle::forUser($userId), null, 'id'),
            'unitSystem' => Units::normalizeSystem($user['unit_system'] ?? null),
            'status' => self::STATUS_MESSAGES[(string) $request->input('status', '')] ?? null,
        ]);
    }

    public function show(Request $request, string $id): void
    {
        $user = $this->currentUser();
        $route = $this->findOwnedOr404($id, (int) $user['id']);
        $status = self::STATUS_MESSAGES[(string) $request->input('status', '')] ?? null;

        $this->renderShow($user, $route, (string) $route['name'], null, $status);
    }

    /**
     * POST /api/saved-routes, session JSON with X-CSRF-Token:
     *   {"origin": {"lat": 40.76, "lng": -111.89, "label": "Salt Lake City"},
     *    "destination": {"lat": 40.65, "lng": -111.50, "label": "Park City"},
     *    "vehicle_id": 3, "label": "vehicle_friendly", "polyline": "...", "name": "optional"}
     *
     * 201 with the new id; 409 when the route planned again no longer matches the one shown;
     * otherwise the same errors as POST /api/routes.
     */
    public function store(Request $request): never
    {
        $userId = (int) Auth::id();
        $perMinute = max(1, (int) Env::get('ROUTING_RATE_LIMIT_PER_MINUTE', 20));

        if (!RateLimiter::attempt('routes|user:' . $userId, $perMinute, 1)) {
            $this->json(['error' => 'Too many route requests. Please wait a minute and try again.'], 429);
        }

        Session::release();

        $errors = [];
        $origin = $this->place($request->input('origin'), 'starting point', 'origin', $errors);
        $destination = $this->place($request->input('destination'), 'destination', 'destination', $errors);

        $vehicleId = $request->input('vehicle_id');
        if (filter_var($vehicleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $errors['vehicle_id'] = 'vehicle_id must be a vehicle id.';
        }

        $label = $request->input('label');
        if (!is_string($label) || !isset(RouteRanker::LABELS[$label])) {
            $errors['label'] = 'label must be one of: ' . implode(', ', array_keys(RouteRanker::LABELS)) . '.';
        }

        $polyline = $request->input('polyline');
        if (!is_string($polyline) || $polyline === '' || strlen($polyline) > 1000000) {
            $errors['polyline'] = 'polyline must be the route\'s encoded polyline.';
        }

        $name = SavedRouteService::cleanName($request->input('name'));
        if ($name !== null && mb_strlen($name) > SavedRouteService::NAME_MAX) {
            $errors['name'] = 'Keep the name to ' . SavedRouteService::NAME_MAX . ' characters or fewer.';
        }

        if ($errors !== []) {
            $this->json(['error' => 'Check the route to save.', 'errors' => $errors], 422);
        }

        $vehicle = Vehicle::findForUser((int) $vehicleId, $userId);

        if ($vehicle === null) {
            $this->json(['error' => 'Vehicle not found.'], 404);
        }

        $unitSystem = Units::normalizeSystem(User::find($userId)['unit_system'] ?? null);

        try {
            $result = (new SavedRouteService())->save($userId, $origin, $destination, $vehicle, $unitSystem, $label, $polyline, $name);
        } catch (RoutingException $exception) {
            $this->json(['error' => $exception->getMessage(), 'reason' => $exception->reason], $exception->httpStatus());
        }

        if ($result === SavedRouteService::CHANGED) {
            $this->json([
                'error' => 'This route has changed since it was planned, so it was not saved. Plan it again, then save.',
                'reason' => SavedRouteService::CHANGED,
            ], 409);
        }

        $saved = SavedRoute::findForUser($result, $userId);

        $this->json(['id' => $result, 'name' => (string) $saved['name'], 'url' => '/routes/' . $result], 201);
    }

    public function rename(Request $request, string $id): void
    {
        $user = $this->currentUser();
        $route = $this->findOwnedOr404($id, (int) $user['id']);
        $raw = $request->input('name');
        $name = SavedRouteService::cleanName($raw);

        $error = match (true) {
            $name === null => 'Give this route a name.',
            mb_strlen($name) > SavedRouteService::NAME_MAX => 'Keep the name to ' . SavedRouteService::NAME_MAX . ' characters or fewer.',
            default => null,
        };

        if ($error !== null) {
            http_response_code(422);
            $this->renderShow($user, $route, is_string($raw) ? $raw : '', $error, null);
            return;
        }

        if (!(new SavedRouteService())->rename((int) $user['id'], (int) $route['id'], $name)) {
            ErrorHandler::render(404);
        }

        $this->redirect('/routes/' . (int) $route['id'] . '?status=renamed');
    }

    public function destroy(Request $request, string $id): void
    {
        $user = $this->currentUser();
        $route = $this->findOwnedOr404($id, (int) $user['id']);

        if (!(new SavedRouteService())->delete((int) $user['id'], (int) $route['id'])) {
            ErrorHandler::render(404);
        }

        $this->redirect('/routes?status=deleted');
    }

    private function renderShow(array $user, array $route, string $nameValue, ?string $nameError, ?string $status): void
    {
        $request = json_decode((string) $route['request_json'], true);
        $summary = json_decode((string) $route['summary_json'], true);

        $this->view('routes.show', [
            'title' => (string) $route['name'],
            'route' => $route,
            'request' => is_array($request) ? $request : [],
            'summary' => is_array($summary) ? $summary : [],
            'unitSystem' => Units::normalizeSystem($user['unit_system'] ?? null),
            'tilesUrl' => MapTiles::browserUrl(),
            'nameValue' => $nameValue,
            'nameError' => $nameError,
            'status' => $status,
        ]);
    }

    /**
     * A {"lat": ..., "lng": ..., "label": ...} from the request body, rounded like POST
     * /api/routes rounds its points so the plan matches the cached one.
     *
     * @return ?array{lat: float, lng: float, label: string}
     */
    private function place(mixed $value, string $name, string $field, array &$errors): ?array
    {
        $lat = is_array($value) ? ($value['lat'] ?? null) : null;
        $lng = is_array($value) ? ($value['lng'] ?? null) : null;
        $label = is_array($value) ? SavedRouteService::cleanName($value['label'] ?? null) : null;

        if (!is_numeric($lat) || !is_numeric($lng) || (float) $lat < -90 || (float) $lat > 90 || (float) $lng < -180 || (float) $lng > 180) {
            $errors[$field] = "Give the {$name} as {\"lat\": ..., \"lng\": ..., \"label\": ...}.";
            return null;
        }

        if ($label === null || mb_strlen($label) > SavedRouteService::PLACE_LABEL_MAX) {
            $errors[$field] = "Give the {$name} a label of at most " . SavedRouteService::PLACE_LABEL_MAX . ' characters.';
            return null;
        }

        return ['lat' => round((float) $lat, 6), 'lng' => round((float) $lng, 6), 'label' => $label];
    }

    /** Another user's route is indistinguishable from one that does not exist. */
    private function findOwnedOr404(string $id, int $userId): array
    {
        $route = ctype_digit($id) ? SavedRoute::findForUser((int) $id, $userId) : null;

        if ($route === null) {
            ErrorHandler::render(404);
        }

        return $route;
    }

    private function currentUser(): array
    {
        $user = User::find((int) Auth::id());

        if ($user === null) {
            throw new \RuntimeException('Authenticated user could not be resolved.');
        }

        return $user;
    }
}
