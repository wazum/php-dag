<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\BrandesKopfPositioning;
use PhpDag\Layout\LayerAssigner;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\NodePositioner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class NodePositionerTest extends TestCase
{
    #[Test]
    public function usesBrandesKopfPositioningAsDefault(): void
    {
        $positioner = new NodePositioner();
        $reflection = new ReflectionProperty($positioner, 'strategy');
        self::assertInstanceOf(BrandesKopfPositioning::class, $reflection->getValue($positioner));
    }

    #[Test]
    public function positionsNodesOnLayoutGraph(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addEdge(new Edge('A', 'B'));
        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new LayerAssigner())->process($layoutGraph);

        (new NodePositioner())->process($layoutGraph);

        self::assertSame(0, $layoutGraph->getLayoutNode('A')->row);
        self::assertGreaterThan(0, $layoutGraph->getLayoutNode('B')->row);
    }
}
