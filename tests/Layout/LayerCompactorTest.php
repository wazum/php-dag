<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Group;
use PhpDag\Graph\Node;
use PhpDag\Layout\BrandesKopfPositioning;
use PhpDag\Layout\FlowDirection;
use PhpDag\Layout\LayerCompactor;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LayoutNode;
use PhpDag\Layout\LongestPathLayering;
use PhpDag\Layout\Processor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LayerCompactorTest extends TestCase
{
    #[Test]
    public function implementsProcessorInterface(): void
    {
        self::assertInstanceOf(Processor::class, new LayerCompactor(FlowDirection::TopToBottom));
    }

    #[Test]
    public function isNoOpForSingleLayerGraph(): void
    {
        $layoutGraph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'Alpha')],
        );

        $rowBefore = $layoutGraph->getLayoutNode('A')->row;

        (new LayerCompactor(FlowDirection::TopToBottom))->process($layoutGraph);

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

        (new LayerCompactor(FlowDirection::TopToBottom))->process($layoutGraph);

        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeB = $layoutGraph->getLayoutNode('B');
        $actualGap = $nodeB->row - ($nodeA->row + $nodeA->boxHeight());
        self::assertSame(2, $actualGap, 'Gap should be compacted to the minimum spacing');
    }

    #[Test]
    public function leavesAGapNarrowerThanRequiredAsItIs(): void
    {
        $layoutGraph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta')],
            edges: [new Edge('A', 'B')],
        );
        $rowBefore = $layoutGraph->getLayoutNode('B')->row;

        (new LayerCompactor(FlowDirection::TopToBottom, minimumSpacing: 9))->process($layoutGraph);

        self::assertSame($rowBefore, $layoutGraph->getLayoutNode('B')->row);
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

        (new LayerCompactor(FlowDirection::TopToBottom))->process($layoutGraph);

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

        (new LayerCompactor(FlowDirection::TopToBottom))->process($layoutGraph);

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

        (new LayerCompactor(FlowDirection::TopToBottom))->process($layoutGraph);

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

        (new LayerCompactor(FlowDirection::TopToBottom))->process($layoutGraph);

        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeB = $layoutGraph->getLayoutNode('B');
        $actualGap = $nodeB->row - ($nodeA->row + $nodeA->boxHeight());
        self::assertSame(3, $actualGap, 'Fan-out transition bends, so the gap must be the minimum spacing plus one channel row');
    }

    #[Test]
    public function compactsExcessSpacingBetweenTwoStraightLayersLeftToRight(): void
    {
        [$layoutGraph, $nodeA, $nodeB] = $this->twoColumnLayers(bColumn: 20);

        (new LayerCompactor(FlowDirection::LeftToRight))->process($layoutGraph);

        self::assertSame($nodeA->boxWidth() + 2, $nodeB->column, 'Excess gap must shrink to the minimum spacing of 2');
    }

    #[Test]
    public function reservesTheSelfLoopLaneWhenCompactingLeftToRight(): void
    {
        [$layoutGraph, $nodeA, $nodeB] = $this->twoColumnLayers(bColumn: 20, selfLoopOnA: true);

        (new LayerCompactor(FlowDirection::LeftToRight))->process($layoutGraph);

        self::assertGreaterThanOrEqual(
            $nodeA->boxWidth() + 3,
            $nodeB->column,
            'The next layer must clear the self-loop lane with a gap, not be compacted onto it',
        );
    }

    #[Test]
    public function keepsExactMinimumSpacingUntouchedLeftToRight(): void
    {
        [$layoutGraph, $nodeA, $nodeB] = $this->twoColumnLayers(bColumn: 7);
        $expected = $nodeA->boxWidth() + 2;
        $nodeB->column = $expected;

        (new LayerCompactor(FlowDirection::LeftToRight))->process($layoutGraph);

        self::assertSame($expected, $nodeB->column, 'A gap already at minimum spacing must not move');
    }

    #[Test]
    public function leavesAGapNarrowerThanRequiredAsItIsLeftToRight(): void
    {
        [$layoutGraph, $nodeA, $nodeB] = $this->twoColumnLayers(bColumn: 7);
        $expected = $nodeA->boxWidth() + 1;
        $nodeB->column = $expected;

        (new LayerCompactor(FlowDirection::LeftToRight))->process($layoutGraph);

        self::assertSame($expected, $nodeB->column);
    }

    #[Test]
    public function keepsTheWidestRightPaddingOfGroupsEndingInTheSameLayerLeftToRight(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addGroup(new Group('wide', 'Wide', ['A']));
        $graph->addGroup(new Group('narrow', 'Narrow', ['C']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        foreach (['A' => 0, 'C' => 0, 'B' => 1] as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
            $layoutGraph->getLayoutNode($nodeId)->column = 40 * $layer;
        }
        $layoutGraph->buildLayerIndex();
        $layoutGraph->setGroupRightPadding('wide', 10);
        $layoutGraph->setGroupRightPadding('narrow', 4);

        (new LayerCompactor(FlowDirection::LeftToRight))->process($layoutGraph);

        // Minimum spacing 2, one column for the group border, 8 beyond the ring.
        self::assertSame($layoutGraph->getLayoutNode('A')->boxWidth() + 11, $layoutGraph->getLayoutNode('B')->column);
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

    /** @return array{LayoutGraph, LayoutNode, LayoutNode} */
    private function twoColumnLayers(int $bColumn, bool $selfLoopOnA = false): array
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        if ($selfLoopOnA) {
            $graph->addEdge(new Edge('A', 'A'));
        }

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeA->layer = 0;
        $nodeA->column = 0;
        $nodeB = $layoutGraph->getLayoutNode('B');
        $nodeB->layer = 1;
        $nodeB->column = $bColumn;
        $layoutGraph->buildLayerIndex();

        return [$layoutGraph, $nodeA, $nodeB];
    }
}
