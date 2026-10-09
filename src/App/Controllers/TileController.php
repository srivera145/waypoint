<?php

namespace Keel\App\Controllers;

use Keel\App\Services\Maps\MapTiles;
use Keel\Core\Controller;
use Keel\Core\Request;
use Keel\Core\Response;
use Keel\Core\Session;

/**
 * GET /tiles/basemap.pmtiles: the MAP_TILES_PATH archive, with byte-range support. The
 * PMTiles client reads the archive one range at a time (a header, a directory, a tile), so
 * almost every request is a 206.
 *
 * Fine for development and a small deployment. In production, point MAP_TILES_URL at a static
 * server or CDN instead: each range here boots PHP.
 */
class TileController extends Controller
{
    public function basemap(Request $request): never
    {
        // A map view fetches dozens of ranges at once; none of them should wait on the session
        // lock. Starting the session also sent no-cache headers meant for pages; tiles cache.
        Session::release();
        header_remove('Pragma');
        header_remove('Expires');

        $path = MapTiles::localPath();

        if ($path === null) {
            Response::abort(404, 'No basemap is installed. Set MAP_TILES_PATH (see docker/README.md).');
        }

        clearstatcache(true, $path);
        $size = (int) filesize($path);
        $modified = (int) filemtime($path);

        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Accept-Ranges' => 'bytes',
            // Changes whenever the archive is replaced; the PMTiles client checks it on every read.
            'ETag' => '"' . dechex($size) . '-' . dechex($modified) . '"',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $modified) . ' GMT',
            'Cache-Control' => 'public, max-age=86400',
        ];

        $header = $request->headers['Range'] ?? $request->headers['range'] ?? $_SERVER['HTTP_RANGE'] ?? null;
        $range = MapTiles::byteRange(is_string($header) ? $header : null, $size);

        if ($range === false) {
            Response::raw('', 416, $headers + ['Content-Range' => 'bytes */' . $size]);
        }

        if ($range === null) {
            Response::file($path, 0, $size, 200, $headers + ['Content-Length' => (string) $size]);
        }

        [$first, $last] = $range;
        $length = $last - $first + 1;

        Response::file($path, $first, $length, 206, $headers + [
            'Content-Range' => "bytes {$first}-{$last}/{$size}",
            'Content-Length' => (string) $length,
        ]);
    }
}
