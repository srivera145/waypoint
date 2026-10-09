<?php

namespace Keel\App\Services\Routing;

use Keel\Core\Env;

/** Picks the routing provider named by ROUTING_PROVIDER. Adding HERE or TomTom is one match arm. */
class RoutingProviders
{
    private static ?RoutingProvider $override = null;

    /** @throws RoutingException when the configured provider is unknown or not set up */
    public static function resolve(): RoutingProvider
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $name = strtolower(trim((string) Env::get('ROUTING_PROVIDER', ValhallaProvider::NAME)));

        return match ($name) {
            ValhallaProvider::NAME => ValhallaProvider::fromEnv(),
            default => self::unknown($name),
        };
    }

    /** Tests only: send every request to $provider. Pass null to go back to the configured one. */
    public static function useForTesting(?RoutingProvider $provider): void
    {
        self::$override = $provider;
    }

    private static function unknown(string $name): never
    {
        error_log("[Keel] Routing: unknown ROUTING_PROVIDER '{$name}'.");

        throw RoutingException::notConfigured();
    }
}
