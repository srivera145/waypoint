<?php

namespace Keel\App\Models;

use Keel\Core\Database;

class SavedRoute
{
    /** Lists leave out the polyline and JSON blobs; findForUser() returns the whole row. */
    private const LIST_COLUMNS = 'id, user_id, trip_id, vehicle_id, name, provider, distance_m, duration_s, created_at, updated_at';

    public static function forUser(int $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::LIST_COLUMNS . ' FROM saved_routes WHERE user_id = ? ORDER BY id DESC'
        );
        $statement->execute([$userId]);

        return $statement->fetchAll();
    }

    public static function findForUser(int $id, int $userId): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM saved_routes WHERE id = ? AND user_id = ? LIMIT 1');
        $statement->execute([$id, $userId]);

        return $statement->fetch() ?: null;
    }

    public static function forTrip(int $tripId, int $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::LIST_COLUMNS . ' FROM saved_routes WHERE trip_id = ? AND user_id = ? ORDER BY id DESC'
        );
        $statement->execute([$tripId, $userId]);

        return $statement->fetchAll();
    }

    public static function forVehicle(int $vehicleId, int $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::LIST_COLUMNS . ' FROM saved_routes WHERE vehicle_id = ? AND user_id = ? ORDER BY id DESC'
        );
        $statement->execute([$vehicleId, $userId]);

        return $statement->fetchAll();
    }

    /**
     * request and summary are arrays, stored as JSON. Returns the new id, or null when the
     * vehicle, or the trip if one is given, is not the user's.
     */
    public static function create(int $userId, array $attributes): ?int
    {
        $vehicleId = (int) ($attributes['vehicle_id'] ?? 0);
        $tripId = isset($attributes['trip_id']) ? (int) $attributes['trip_id'] : null;

        if (Vehicle::findForUser($vehicleId, $userId) === null) {
            return null;
        }

        if ($tripId !== null && Trip::findForUser($tripId, $userId) === null) {
            return null;
        }

        $statement = Database::connection()->prepare(
            'INSERT INTO saved_routes (user_id, trip_id, vehicle_id, name, provider, request_json, summary_json, polyline, distance_m, duration_s)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $userId,
            $tripId,
            $vehicleId,
            (string) $attributes['name'],
            (string) $attributes['provider'],
            json_encode($attributes['request'] ?? [], JSON_THROW_ON_ERROR),
            json_encode($attributes['summary'] ?? [], JSON_THROW_ON_ERROR),
            (string) $attributes['polyline'],
            (int) $attributes['distance_m'],
            (int) $attributes['duration_s'],
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /** False when the route is not the user's. Renaming to the same name still counts as found. */
    public static function rename(int $id, int $userId, string $name): bool
    {
        $statement = Database::connection()->prepare('UPDATE saved_routes SET name = ? WHERE id = ? AND user_id = ?');
        $statement->execute([$name, $id, $userId]);

        return $statement->rowCount() > 0 || self::findForUser($id, $userId) !== null;
    }

    public static function delete(int $id, int $userId): bool
    {
        $statement = Database::connection()->prepare('DELETE FROM saved_routes WHERE id = ? AND user_id = ?');
        $statement->execute([$id, $userId]);

        return $statement->rowCount() > 0;
    }

    public static function trip(array $route, int $userId): ?array
    {
        return $route['trip_id'] === null ? null : Trip::findForUser((int) $route['trip_id'], $userId);
    }

    public static function vehicle(array $route, int $userId): ?array
    {
        return Vehicle::findForUser((int) $route['vehicle_id'], $userId);
    }
}
