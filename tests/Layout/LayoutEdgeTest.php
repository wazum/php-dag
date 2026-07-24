<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Render\Waypoint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LayoutEdgeTest extends TestCase
{
    #[Test]
    public function delegatesSourceAndTargetToEdge(): void
    {
        $edge = new Edge(sourceId: 'A', targetId: 'B');
        $layoutEdge = new LayoutEdge(edge: $edge);

        self::assertSame('A', $layoutEdge->sourceId());
        self::assertSame('B', $layoutEdge->targetId());
        self::assertSame(1, $layoutEdge->minLength());
        self::assertFalse($layoutEdge->reversed);
    }

    #[Test]
    public function swapsSourceAndTargetWhenReversed(): void
    {
        $edge = new Edge(sourceId: 'A', targetId: 'B');
        $layoutEdge = new LayoutEdge(edge: $edge, reversed: true);

        self::assertSame('B', $layoutEdge->sourceId());
        self::assertSame('A', $layoutEdge->targetId());
    }

    #[Test]
    public function storesWaypoints(): void
    {
        $edge = new Edge(sourceId: 'A', targetId: 'B');
        $layoutEdge = new LayoutEdge(edge: $edge);

        self::assertSame([], $layoutEdge->waypoints);

        $layoutEdge->waypoints = [
            new Waypoint(3, 4),
            new Waypoint(5, 4),
        ];

        self::assertCount(2, $layoutEdge->waypoints);
        self::assertSame(3, $layoutEdge->waypoints[0]->row);
        self::assertSame(4, $layoutEdge->waypoints[0]->column);
    }
}
