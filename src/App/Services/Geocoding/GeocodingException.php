<?php

namespace Keel\App\Services\Geocoding;

/** A place search that could not run. The message is safe to show the user. */
class GeocodingException extends \RuntimeException
{
    /** The geocoder could not be reached, timed out, or answered with something unreadable. */
    public const UNAVAILABLE = 'unavailable';

    /** Place search is not set up in this environment (PHOTON_URL missing, unknown provider). */
    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function unavailable(string $message = 'Place search is unavailable right now. Please try again in a few minutes.', ?\Throwable $previous = null): self
    {
        return new self(self::UNAVAILABLE, $message, $previous);
    }

    public static function notConfigured(string $message = 'Place search is not configured on this server.'): self
    {
        return new self(self::NOT_CONFIGURED, $message);
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
