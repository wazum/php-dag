<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LeftToRightPositioning;
use PhpDag\Layout\LongestPathLayering;
use PhpDag\Layout\NodePositioning;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LeftToRightPositioningTest extends TestCase
{
    #[Test]
    public function implementsNodePositioningInterface(): void
    {
        self::assertInstanceOf(NodePositioning::class, new LeftToRightPositioning());
    }

    #[Test]
    public function positionsSingleNodeAtOrigin(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Alpha')],
        );

        (new LeftToRightPositioning())->position($layoutGraph);

        $node = $layoutGraph->getLayoutNode('A');
        self::assertSame(0, $node->row);
        self::assertSame(0, $node->column);
    }

    #[Test]
    public function positionsLinearChainWithHorizontalSpacing(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta'), new Node('C', 'Gamma')],
            edges: [new Edge('A', 'B'), new Edge('B', 'C')],
        );

        (new LeftToRightPositioning())->position($layoutGraph);

        $nodeA = $layoutGraph->getLayoutNode('A');
        self::assertSame(0, $nodeA->row);
        self::assertSame(0, $nodeA->column);

        $nodeB = $layoutGraph->getLayoutNode('B');
        self::assertSame(0, $nodeB->row);
        self::assertSame($nodeA->boxWidth() + 3, $nodeB->column);

        $nodeC = $layoutGraph->getLayoutNode('C');
        self::assertSame(0, $nodeC->row);
        self::assertSame($nodeB->column + $nodeB->boxWidth() + 3, $nodeC->column);
    }

    #[Test]
    public function assignsRowsToNodesInSameLayer(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [
                new Node('A', 'Alpha'),
                new Node('B', 'Beta'),
                new Node('C', 'Gamma'),
            ],
            edges: [
                new Edge('A', 'C'),
                new Edge('B', 'C'),
            ],
        );

        (new LeftToRightPositioning())->position($layoutGraph);

        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeB = $layoutGraph->getLayoutNode('B');
        self::assertSame(0, $nodeA->column);
        self::assertSame(0, $nodeB->column);
        self::assertSame($nodeA->boxHeight() + 2, $nodeB->row);
    }

    #[Test]
    public function centersDiamondGraphLayersVertically(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [
                new Node('A', 'Alpha'),
                new Node('B', 'Beta'),
                new Node('C', 'Gamma'),
                new Node('D', 'Delta'),
            ],
            edges: [
                new Edge('A', 'B'),
                new Edge('A', 'C'),
                new Edge('B', 'D'),
                new Edge('C', 'D'),
            ],
        );

        (new LeftToRightPositioning())->position($layoutGraph);

        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeB = $layoutGraph->getLayoutNode('B');
        self::assertSame(0, $nodeB->row, 'First node in tallest layer starts at row 0');
        self::assertGreaterThan(0, $nodeA->row, 'Single-node layer A should be offset to center vertically');
    }

    #[Test]
    public function respectsCustomSpacing(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta')],
            edges: [new Edge('A', 'B')],
        );

        (new LeftToRightPositioning(horizontalSpacing: 4, verticalSpacing: 1))->position($layoutGraph);

        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeB = $layoutGraph->getLayoutNode('B');
        self::assertSame($nodeA->boxWidth() + 4, $nodeB->column);
    }

    /**
     * @param list<Node> $nodes
     * @param list<Edge> $edges
     */
    private function buildLayeredGraph(array $nodes, array $edges = []): LayoutGraph
    {
        $graph = new Graph();
        foreach ($nodes as $node) {
            $graph->addNode($node);
        }
        foreach ($edges as $edge) {
            $graph->addEdge($edge);
        }

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();

        return $layoutGraph;
    }

    #[Test]
    public function centersShorterLayerAgainstTheTallest(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta'), new Node('C', 'Gamma')],
            edges: [new Edge('A', 'C'), new Edge('B', 'C')],
        );

        (new LeftToRightPositioning())->position($layoutGraph);

        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeB = $layoutGraph->getLayoutNode('B');
        $nodeC = $layoutGraph->getLayoutNode('C');

        self::assertSame(0, $nodeA->row, 'First node of the tallest layer starts at the top');
        self::assertSame($nodeA->boxHeight() + 2, $nodeB->row, 'Second node is stacked below with vertical spacing 2');
        $tallestLayerHeight = $nodeA->boxHeight() + 2 + $nodeB->boxHeight();
        self::assertSame(intdiv($tallestLayerHeight, 2) - intdiv($nodeC->boxHeight(), 2), $nodeC->row, 'Single-node layer is centered against the tallest layer');
    }

    #[Test]
    public function stacksNodesWithSpacingInsideACenteredShorterLayer(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Tall', ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10']), new Node('B', 'B'), new Node('C', 'C')],
            edges: [new Edge('A', 'B'), new Edge('A', 'C')],
        );

        (new LeftToRightPositioning())->position($layoutGraph);

        $nodeB = $layoutGraph->getLayoutNode('B');
        $nodeC = $layoutGraph->getLayoutNode('C');

        self::assertGreaterThan(0, $nodeB->row, 'The shorter layer starts below the top because it is centered');
        self::assertSame($nodeB->row + $nodeB->boxHeight() + 2, $nodeC->row, 'Stacking must accumulate from the layer start, not reset');
    }
}
