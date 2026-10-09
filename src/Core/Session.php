<?php

namespace Keel\Core;

class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => Env::get('APP_ENV') === 'production',
            ]);
            session_start();
        }
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Ends this request's hold on the session. PHP locks the session file from start to end of
     * a request, so a slow request (planning a route) or many parallel ones (map tiles) would
     * make the user's other requests queue behind it. Reads still work afterwards; changes made
     * afterwards are not saved.
     *
     * Under the test harness the session stays open: every test shares one process, and PHP
     * cannot reopen a session once output has started.
     */
    public static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !Response::capturing()) {
            session_write_close();
        }
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}
