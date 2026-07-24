<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\EdgePort;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\SelfLoopRouter;
use PhpDag\Render\Waypoint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SelfLoopRouterTest extends TestCase
{
    #[Test]
    public function routesSelfLoopExitingTheBottomAndReenteringTheEastSide(): void
    {
        $graph = new Graph();
        // A multi-line node (height 5) so the re-entry centre row is intdiv(h, 2)
        // and not confusable with intdiv(h, 3).
        $graph->addNode(new Node('A', 'A', ['line two', 'line three']));
        $graph->addEdge(new Edge('A', 'A'));
        $layoutGraph = LayoutGraph::fromGraph($graph);
        $node = $layoutGraph->getLayoutNode('A');
        $node->row = 0;
        $node->column = 0;

        (new SelfLoopRouter())->process($layoutGraph);

        $loop = $layoutGraph->selfLoops()[0];
        $width = $node->boxWidth();
        $height = $node->boxHeight();

        self::assertSame(EdgePort::South, $loop->sourcePort);
        self::assertSame(EdgePort::East, $loop->targetPort);
        self::assertEquals(
            [
                new Waypoint($height, intdiv($width, 2)),
                new Waypoint($height, $width + 1),
                new Waypoint(intdiv($height, 2), $width + 1),
                new Waypoint(intdiv($height, 2), $width),
            ],
            $loop->waypoints,
        );
    }
}
