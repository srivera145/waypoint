<?php

namespace Keel\App\Services;

use Keel\App\Models\RoutePreference;
use Keel\App\Models\SavedRoute;
use Keel\App\Models\Vehicle;
use Keel\App\Services\Routing\RoutePlanner;
use Keel\App\Services\Routing\RoutingException;
use Keel\Core\Activity;

/**
 * Saving, renaming and deleting a user's saved routes.
 *
 * A route is saved by planning it again here and storing the option the user picked, matched
 * on its label and its polyline. The route cache answers a repeat within
 * ROUTING_CACHE_TTL_MINUTES, so this costs nothing in the usual case, and what is stored is
 * always a route Waypoint computed and explained, never one the browser described.
 */
class SavedRouteService
{
    public const NAME_MAX = 100;
    public const PLACE_LABEL_MAX = 200;

    /** The route planned again differs from the one the user saw: preferences or the map data changed. */
    public const CHANGED = 'changed';

    /** Made on first save, so renaming and deleting never depend on routing being set up. */
    public function __construct(private ?RoutePlanner $planner = null)
    {
    }

    /**
     * @param array{lat: float, lng: float, label: string} $origin
     * @param array{lat: float, lng: float, label: string} $destination
     * @param array $vehicle a vehicles row the user owns
     * @return int|string the new route's id, or CHANGED
     * @throws RoutingException when the route cannot be planned again, or routing is not configured
     */
    public function save(int $userId, array $origin, array $destination, array $vehicle, string $unitSystem, string $label, string $polyline, ?string $name): int|string
    {
        $this->planner ??= new RoutePlanner();
        $plan = $this->planner->plan($userId, $origin, $destination, $vehicle, $unitSystem);
        $option = null;

        foreach ($plan['options'] as $candidate) {
            if ($candidate['label'] === $label && $candidate['polyline'] === $polyline) {
                $option = $candidate;
                break;
            }
        }

        if ($option === null) {
            return self::CHANGED;
        }

        unset($option['ranking']);
        $preferences = RoutePreference::effectiveForVehicle((int) $vehicle['id'], $userId);
        $name = $name !== null && $name !== '' ? $name : self::defaultName($destination['label']);

        $id = SavedRoute::create($userId, [
            'vehicle_id' => (int) $vehicle['id'],
            'name' => $name,
            'provider' => $plan['provider'],
            'request' => [
                'origin' => $origin,
                'destination' => $destination,
                'vehicle_id' => (int) $vehicle['id'],
                'personality' => (string) ($preferences['personality'] ?? RoutePreference::DEFAULT_PERSONALITY),
            ],
            // Everything the saved route's page shows, as it was when saved.
            'summary' => [
                'option' => $option,
                'notices' => $plan['notices'],
                'vehicle' => [
                    'id' => (int) $vehicle['id'],
                    'name' => (string) $vehicle['name'],
                    'type' => (string) $vehicle['type'],
                    'height_m' => $vehicle['height_m'] === null ? null : (float) $vehicle['height_m'],
                    'length_m' => Vehicle::overallLength($vehicle),
                ],
            ],
            'polyline' => $option['polyline'],
            'distance_m' => (int) round($option['summary']['distance_m']),
            'duration_s' => (int) round($option['summary']['duration_s']),
        ]);

        if ($id === null) {
            throw new \RuntimeException('The vehicle for a saved route was not the user\'s.');
        }

        Activity::log('saved_route.created', 'SavedRoute', $id, ['label' => $label]);

        return $id;
    }

    /** False when the route is not the user's. */
    public function rename(int $userId, int $routeId, string $name): bool
    {
        if (!SavedRoute::rename($routeId, $userId, $name)) {
            return false;
        }

        Activity::log('saved_route.renamed', 'SavedRoute', $routeId);

        return true;
    }

    /** False when the route is not the user's. */
    public function delete(int $userId, int $routeId): bool
    {
        if (!SavedRoute::delete($routeId, $userId)) {
            return false;
        }

        Activity::log('saved_route.deleted', 'SavedRoute', $routeId);

        return true;
    }

    /** A name, trimmed, with runs of whitespace as one space; null when that leaves nothing. */
    public static function cleanName(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return $name === '' ? null : $name;
    }

    public static function defaultName(string $destinationLabel): string
    {
        return mb_substr('To ' . $destinationLabel, 0, self::NAME_MAX);
    }
}
