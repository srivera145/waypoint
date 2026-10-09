<?php

namespace Keel\App\Models;

use Keel\Core\Database;

/**
 * Routing results kept for a few minutes, so asking for the same route again does not go back
 * to the routing engine. Rows belong to a user like everything else; a key is a hash of the
 * provider and the full request.
 */
class RouteCache
{
    /** The cached payload, or null when there is none or it has expired. */
    public static function get(int $userId, string $key): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT payload FROM route_cache WHERE user_id = ? AND cache_key = ? AND expires_at > NOW() LIMIT 1'
        );
        $statement->execute([$userId, $key]);
        $payload = $statement->fetchColumn();

        if ($payload === false) {
            return null;
        }

        $decoded = json_decode((string) $payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Stores or replaces the payload for $key, and clears the user's expired rows while here. */
    public static function put(int $userId, string $key, array $payload, int $ttlMinutes): void
    {
        $connection = Database::connection();
        $ttlMinutes = max(1, $ttlMinutes);

        $statement = $connection->prepare(
            'INSERT INTO route_cache (user_id, cache_key, payload, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . $ttlMinutes . ' MINUTE))
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)'
        );
        $statement->execute([$userId, $key, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);

        $purge = $connection->prepare('DELETE FROM route_cache WHERE user_id = ? AND expires_at <= NOW()');
        $purge->execute([$userId]);
    }
}
