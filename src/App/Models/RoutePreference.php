<?php

namespace Keel\App\Models;

use Keel\Core\Database;

/**
 * A user's routing preferences. The row with vehicle_id NULL is their default; a row with a
 * vehicle_id overrides it for that vehicle.
 */
class RoutePreference
{
    public const PERSONALITIES = ['fastest', 'economical', 'scenic', 'easy', 'flat', 'rv'];
    public const DEFAULT_PERSONALITY = 'fastest';

    /** The three the home screen offers directly. The rest sit behind its "Custom" choice. */
    public const PRIMARY_PERSONALITIES = ['fastest', 'scenic', 'easy'];

    public const LABELS = [
        'fastest' => 'Fastest',
        'economical' => 'Economical',
        'scenic' => 'Scenic',
        'easy' => 'Easy',
        'flat' => 'Flat',
        'rv' => 'RV-friendly',
    ];

    /** The user's default preferences: the row that belongs to no vehicle. */
    public static function forUser(int $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM route_preferences WHERE user_id = ? AND vehicle_id IS NULL ORDER BY id ASC LIMIT 1'
        );
        $statement->execute([$userId]);

        return $statement->fetch() ?: null;
    }

    public static function forVehicle(int $vehicleId, int $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM route_preferences WHERE vehicle_id = ? AND user_id = ? LIMIT 1'
        );
        $statement->execute([$vehicleId, $userId]);

        return $statement->fetch() ?: null;
    }

    public static function personalityForUser(int $userId): string
    {
        return (string) (self::forUser($userId)['personality'] ?? self::DEFAULT_PERSONALITY);
    }

    /**
     * Saves the personality on the user's default row, or on a vehicle's row when $vehicleId is
     * given, creating the row if needed. Returns false when the user or vehicle is not theirs.
     *
     * The user's row is locked first: MySQL lets a unique key hold any number of NULLs, so
     * uniq_user_vehicle cannot stop two simultaneous saves each inserting a default row.
     */
    public static function savePersonality(int $userId, string $personality, ?int $vehicleId = null): bool
    {
        if (!in_array($personality, self::PERSONALITIES, true)) {
            throw new \InvalidArgumentException("Unknown route personality: {$personality}");
        }

        $connection = Database::connection();
        $connection->beginTransaction();

        try {
            $lock = $connection->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([$userId]);

            if (!$lock->fetch() || ($vehicleId !== null && Vehicle::findForUser($vehicleId, $userId) === null)) {
                $connection->rollBack();
                return false;
            }

            $existing = $vehicleId === null ? self::forUser($userId) : self::forVehicle($vehicleId, $userId);

            if ($existing) {
                $statement = $connection->prepare('UPDATE route_preferences SET personality = ? WHERE id = ? AND user_id = ?');
                $statement->execute([$personality, $existing['id'], $userId]);
            } else {
                $statement = $connection->prepare('INSERT INTO route_preferences (user_id, vehicle_id, personality) VALUES (?, ?, ?)');
                $statement->execute([$userId, $vehicleId, $personality]);
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }

        return true;
    }

    public static function vehicle(array $preference, int $userId): ?array
    {
        return $preference['vehicle_id'] === null ? null : Vehicle::findForUser((int) $preference['vehicle_id'], $userId);
    }
}
