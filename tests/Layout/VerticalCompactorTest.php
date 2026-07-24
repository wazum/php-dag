<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\BrandesKopfPositioning;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LongestPathLayering;
use PhpDag\Layout\Processor;
use PhpDag\Layout\VerticalCompactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VerticalCompactorTest extends TestCase
{
    #[Test]
    public function implementsProcessorInterface(): void
    {
        self::assertInstanceOf(Processor::class, new VerticalCompactor());
    }

    #[Test]
    public function isNoOpForSingleLayerGraph(): void
    {
        $layoutGraph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'Alpha')],
        );

        $rowBefore = $layoutGraph->getLayoutNode('A')->row;

        (new VerticalCompactor())->process($layoutGraph);

        self::assertSame($rowBefore, $layoutGraph->getLayoutNode('A')->row);
    }

    #[Test]
    public function compactsExcessGapBetweenTwoLayers(): void
    {
        $layoutGraph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta')],
            edges: [new Edge('A', 'B')],
        );

        $layoutGraph->getLayoutNode('B')->row += 10;

        (new VerticalCompactor())->process($layoutGraph);

        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeB = $layoutGraph->getLayoutNode('B');
        $actualGap = $nodeB->row - ($nodeA->row + $nodeA->boxHeight());
        self::assertSame(2, $actualGap, 'Gap should be compacted to minimumVerticalSpacing');
    }

    #[Test]
    public function compactsMiddleGapShiftingAllLowerLayers(): void
    {
        $layoutGraph = $this->buildPositionedGraph(
            nodes: [
                new Node('A', 'Alpha'),
                new Node('B', 'Beta'),
                new Node('C', 'Gamma'),
            ],
            edges: [new Edge('A', 'B'), new Edge('B', 'C')],
        );

        $layoutGraph->getLayoutNode('C')->row += 10;

        (new VerticalCompactor())->process($layoutGraph);

        $nodeB = $layoutGraph->getLayoutNode('B');
        $nodeC = $layoutGraph->getLayoutNode('C');
        $actualGap = $nodeC->row - ($nodeB->row + $nodeB->boxHeight());
        self::assertSame(2, $actualGap);
    }

    #[Test]
    public function handlesMixedBoxHeights(): void
    {
        $layoutGraph = $this->buildPositionedGraph(
            nodes: [
                new Node('A', 'Tall', body: ['line1', 'line2']),
                new Node('B', 'Short'),
            ],
            edges: [new Edge('A', 'B')],
        );

        $layoutGraph->getLayoutNode('B')->row += 10;

        (new VerticalCompactor())->process($layoutGraph);

        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeB = $layoutGraph->getLayoutNode('B');
        $actualGap = $nodeB->row - ($nodeA->row + $nodeA->boxHeight());
        self::assertSame(2, $actualGap, 'Gap calculation must use tallest box in upper layer');
    }

    #[Test]
    public function preservesColumnsDuringCompaction(): void
    {
        $layoutGraph = $this->buildPositionedGraph(
            nodes: [
                new Node('A', 'Alpha'),
                new Node('B', 'Beta'),
                new Node('C', 'Gamma'),
            ],
            edges: [new Edge('A', 'B'), new Edge('B', 'C')],
        );

        $columnsBefore = [];
        foreach ($layoutGraph->nodeIds() as $nodeId) {
            $columnsBefore[$nodeId] = $layoutGraph->getLayoutNode($nodeId)->column;
        }

        foreach ($layoutGraph->layerIndex() as $layerNumber => $nodeIds) {
            if ($layerNumber > 0) {
                foreach ($nodeIds as $nodeId) {
                    $layoutGraph->getLayoutNode($nodeId)->row += 10 * $layerNumber;
                }
            }
        }

        (new VerticalCompactor())->process($layoutGraph);

        foreach ($layoutGraph->nodeIds() as $nodeId) {
            self::assertSame(
                $columnsBefore[$nodeId],
                $layoutGraph->getLayoutNode($nodeId)->column,
                "Column for {$nodeId} must not change during vertical compaction",
            );
        }
    }

    #[Test]
    public function reservesOneExtraRowForBendingEdgeTransitions(): void
    {
        $layoutGraph = $this->buildPositionedGraph(
            nodes: [
                new Node('A', 'Alpha'),
                new Node('B', 'Beta'),
                new Node('C', 'Gamma'),
            ],
            edges: [new Edge('A', 'B'), new Edge('A', 'C')],
        );

        foreach ($layoutGraph->layerIndex()[1] as $nodeId) {
            $layoutGraph->getLayoutNode($nodeId)->row += 10;
        }

        (new VerticalCompactor())->process($layoutGraph);

        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeB = $layoutGraph->getLayoutNode('B');
        $actualGap = $nodeB->row - ($nodeA->row + $nodeA->boxHeight());
        self::assertSame(3, $actualGap, 'Fan-out transition bends, so the gap must be minimumVerticalSpacing plus one channel row');
    }

    /**
     * @param list<Node> $nodes
     * @param list<Edge> $edges
     */
    private function buildPositionedGraph(array $nodes, array $edges = []): LayoutGraph
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
        (new BrandesKopfPositioning())->position($layoutGraph);

        return $layoutGraph;
    }
}
