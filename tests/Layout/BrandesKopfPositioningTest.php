<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\BrandesKopfPositioning;
use PhpDag\Layout\DummyLayoutNode;
use PhpDag\Layout\DummyNodeInserter;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LongestPathLayering;
use PhpDag\Layout\NodePositioning;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BrandesKopfPositioningTest extends TestCase
{
    #[Test]
    public function implementsNodePositioningInterface(): void
    {
        self::assertInstanceOf(NodePositioning::class, new BrandesKopfPositioning());
    }

    #[Test]
    public function reservesRightClearanceForASelfLoopNode(): void
    {
        // X carries a self-loop whose lane sits at X.column + boxWidth + 1; its
        // right sibling Y must keep at least one empty column past that lane.
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [new Node('R', 'R'), new Node('X', 'X'), new Node('Y', 'Y')],
            edges: [new Edge('R', 'X'), new Edge('R', 'Y'), new Edge('X', 'X')],
        );

        (new BrandesKopfPositioning())->position($layoutGraph);

        $nodeX = $layoutGraph->getLayoutNode('X');
        $nodeY = $layoutGraph->getLayoutNode('Y');

        self::assertGreaterThanOrEqual(
            $nodeX->column + $nodeX->boxWidth() + 3,
            $nodeY->column,
            'Right sibling must clear the self-loop lane with a gap',
        );
    }

    #[Test]
    public function positionsSingleNodeAtOrigin(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Alpha')],
        );

        (new BrandesKopfPositioning())->position($layoutGraph);

        $node = $layoutGraph->getLayoutNode('A');
        self::assertSame(0, $node->row);
        self::assertSame(0, $node->column);
    }

    #[Test]
    public function alignsTwoNodeChainVertically(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Short'), new Node('B', 'Very Long Node Title Here')],
            edges: [new Edge('A', 'B')],
        );

        (new BrandesKopfPositioning())->position($layoutGraph);

        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeB = $layoutGraph->getLayoutNode('B');

        $centerA = $nodeA->column + intdiv($nodeA->boxWidth(), 2);
        $centerB = $nodeB->column + intdiv($nodeB->boxWidth(), 2);

        self::assertSame($centerA, $centerB, 'Two-node chain centers must align vertically');
    }

    #[Test]
    public function alignsAChildUnderItsOnlyParentAcrossLayers(): void
    {
        // A fans to B and C; only B has a child D. Naive per-layer centering
        // drops D in the middle of its (single-node) layer, away from B. Real
        // Brandes-Köpf aligns D to its sole neighbour B, so their centres match.
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'A'), new Node('B', 'B'), new Node('C', 'C'), new Node('D', 'D')],
            edges: [new Edge('A', 'B'), new Edge('A', 'C'), new Edge('B', 'D')],
        );

        (new BrandesKopfPositioning())->position($layoutGraph);

        $nodeB = $layoutGraph->getLayoutNode('B');
        $nodeD = $layoutGraph->getLayoutNode('D');
        $centerB = $nodeB->column + intdiv($nodeB->boxWidth(), 2);
        $centerD = $nodeD->column + intdiv($nodeD->boxWidth(), 2);

        self::assertSame($centerB, $centerD, 'D must align under its only parent B');
    }

    #[Test]
    public function positionsDiamondWithSeparatedMiddleNodes(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [
                new Node('A', 'A'),
                new Node('B', 'B'),
                new Node('C', 'C'),
                new Node('D', 'D'),
            ],
            edges: [
                new Edge('A', 'B'),
                new Edge('A', 'C'),
                new Edge('B', 'D'),
                new Edge('C', 'D'),
            ],
        );

        (new BrandesKopfPositioning())->position($layoutGraph);

        $nodeB = $layoutGraph->getLayoutNode('B');
        $nodeC = $layoutGraph->getLayoutNode('C');

        self::assertLessThan(
            $nodeC->column,
            $nodeB->column + $nodeB->boxWidth(),
            'B and C must not overlap',
        );

        $gap = $nodeC->column - ($nodeB->column + $nodeB->boxWidth());
        self::assertGreaterThanOrEqual(2, $gap, 'B and C must have at least horizontalSpacing gap');
    }

    #[Test]
    public function spreadsFanOutChildrenWithMinimumSpacing(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [
                new Node('A', 'Hub'),
                new Node('B', 'B'),
                new Node('C', 'C'),
                new Node('D', 'D'),
            ],
            edges: [
                new Edge('A', 'B'),
                new Edge('A', 'C'),
                new Edge('A', 'D'),
            ],
        );

        (new BrandesKopfPositioning())->position($layoutGraph);

        $nodeB = $layoutGraph->getLayoutNode('B');
        $nodeC = $layoutGraph->getLayoutNode('C');
        $nodeD = $layoutGraph->getLayoutNode('D');

        self::assertLessThan($nodeC->column, $nodeB->column + $nodeB->boxWidth());
        self::assertLessThan($nodeD->column, $nodeC->column + $nodeC->boxWidth());

        $gapBC = $nodeC->column - ($nodeB->column + $nodeB->boxWidth());
        $gapCD = $nodeD->column - ($nodeC->column + $nodeC->boxWidth());
        self::assertGreaterThanOrEqual(2, $gapBC);
        self::assertGreaterThanOrEqual(2, $gapCD);
    }

    #[Test]
    public function keepsMultiLayerDummyChainStraightWithRealNodesInSameLayers(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [
                new Node('A', 'Top'),
                new Node('B', 'Mid1'),
                new Node('C', 'Mid2'),
                new Node('D', 'Bottom'),
                new Node('E', 'Side'),
            ],
            edges: [
                new Edge('A', 'B'),
                new Edge('B', 'C'),
                new Edge('C', 'D'),
                new Edge('A', 'D'),
                new Edge('A', 'E'),
                new Edge('E', 'D'),
            ],
            insertDummies: true,
        );

        (new BrandesKopfPositioning())->position($layoutGraph);

        $dummyColumns = [];
        foreach ($layoutGraph->nodeIds() as $nodeId) {
            $node = $layoutGraph->getLayoutNode($nodeId);
            if ($node instanceof DummyLayoutNode
                && 'A' === $node->originalEdgeSourceId
                && 'D' === $node->originalEdgeTargetId
            ) {
                $dummyColumns[] = $node->column;
            }
        }

        self::assertCount(2, $dummyColumns, 'A→D should have 2 dummy nodes');
        self::assertSame($dummyColumns[0], $dummyColumns[1], 'All dummies in a chain must share the same column');
    }

    #[Test]
    public function alignedDummyChainDoesNotOverlapWideRealNodes(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [
                new Node('A', 'A'),
                new Node('B', 'B'),
                new Node('C', 'A Very Wide Node Title Here Indeed'),
                new Node('E', 'E'),
            ],
            edges: [
                new Edge('A', 'B'),
                new Edge('B', 'C'),
                new Edge('C', 'E'),
                new Edge('A', 'E'),
            ],
            insertDummies: true,
        );

        (new BrandesKopfPositioning())->position($layoutGraph);

        foreach ($layoutGraph->nodeIds() as $nodeId) {
            $node = $layoutGraph->getLayoutNode($nodeId);
            if (!$node instanceof DummyLayoutNode) {
                continue;
            }
            foreach ($layoutGraph->layerIndex()[$node->layer] ?? [] as $otherId) {
                $other = $layoutGraph->getLayoutNode($otherId);
                if ($other instanceof DummyLayoutNode) {
                    continue;
                }
                self::assertFalse(
                    $node->column >= $other->column && $node->column < $other->column + $other->boxWidth(),
                    sprintf('Dummy %s at column %d must not sit inside %s (columns %d-%d)', $nodeId, $node->column, $otherId, $other->column, $other->column + $other->boxWidth() - 1),
                );
            }
        }
    }

    #[Test]
    public function alignedDummyChainKeepsAGapFromOtherDummyLanes(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [
                new Node('A', 'A'),
                new Node('B', 'BBBBBBB'),
                new Node('C', 'CCCCCC'),
                new Node('D', 'D'),
            ],
            edges: [
                new Edge('A', 'B'),
                new Edge('B', 'C'),
                new Edge('C', 'D'),
                new Edge('A', 'D'),
                new Edge('B', 'D'),
            ],
            insertDummies: true,
        );

        (new BrandesKopfPositioning())->position($layoutGraph);

        foreach ($layoutGraph->layerIndex() as $layer => $nodeIds) {
            $dummyColumns = [];
            foreach ($nodeIds as $nodeId) {
                if ($layoutGraph->getLayoutNode($nodeId) instanceof DummyLayoutNode) {
                    $dummyColumns[] = $layoutGraph->getLayoutNode($nodeId)->column;
                }
            }
            sort($dummyColumns);
            for ($dummyOffset = 1; $dummyOffset < count($dummyColumns); ++$dummyOffset) {
                self::assertGreaterThan(
                    1,
                    $dummyColumns[$dummyOffset] - $dummyColumns[$dummyOffset - 1],
                    sprintf('Dummy lanes in layer %d must keep at least one empty column between them, got columns %s', $layer, implode(', ', $dummyColumns)),
                );
            }
        }
    }

    #[Test]
    public function dummyChainDoesNotOverlapRealNodes(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [
                new Node('A', 'Root'),
                new Node('B', 'Left'),
                new Node('C', 'Right'),
                new Node('D', 'Bottom'),
            ],
            edges: [
                new Edge('A', 'B'),
                new Edge('A', 'C'),
                new Edge('B', 'D'),
                new Edge('C', 'D'),
                new Edge('A', 'D'),
            ],
            insertDummies: true,
        );

        (new BrandesKopfPositioning())->position($layoutGraph);

        foreach ($layoutGraph->nodeIds() as $nodeId) {
            $node = $layoutGraph->getLayoutNode($nodeId);
            if ($node instanceof DummyLayoutNode) {
                $dummyColumn = $node->column;
                $dummyLayer = $node->layer;

                foreach ($layoutGraph->layerIndex()[$dummyLayer] ?? [] as $otherId) {
                    if ($otherId === $nodeId) {
                        continue;
                    }
                    $other = $layoutGraph->getLayoutNode($otherId);
                    $otherEnd = $other->column + $other->boxWidth();
                    self::assertTrue(
                        $dummyColumn >= $otherEnd || $dummyColumn + 1 <= $other->column,
                        sprintf(
                            'Dummy %s at column %d overlaps with %s at columns %d-%d',
                            $nodeId, $dummyColumn, $otherId, $other->column, $otherEnd - 1,
                        ),
                    );
                }
            }
        }
    }

    #[Test]
    public function assignsRowsWithDefaultVerticalSpacing(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta')],
            edges: [new Edge('A', 'B')],
        );

        (new BrandesKopfPositioning())->position($layoutGraph);

        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeB = $layoutGraph->getLayoutNode('B');

        self::assertSame(0, $nodeA->row);
        self::assertSame($nodeA->boxHeight() + 3, $nodeB->row, 'Row gap must be boxHeight + default verticalSpacing(3)');
    }

    #[Test]
    public function assignsColumnsWithDefaultHorizontalSpacing(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta'), new Node('C', 'Gamma')],
            edges: [new Edge('A', 'C'), new Edge('B', 'C')],
        );

        (new BrandesKopfPositioning())->position($layoutGraph);

        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeB = $layoutGraph->getLayoutNode('B');

        self::assertSame($nodeA->boxWidth() + 2, $nodeB->column - $nodeA->column, 'Sibling column gap must be boxWidth + default horizontalSpacing(2)');
    }

    #[Test]
    public function respectsCustomSpacing(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Hi'), new Node('B', 'Ho')],
            edges: [new Edge('A', 'B')],
        );

        (new BrandesKopfPositioning(horizontalSpacing: 4, verticalSpacing: 1))->position($layoutGraph);

        $nodeA = $layoutGraph->getLayoutNode('A');
        $nodeB = $layoutGraph->getLayoutNode('B');
        self::assertSame($nodeA->boxHeight() + 1, $nodeB->row);
    }

    #[Test]
    public function alignsLaterChainWhenEarlierChainHasSingleDummy(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [
                new Node('A', 'A'),
                new Node('B', 'B'),
                new Node('C', 'C'),
                new Node('D', 'D'),
            ],
            edges: [
                new Edge('A', 'B'),
                new Edge('B', 'C'),
                new Edge('C', 'D'),
                new Edge('A', 'C'),
                new Edge('A', 'D'),
            ],
            insertDummies: true,
        );

        (new BrandesKopfPositioning())->position($layoutGraph);

        $dummyColumns = $this->dummyChainColumns($layoutGraph, 'A', 'D');

        self::assertCount(2, $dummyColumns, 'A→D should have 2 dummy nodes');
        self::assertSame(
            $dummyColumns[0],
            $dummyColumns[1],
            'The A→D chain must be aligned even though the single-dummy A→C chain is processed first',
        );
    }

    #[Test]
    public function keepsALongChainStraightAndClearOfAWideRealNode(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodes: [
                new Node('A', 'A'),
                new Node('B', 'B'),
                new Node('C', 'C very wide indeed!'),
                new Node('D', 'DDDD'),
                new Node('E', 'E'),
            ],
            edges: [
                new Edge('A', 'B'),
                new Edge('B', 'C'),
                new Edge('C', 'D'),
                new Edge('D', 'E'),
                new Edge('A', 'E'),
            ],
            insertDummies: true,
        );

        (new BrandesKopfPositioning())->position($layoutGraph);

        $dummyColumns = $this->dummyChainColumns($layoutGraph, 'A', 'E');

        self::assertCount(3, $dummyColumns, 'A→E should have 3 dummy nodes');
        self::assertSame(
            [$dummyColumns[0], $dummyColumns[0]],
            [$dummyColumns[1], $dummyColumns[2]],
            'The whole A→E inner-segment chain must stay on one straight column',
        );

        $wideNode = $layoutGraph->getLayoutNode('C');
        self::assertTrue(
            $dummyColumns[0] >= $wideNode->column + $wideNode->boxWidth() || $dummyColumns[0] < $wideNode->column,
            'The straight chain must sit clear of the wide node, not inside it',
        );
    }

    /** @return list<int> */
    private function dummyChainColumns(LayoutGraph $layoutGraph, string $sourceId, string $targetId): array
    {
        $dummyColumns = [];
        foreach ($layoutGraph->nodeIds() as $nodeId) {
            $node = $layoutGraph->getLayoutNode($nodeId);
            if ($node instanceof DummyLayoutNode
                && $sourceId === $node->originalEdgeSourceId
                && $targetId === $node->originalEdgeTargetId
            ) {
                $dummyColumns[] = $node->column;
            }
        }

        return $dummyColumns;
    }

    /**
     * @param list<Node> $nodes
     * @param list<Edge> $edges
     */
    private function buildLayeredGraph(array $nodes, array $edges = [], bool $insertDummies = false): LayoutGraph
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

        if ($insertDummies) {
            (new DummyNodeInserter())->process($layoutGraph);
        }

        return $layoutGraph;
    }
}
