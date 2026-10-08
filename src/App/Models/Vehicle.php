<?php

namespace Keel\App\Models;

use Keel\Core\Database;

/**
 * Every query takes the owner's user id and filters on it, so a vehicle id from another
 * account reads as "not found". Keeping exactly one vehicle active is VehicleService's job:
 * it wraps deactivateAllForUser() and markActive() in one transaction.
 */
class Vehicle
{
    private const WRITABLE = [
        'name', 'type', 'length_m', 'width_m', 'height_m', 'weight_kg',
        'axle_count', 'axle_load_kg', 'has_trailer', 'trailer_length_m', 'trailer_weight_kg',
    ];

    /** The active vehicle first, then by name. */
    public static function forUser(int $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM vehicles WHERE user_id = ? ORDER BY is_active DESC, name ASC, id ASC'
        );
        $statement->execute([$userId]);

        return $statement->fetchAll();
    }

    public static function findForUser(int $id, int $userId): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM vehicles WHERE id = ? AND user_id = ? LIMIT 1');
        $statement->execute([$id, $userId]);

        return $statement->fetch() ?: null;
    }

    public static function activeForUser(int $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM vehicles WHERE user_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1'
        );
        $statement->execute([$userId]);

        return $statement->fetch() ?: null;
    }

    public static function countForUser(int $userId): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM vehicles WHERE user_id = ?');
        $statement->execute([$userId]);

        return (int) $statement->fetchColumn();
    }

    /** The most recently changed vehicle other than $exceptId: the one to promote when the active one goes. */
    public static function mostRecentForUser(int $userId, int $exceptId = 0): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM vehicles WHERE user_id = ? AND id <> ? ORDER BY updated_at DESC, id DESC LIMIT 1'
        );
        $statement->execute([$userId, $exceptId]);

        return $statement->fetch() ?: null;
    }

    public static function create(int $userId, array $attributes, bool $isActive = false): int
    {
        $values = self::writableValues($attributes);
        $columns = array_keys($values);

        $statement = Database::connection()->prepare(
            'INSERT INTO vehicles (user_id, is_active, ' . implode(', ', $columns) . ')
             VALUES (:user_id, :is_active, :' . implode(', :', $columns) . ')'
        );
        $statement->execute($values + ['user_id' => $userId, 'is_active' => $isActive ? 1 : 0]);

        return (int) Database::connection()->lastInsertId();
    }

    public static function update(int $id, int $userId, array $attributes): void
    {
        $values = self::writableValues($attributes);
        $assignments = array_map(static fn (string $column): string => "{$column} = :{$column}", array_keys($values));

        $statement = Database::connection()->prepare(
            'UPDATE vehicles SET ' . implode(', ', $assignments) . ' WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($values + ['id' => $id, 'user_id' => $userId]);
    }

    public static function delete(int $id, int $userId): bool
    {
        $statement = Database::connection()->prepare('DELETE FROM vehicles WHERE id = ? AND user_id = ?');
        $statement->execute([$id, $userId]);

        return $statement->rowCount() > 0;
    }

    public static function deactivateAllForUser(int $userId): void
    {
        $statement = Database::connection()->prepare('UPDATE vehicles SET is_active = 0 WHERE user_id = ? AND is_active = 1');
        $statement->execute([$userId]);
    }

    public static function markActive(int $id, int $userId): bool
    {
        $statement = Database::connection()->prepare('UPDATE vehicles SET is_active = 1 WHERE id = ? AND user_id = ?');
        $statement->execute([$id, $userId]);

        return $statement->rowCount() > 0;
    }

    /** True when a trip or saved route points at the vehicle, which the RESTRICT foreign keys protect. */
    public static function isInUse(int $id, int $userId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT EXISTS (SELECT 1 FROM trips WHERE vehicle_id = ? AND user_id = ?)
                 OR EXISTS (SELECT 1 FROM saved_routes WHERE vehicle_id = ? AND user_id = ?)'
        );
        $statement->execute([$id, $userId, $id, $userId]);

        return (bool) $statement->fetchColumn();
    }

    public static function routePreference(int $id, int $userId): ?array
    {
        return RoutePreference::forVehicle($id, $userId);
    }

    public static function trips(int $id, int $userId): array
    {
        return Trip::forVehicle($id, $userId);
    }

    public static function savedRoutes(int $id, int $userId): array
    {
        return SavedRoute::forVehicle($id, $userId);
    }

    /** Vehicle plus trailer, in metres, or null for a type with no length (bicycle, walking). */
    public static function overallLength(array $vehicle): ?float
    {
        if ($vehicle['length_m'] === null) {
            return null;
        }

        $type = (string) $vehicle['type'];
        $hasTrailer = (int) ($vehicle['has_trailer'] ?? 0) === 1;
        $length = (float) $vehicle['length_m'];

        if (VehicleType::applies($type, 'trailer_length_m', $hasTrailer) && $vehicle['trailer_length_m'] !== null) {
            $length += (float) $vehicle['trailer_length_m'];
        }

        return $length;
    }

    /** Only the known columns, each present: a missing one is written as NULL rather than left stale. */
    private static function writableValues(array $attributes): array
    {
        $values = [];

        foreach (self::WRITABLE as $column) {
            $values[$column] = $attributes[$column] ?? null;
        }

        $values['has_trailer'] = (int) (bool) ($values['has_trailer'] ?? 0);

        return $values;
    }
}
