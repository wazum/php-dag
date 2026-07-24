<?php

declare(strict_types=1);

namespace PhpDag\Tests\Render;

use InvalidArgumentException;
use PhpDag\Render\EdgeRoute;
use PhpDag\Render\Waypoint;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EdgeRouteTest extends TestCase
{
    #[Test]
    public function constructsWithMinimalWaypoints(): void
    {
        $route = new EdgeRoute(
            waypoints: [new Waypoint(0, 5), new Waypoint(3, 5)],
            edgeId: 1,
        );

        self::assertCount(2, $route->waypoints);
        self::assertSame(1, $route->edgeId);
        self::assertSame(EdgeStrokeStyle::Solid, $route->strokeStyle);
        self::assertFalse($route->sourceArrow);
        self::assertTrue($route->targetArrow);
    }

    #[Test]
    public function rejectsFewerThanTwoWaypoints(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EdgeRoute(
            waypoints: [new Waypoint(0, 0)],
            edgeId: 1,
        );
    }

    #[Test]
    public function rejectsNonOrthogonalConsecutiveWaypoints(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EdgeRoute(
            waypoints: [new Waypoint(0, 0), new Waypoint(3, 5)],
            edgeId: 1,
        );
    }

    #[Test]
    public function acceptsLShapedRoute(): void
    {
        $route = new EdgeRoute(
            waypoints: [new Waypoint(0, 0), new Waypoint(0, 5), new Waypoint(3, 5)],
            edgeId: 1,
        );

        self::assertCount(3, $route->waypoints);
    }
}
