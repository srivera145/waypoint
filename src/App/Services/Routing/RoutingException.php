<?php

namespace Keel\App\Services\Routing;

/**
 * A routing request that produced no usable route. The message is safe to show the user;
 * the reason picks the HTTP status. Nothing that throws this ever hands back a made-up route.
 */
class RoutingException extends \RuntimeException
{
    /** The routing engine could not be reached, timed out, or answered with something unreadable. */
    public const UNAVAILABLE = 'unavailable';

    /** The engine answered, and there is no route between the two points for this vehicle. */
    public const NO_ROUTE = 'no_route';

    /** Routing is not set up in this environment (VALHALLA_URL missing, unknown provider). */
    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function unavailable(string $message = 'The routing service is unavailable right now. Please try again in a few minutes.', ?\Throwable $previous = null): self
    {
        return new self(self::UNAVAILABLE, $message, $previous);
    }

    public static function noRoute(string $message = 'No route was found between those points for this vehicle.'): self
    {
        return new self(self::NO_ROUTE, $message);
    }

    public static function notConfigured(string $message = 'Routing is not configured on this server.'): self
    {
        return new self(self::NOT_CONFIGURED, $message);
    }

    public function httpStatus(): int
    {
        return $this->reason === self::NO_ROUTE ? 422 : 503;
    }
}
