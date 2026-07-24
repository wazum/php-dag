<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\DummyLayoutNode;
use PhpDag\Layout\DummyNodeInserter;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LongestPathLayering;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DummyNodeInserterTest extends TestCase
{
    #[Test]
    public function leavesUnitEdgesUntouched(): void
    {
        $graph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta')],
            edges: [new Edge('A', 'B')],
        );

        (new DummyNodeInserter())->process($graph);

        self::assertSame(2, $graph->nodeCount());
        self::assertCount(1, $graph->edges());
        self::assertSame('A', $graph->edges()[0]->sourceId());
        self::assertSame('B', $graph->edges()[0]->targetId());
    }

    #[Test]
    public function insertsOneDummyForSpanTwoEdge(): void
    {
        $graph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta'), new Node('C', 'Gamma')],
            edges: [new Edge('A', 'B'), new Edge('B', 'C'), new Edge('A', 'C')],
        );

        (new DummyNodeInserter())->process($graph);

        self::assertTrue($graph->hasNode('__dummy_A_C_1'));
        self::assertSame(1, $graph->getLayoutNode('__dummy_A_C_1')->layer);
        self::assertInstanceOf(DummyLayoutNode::class, $graph->getLayoutNode('__dummy_A_C_1'));
    }

    #[Test]
    public function replacesLongEdgeWithDummyChain(): void
    {
        $graph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta'), new Node('C', 'Gamma')],
            edges: [new Edge('A', 'B'), new Edge('B', 'C'), new Edge('A', 'C')],
        );

        (new DummyNodeInserter())->process($graph);

        self::assertSame(['B', '__dummy_A_C_1'], $graph->successors('A'));
        self::assertSame(['B', '__dummy_A_C_1'], $graph->predecessors('C'));
    }

    #[Test]
    public function totalEdgeCountMatchesAfterInsertion(): void
    {
        $graph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta'), new Node('C', 'Gamma')],
            edges: [new Edge('A', 'B'), new Edge('B', 'C'), new Edge('A', 'C')],
        );

        (new DummyNodeInserter())->process($graph);

        self::assertCount(4, $graph->edges());
    }

    #[Test]
    public function rebuildsLayerIndexToIncludeDummyNodes(): void
    {
        $graph = $this->buildLayeredGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta'), new Node('C', 'Gamma')],
            edges: [new Edge('A', 'B'), new Edge('B', 'C'), new Edge('A', 'C')],
        );

        (new DummyNodeInserter())->process($graph);

        $layerIndex = $graph->layerIndex();
        self::assertContains('__dummy_A_C_1', $layerIndex[1]);
    }

    #[Test]
    public function insertsTwoDummiesForSpanThreeEdge(): void
    {
        $graph = $this->buildLayeredGraph(
            nodes: [
                new Node('A', 'Alpha'),
                new Node('B', 'Beta'),
                new Node('C', 'Gamma'),
                new Node('D', 'Delta'),
            ],
            edges: [
                new Edge('A', 'B'),
                new Edge('B', 'C'),
                new Edge('C', 'D'),
                new Edge('A', 'D'),
            ],
        );

        (new DummyNodeInserter())->process($graph);

        self::assertTrue($graph->hasNode('__dummy_A_D_1'));
        self::assertTrue($graph->hasNode('__dummy_A_D_2'));
        self::assertSame(1, $graph->getLayoutNode('__dummy_A_D_1')->layer);
        self::assertSame(2, $graph->getLayoutNode('__dummy_A_D_2')->layer);
        self::assertSame(['__dummy_A_D_2'], $graph->successors('__dummy_A_D_1'));
    }

    #[Test]
    public function insertsMultipleDummyChainsForMultipleLongEdges(): void
    {
        $graph = $this->buildLayeredGraph(
            nodes: [
                new Node('A', 'Alpha'),
                new Node('B', 'Beta'),
                new Node('C', 'Gamma'),
                new Node('D', 'Delta'),
            ],
            edges: [
                new Edge('A', 'B'),
                new Edge('B', 'C'),
                new Edge('C', 'D'),
                new Edge('A', 'C'),
                new Edge('B', 'D'),
            ],
        );

        (new DummyNodeInserter())->process($graph);

        self::assertTrue($graph->hasNode('__dummy_A_C_1'), 'Dummy for A→C must exist');
        self::assertTrue($graph->hasNode('__dummy_B_D_2'), 'Dummy for B→D must exist');
    }

    #[Test]
    public function skipsReversedEdgeButStillProcessesLaterLongEdges(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->addEdge(new LayoutEdge(edge: new Edge('B', 'A'), reversed: true));
        $layoutGraph->addEdge(new LayoutEdge(edge: new Edge('A', 'C')));
        $layoutGraph->getLayoutNode('A')->layer = 0;
        $layoutGraph->getLayoutNode('B')->layer = 1;
        $layoutGraph->getLayoutNode('C')->layer = 2;
        $layoutGraph->buildLayerIndex();

        (new DummyNodeInserter())->process($layoutGraph);

        self::assertTrue($layoutGraph->hasNode('__dummy_A_C_1'), 'A long edge listed after a reversed edge must still receive its dummy chain');
        self::assertFalse($layoutGraph->hasNode('__dummy_B_A_1'), 'The reversed edge itself must not be split');
    }

    #[Test]
    public function expandsManyLongEdgesWithoutFilteringTheWholeGraphPerEdge(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('source', 'Source'));
        $edgeCount = 2000;
        for ($index = 0; $index < $edgeCount; ++$index) {
            $targetId = 'target-'.$index;
            $graph->addNode(new Node($targetId, $targetId));
            $graph->addEdge(new Edge('source', $targetId));
        }

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->getLayoutNode('source')->layer = 0;
        for ($index = 0; $index < $edgeCount; ++$index) {
            $layoutGraph->getLayoutNode('target-'.$index)->layer = 2;
        }

        $start = hrtime(true);
        (new DummyNodeInserter())->process($layoutGraph);
        $elapsedMilliseconds = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(250.0, $elapsedMilliseconds, sprintf('Long-edge expansion took %.1f ms', $elapsedMilliseconds));
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
}
