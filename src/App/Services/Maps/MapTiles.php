<?php

namespace Keel\App\Services\Maps;

use Keel\Core\Env;

/**
 * Where the basemap comes from: one Protomaps .pmtiles archive.
 *
 *   MAP_TILES_URL   the browser loads the archive from here: a CDN, or a path a static web
 *                   server answers with range requests. Takes precedence when set.
 *   MAP_TILES_PATH  otherwise Waypoint serves this file itself at LOCAL_URL. Relative paths
 *                   are from the project root.
 *
 * Basemap tiles are public OpenStreetMap data, not user data, so neither needs a session.
 */
class MapTiles
{
    public const LOCAL_URL = '/tiles/basemap.pmtiles';

    /** The URL the map screen loads tiles from, or null when no basemap is installed. */
    public static function browserUrl(): ?string
    {
        $url = trim((string) Env::get('MAP_TILES_URL', ''));

        if ($url !== '') {
            return $url;
        }

        return self::localPath() !== null ? self::LOCAL_URL : null;
    }

    /** MAP_TILES_PATH as an absolute path, when it names a readable file. */
    public static function localPath(): ?string
    {
        $path = trim((string) Env::get('MAP_TILES_PATH', ''));

        if ($path === '') {
            return null;
        }

        if (!self::isAbsolute($path)) {
            $path = dirname(__DIR__, 4) . '/' . $path;
        }

        return is_file($path) && is_readable($path) ? $path : null;
    }

    /**
     * The one byte range a Range header asks for, as [first, last] inclusive.
     *
     * Returns null to send the whole file: no header, a unit other than bytes, several ranges
     * at once (allowed by RFC 9110, and PMTiles never asks for them), or a header that does
     * not parse. Returns false when the range starts past the end, which is a 416.
     *
     * @return array{0: int, 1: int}|false|null
     */
    public static function byteRange(?string $header, int $size): array|false|null
    {
        $header = trim((string) $header);

        if (!preg_match('/^bytes=(\d*)-(\d*)$/i', $header, $match) || ($match[1] === '' && $match[2] === '')) {
            return null;
        }

        if ($match[1] === '') {
            // A suffix range: the last N bytes.
            $suffix = (int) $match[2];

            return $suffix === 0 || $size === 0 ? false : [max(0, $size - $suffix), $size - 1];
        }

        $first = (int) $match[1];

        if ($first >= $size) {
            return false;
        }

        $last = $match[2] === '' ? $size - 1 : (int) $match[2];

        return $last < $first ? null : [$first, min($last, $size - 1)];
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
