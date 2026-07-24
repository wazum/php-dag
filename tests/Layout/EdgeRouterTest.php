<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\EdgeRouter;
use PhpDag\Layout\LayerAssigner;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\NodePositioner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EdgeRouterTest extends TestCase
{
    #[Test]
    public function routesEdgesOnLayoutGraph(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addEdge(new Edge('A', 'B'));
        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new LayerAssigner())->process($layoutGraph);
        (new NodePositioner())->process($layoutGraph);

        (new EdgeRouter())->process($layoutGraph);

        self::assertNotEmpty($layoutGraph->edges()[0]->waypoints);
    }
}
