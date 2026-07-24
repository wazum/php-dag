<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\HorizontalCompactor;
use PhpDag\Layout\LayoutGraph;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HorizontalCompactorTest extends TestCase
{
    #[Test]
    public function compactsExcessSpacingBetweenTwoStraightLayers(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeA->layer = 0;
        $nodeA->column = 0;
        $nodeB = $layoutGraph->getLayoutNode('B');
        $nodeB->layer = 1;
        $nodeB->column = 20;
        $layoutGraph->buildLayerIndex();

        (new HorizontalCompactor())->process($layoutGraph);

        self::assertSame($nodeA->boxWidth() + 2, $nodeB->column, 'Excess gap must shrink to the minimum spacing of 2');
    }

    #[Test]
    public function reservesTheSelfLoopLaneWhenCompacting(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addEdge(new Edge('A', 'A'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeA->layer = 0;
        $nodeA->column = 0;
        $nodeB = $layoutGraph->getLayoutNode('B');
        $nodeB->layer = 1;
        $nodeB->column = 20;
        $layoutGraph->buildLayerIndex();

        (new HorizontalCompactor())->process($layoutGraph);

        self::assertGreaterThanOrEqual(
            $nodeA->boxWidth() + 3,
            $nodeB->column,
            'The next layer must clear the self-loop lane with a gap, not be compacted onto it',
        );
    }

    #[Test]
    public function keepsExactMinimumSpacingUntouched(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeA->layer = 0;
        $nodeA->column = 0;
        $nodeB = $layoutGraph->getLayoutNode('B');
        $nodeB->layer = 1;
        $nodeB->column = $nodeA->boxWidth() + 2;
        $layoutGraph->buildLayerIndex();

        (new HorizontalCompactor())->process($layoutGraph);

        self::assertSame($nodeA->boxWidth() + 2, $nodeB->column, 'A gap already at minimum spacing must not move');
    }
}
