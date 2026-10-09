<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Services\Maps\MapScreen;
use Keel\App\Services\Maps\MapTiles;
use PHPUnit\Framework\TestCase;

class MapTilesTest extends TestCase
{
    public function testByteRangesAsThePmtilesClientSendsThem(): void
    {
        // The archive header, then a directory and a tile somewhere inside.
        self::assertSame([0, 16383], MapTiles::byteRange('bytes=0-16383', 291469574));
        self::assertSame([127, 2048], MapTiles::byteRange('bytes=127-2048', 291469574));
        self::assertSame([100, 999], MapTiles::byteRange('bytes=100-', 1000));
        self::assertSame([900, 999], MapTiles::byteRange('bytes=-100', 1000));
        self::assertSame([0, 999], MapTiles::byteRange('bytes=-5000', 1000));
        // Past the end is clipped to the file.
        self::assertSame([990, 999], MapTiles::byteRange('bytes=990-5000', 1000));
    }

    public function testRangesThatStartPastTheEndAreUnsatisfiable(): void
    {
        self::assertFalse(MapTiles::byteRange('bytes=1000-', 1000));
        self::assertFalse(MapTiles::byteRange('bytes=1000-2000', 1000));
        self::assertFalse(MapTiles::byteRange('bytes=-0', 1000));
    }

    public function testAnythingElseMeansTheWholeFile(): void
    {
        foreach ([null, '', 'bytes=', 'bytes=-', 'items=0-10', 'bytes=0-10,20-30', 'bytes=50-10', 'bytes=abc-'] as $header) {
            self::assertNull(MapTiles::byteRange($header, 1000), (string) $header);
        }
    }

    public function testOnlyTheMapScreenIsAReturnPath(): void
    {
        self::assertSame('/map', MapScreen::returnPath('/map'));
        self::assertSame('/map?to=40.6,-111.5&to_label=Park+City', MapScreen::returnPath('/map?to=40.6,-111.5&to_label=Park+City'));

        foreach (['', '/', '/mapping', '//evil.test/map', 'https://evil.test/map', '/map/../admin', '/map?x=1#frag', '/map?x=\\evil', null, ['/map']] as $value) {
            self::assertNull(MapScreen::returnPath($value), json_encode($value));
        }
    }

    public function testTripPlacesComeFromTheQueryString(): void
    {
        self::assertSame(['lat' => 40.646092, 'lng' => -111.497996, 'label' => 'Park City'], MapScreen::place('40.646092,-111.497996', 'Park City'));
        self::assertSame(['lat' => 40.6, 'lng' => -111.5, 'label' => 'Two words'], MapScreen::place(' 40.6 , -111.5 ', "  Two \n words "));

        self::assertNull(MapScreen::place('40.6,-111.5', ''));
        self::assertNull(MapScreen::place('40.6,-111.5', str_repeat('x', 201)));
        self::assertNull(MapScreen::place('91,-111.5', 'Too far north'));
        self::assertNull(MapScreen::place('40.6', 'One number'));
        self::assertNull(MapScreen::place('north,west', 'Words'));
        self::assertNull(MapScreen::place(['40.6', '-111.5'], 'Array'));
    }
}
