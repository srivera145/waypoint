<?php

namespace Keel\App\Models;

use Keel\Core\Database;

/**
 * trip_stops has no user_id of its own: a stop belongs to whoever owns its trip, so every
 * query joins trips and filters on trips.user_id.
 */
class TripStop
{
    public static function forTrip(int $tripId, int $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT s.* FROM trip_stops s
             JOIN trips t ON t.id = s.trip_id
             WHERE s.trip_id = ? AND t.user_id = ?
             ORDER BY s.position ASC, s.id ASC'
        );
        $statement->execute([$tripId, $userId]);

        return $statement->fetchAll();
    }

    public static function findForUser(int $id, int $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT s.* FROM trip_stops s
             JOIN trips t ON t.id = s.trip_id
             WHERE s.id = ? AND t.user_id = ?
             LIMIT 1'
        );
        $statement->execute([$id, $userId]);

        return $statement->fetch() ?: null;
    }

    /**
     * Appends a stop, or places it at $attributes['position'] when given. Returns the new id,
     * or null when the trip is not the user's.
     */
    public static function create(int $tripId, int $userId, array $attributes): ?int
    {
        if (Trip::findForUser($tripId, $userId) === null) {
            return null;
        }

        $connection = Database::connection();
        $position = $attributes['position'] ?? null;

        if ($position === null) {
            $next = $connection->prepare('SELECT COALESCE(MAX(position) + 1, 0) FROM trip_stops WHERE trip_id = ?');
            $next->execute([$tripId]);
            $position = (int) $next->fetchColumn();
        }

        $statement = $connection->prepare(
            'INSERT INTO trip_stops (trip_id, position, label, address, lat, lng, place_ref) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $tripId,
            (int) $position,
            (string) $attributes['label'],
            (string) $attributes['address'],
            $attributes['lat'],
            $attributes['lng'],
            $attributes['place_ref'] ?? null,
        ]);

        return (int) $connection->lastInsertId();
    }

    public static function delete(int $id, int $userId): bool
    {
        $statement = Database::connection()->prepare(
            'DELETE s FROM trip_stops s JOIN trips t ON t.id = s.trip_id WHERE s.id = ? AND t.user_id = ?'
        );
        $statement->execute([$id, $userId]);

        return $statement->rowCount() > 0;
    }

    public static function trip(array $stop, int $userId): ?array
    {
        return Trip::findForUser((int) $stop['trip_id'], $userId);
    }
}
