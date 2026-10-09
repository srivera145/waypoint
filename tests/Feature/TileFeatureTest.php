<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class TileFeatureTest extends TestCase
{
    private string $archive;

    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Stands in for a .pmtiles file: the real magic bytes, then known filler.
        $this->archive = tempnam(sys_get_temp_dir(), 'tiles');
        file_put_contents($this->archive, 'PMTiles' . "\x03" . str_repeat('0123456789', 99) . 'END');

        $this->envBackup = ['MAP_TILES_PATH' => $_ENV['MAP_TILES_PATH'] ?? null, 'MAP_TILES_URL' => $_ENV['MAP_TILES_URL'] ?? null];
        $_ENV['MAP_TILES_PATH'] = $this->archive;
        $_ENV['MAP_TILES_URL'] = '';
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            $_ENV[$key] = $value;
        }

        @unlink($this->archive);

        parent::tearDown();
    }

    public function testARangeIsAnsweredWith206AndThoseBytes(): void
    {
        $response = $this->get('/tiles/basemap.pmtiles', ['Range' => 'bytes=0-6']);

        self::assertSame(206, $response->status);
        self::assertSame('PMTiles', $response->body);
        self::assertSame('bytes 0-6/1001', $response->header('Content-Range'));
        self::assertSame('7', $response->header('Content-Length'));
        self::assertSame('bytes', $response->header('Accept-Ranges'));
        self::assertSame('application/octet-stream', $response->header('Content-Type'));
        self::assertMatchesRegularExpression('/^"[0-9a-f]+-[0-9a-f]+"$/', (string) $response->header('ETag'));

        $tail = $this->get('/tiles/basemap.pmtiles', ['Range' => 'bytes=-3']);
        self::assertSame(206, $tail->status);
        self::assertSame('END', $tail->body);
        self::assertSame('bytes 998-1000/1001', $tail->header('Content-Range'));
    }

    public function testARangePastTheEndIs416(): void
    {
        $response = $this->get('/tiles/basemap.pmtiles', ['Range' => 'bytes=5000-']);

        self::assertSame(416, $response->status);
        self::assertSame('bytes */1001', $response->header('Content-Range'));
        self::assertSame('', $response->body);
    }

    public function testWithoutARangeTheWholeArchiveIsSent(): void
    {
        $response = $this->get('/tiles/basemap.pmtiles');

        self::assertSame(200, $response->status);
        self::assertSame(1001, strlen($response->body));
        self::assertSame('1001', $response->header('Content-Length'));
        self::assertSame('bytes', $response->header('Accept-Ranges'));
    }

    public function testTheBasemapNeedsNoSignIn(): void
    {
        // No actingAs(): tiles are public OpenStreetMap data.
        self::assertSame(206, $this->get('/tiles/basemap.pmtiles', ['Range' => 'bytes=0-1'])->status);
    }

    public function testNoArchiveIsA404(): void
    {
        $_ENV['MAP_TILES_PATH'] = $this->archive . '.missing';

        self::assertSame(404, $this->get('/tiles/basemap.pmtiles', ['Range' => 'bytes=0-1'])->status);

        $_ENV['MAP_TILES_PATH'] = '';
        self::assertSame(404, $this->get('/tiles/basemap.pmtiles')->status);
    }
}
