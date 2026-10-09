<?php

namespace Keel\App\Services\Geocoding;

use Keel\Core\Env;

/** Picks the geocoder named by GEOCODING_PROVIDER. Adding another is one match arm. */
class GeocodingProviders
{
    private static ?GeocodingProvider $override = null;

    /** @throws GeocodingException when the configured provider is unknown or not set up */
    public static function resolve(): GeocodingProvider
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $name = strtolower(trim((string) Env::get('GEOCODING_PROVIDER', PhotonProvider::NAME)));

        return match ($name) {
            PhotonProvider::NAME => PhotonProvider::fromEnv(),
            default => self::unknown($name),
        };
    }

    /** Tests only: send every request to $provider. Pass null to go back to the configured one. */
    public static function useForTesting(?GeocodingProvider $provider): void
    {
        self::$override = $provider;
    }

    private static function unknown(string $name): never
    {
        error_log("[Keel] Geocoding: unknown GEOCODING_PROVIDER '{$name}'.");

        throw GeocodingException::notConfigured();
    }
}
