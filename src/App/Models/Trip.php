<?php

namespace Keel\App\Models;

use Keel\Core\Database;

class Trip
{
    public const STATUSES = ['planned', 'active', 'completed'];

    public static function forUser(int $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM trips WHERE user_id = ? ORDER BY departure_at IS NULL, departure_at DESC, id DESC'
        );
        $statement->execute([$userId]);

        return $statement->fetchAll();
    }

    public static function findForUser(int $id, int $userId): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM trips WHERE id = ? AND user_id = ? LIMIT 1');
        $statement->execute([$id, $userId]);

        return $statement->fetch() ?: null;
    }

    public static function forVehicle(int $vehicleId, int $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM trips WHERE vehicle_id = ? AND user_id = ? ORDER BY id DESC'
        );
        $statement->execute([$vehicleId, $userId]);

        return $statement->fetchAll();
    }

    /** Returns the new id, or null when vehicle_id is not one of the user's vehicles. */
    public static function create(int $userId, array $attributes): ?int
    {
        $vehicleId = (int) ($attributes['vehicle_id'] ?? 0);
        $status = (string) ($attributes['status'] ?? 'planned');

        self::assertStatus($status);

        if (Vehicle::findForUser($vehicleId, $userId) === null) {
            return null;
        }

        $statement = Database::connection()->prepare(
            'INSERT INTO trips (user_id, vehicle_id, name, status, departure_at) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$userId, $vehicleId, (string) $attributes['name'], $status, $attributes['departure_at'] ?? null]);

        return (int) Database::connection()->lastInsertId();
    }

    public static function updateStatus(int $id, int $userId, string $status): bool
    {
        self::assertStatus($status);

        $statement = Database::connection()->prepare('UPDATE trips SET status = ? WHERE id = ? AND user_id = ?');
        $statement->execute([$status, $id, $userId]);

        return $statement->rowCount() > 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        $statement = Database::connection()->prepare('DELETE FROM trips WHERE id = ? AND user_id = ?');
        $statement->execute([$id, $userId]);

        return $statement->rowCount() > 0;
    }

    public static function stops(int $tripId, int $userId): array
    {
        return TripStop::forTrip($tripId, $userId);
    }

    public static function vehicle(array $trip, int $userId): ?array
    {
        return Vehicle::findForUser((int) $trip['vehicle_id'], $userId);
    }

    public static function savedRoutes(int $tripId, int $userId): array
    {
        return SavedRoute::forTrip($tripId, $userId);
    }

    private static function assertStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown trip status: {$status}");
        }
    }
}
