<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Services\Routing\Polyline;
use PHPUnit\Framework\TestCase;

class PolylineTest extends TestCase
{
    public function testDecodesGooglesReferenceExampleAtPrecisionFive(): void
    {
        self::assertSame(
            [[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]],
            Polyline::decode('_p~iF~ps|U_ulLnnqC_mqNvxq`@', 5)
        );
    }

    public function testRoundTripsAtPrecisionSix(): void
    {
        $points = [[40.760800, -111.891000], [40.646089, -111.498038], [40.0, -111.0], [-33.868820, 151.209296]];

        $encoded = Polyline::encode($points);

        self::assertSame($points, Polyline::decode($encoded));
        self::assertNotSame(Polyline::encode($points, 5), $encoded, 'Precision 6 is not Google precision 5.');
    }

    public function testEmptyAndInvalidInput(): void
    {
        self::assertSame([], Polyline::decode(''));
        self::assertSame('', Polyline::encode([]));

        $this->expectException(\InvalidArgumentException::class);
        Polyline::decode('_p~iF~ps|U_');
    }
}
