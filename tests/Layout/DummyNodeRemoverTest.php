<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Label;
use PhpDag\Graph\Node;
use PhpDag\Layout\BrandesKopfPositioning;
use PhpDag\Layout\ChainAwareRouting;
use PhpDag\Layout\CycleBreaker;
use PhpDag\Layout\DummyLayoutNode;
use PhpDag\Layout\DummyNodeInserter;
use PhpDag\Layout\DummyNodeRemover;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LongestPathLayering;
use PhpDag\Render\Waypoint;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DummyNodeRemoverTest extends TestCase
{
    #[Test]
    public function removesAllDummyNodes(): void
    {
        $graph = $this->buildLayeredGraphWithDummies(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta'), new Node('C', 'Gamma')],
            edges: [new Edge('A', 'B'), new Edge('B', 'C'), new Edge('A', 'C')],
        );

        self::assertSame(4, $graph->nodeCount());

        (new DummyNodeRemover())->process($graph);

        self::assertSame(3, $graph->nodeCount());
        self::assertFalse($graph->hasNode('__dummy_A_C_1'));
    }

    #[Test]
    public function restoresOriginalEdge(): void
    {
        $graph = $this->buildLayeredGraphWithDummies(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta'), new Node('C', 'Gamma')],
            edges: [new Edge('A', 'B'), new Edge('B', 'C'), new Edge('A', 'C')],
        );

        (new DummyNodeRemover())->process($graph);

        self::assertSame(['B', 'C'], $graph->successors('A'));
        self::assertSame(['B', 'A'], $graph->predecessors('C'));
    }

    #[Test]
    public function mergesWaypointsFromChainEdgesOntoRestoredEdge(): void
    {
        $graph = $this->buildLayeredGraphWithDummies(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta'), new Node('C', 'Gamma')],
            edges: [new Edge('A', 'B'), new Edge('B', 'C'), new Edge('A', 'C')],
        );

        $chainEdges = $graph->outgoingEdges('A');
        foreach ($chainEdges as $chainEdge) {
            if ('__dummy_A_C_1' === $chainEdge->targetId()) {
                $chainEdge->waypoints = [new Waypoint(3, 5), new Waypoint(6, 5)];
            }
        }
        foreach ($graph->outgoingEdges('__dummy_A_C_1') as $chainEdge) {
            if ('C' === $chainEdge->targetId()) {
                $chainEdge->waypoints = [new Waypoint(6, 5), new Waypoint(9, 5)];
            }
        }

        (new DummyNodeRemover())->process($graph);

        $restoredEdge = null;
        foreach ($graph->outgoingEdges('A') as $edge) {
            if ('C' === $edge->targetId()) {
                $restoredEdge = $edge;
            }
        }

        self::assertNotNull($restoredEdge);
        self::assertCount(3, $restoredEdge->waypoints);
        self::assertEquals(new Waypoint(3, 5), $restoredEdge->waypoints[0]);
        self::assertEquals(new Waypoint(6, 5), $restoredEdge->waypoints[1]);
        self::assertEquals(new Waypoint(9, 5), $restoredEdge->waypoints[2]);
    }

    #[Test]
    public function mergesWaypointsAcrossTwoDummies(): void
    {
        $graph = $this->buildLayeredGraphWithDummies(
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

        foreach ($graph->outgoingEdges('A') as $edge) {
            if ('__dummy_A_D_1' === $edge->targetId()) {
                $edge->waypoints = [new Waypoint(3, 10), new Waypoint(6, 10)];
            }
        }
        foreach ($graph->outgoingEdges('__dummy_A_D_1') as $edge) {
            if ('__dummy_A_D_2' === $edge->targetId()) {
                $edge->waypoints = [new Waypoint(6, 10), new Waypoint(9, 10)];
            }
        }
        foreach ($graph->outgoingEdges('__dummy_A_D_2') as $edge) {
            if ('D' === $edge->targetId()) {
                $edge->waypoints = [new Waypoint(9, 10), new Waypoint(12, 10)];
            }
        }

        (new DummyNodeRemover())->process($graph);

        $restoredEdge = null;
        foreach ($graph->outgoingEdges('A') as $edge) {
            if ('D' === $edge->targetId()) {
                $restoredEdge = $edge;
            }
        }

        self::assertNotNull($restoredEdge);
        self::assertCount(4, $restoredEdge->waypoints);
        self::assertEquals(new Waypoint(3, 10), $restoredEdge->waypoints[0]);
        self::assertEquals(new Waypoint(6, 10), $restoredEdge->waypoints[1]);
        self::assertEquals(new Waypoint(9, 10), $restoredEdge->waypoints[2]);
        self::assertEquals(new Waypoint(12, 10), $restoredEdge->waypoints[3]);
    }

    #[Test]
    public function removesMultipleDummyChainsAndRestoresAllEdges(): void
    {
        $graph = $this->buildLayeredGraphWithDummies(
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

        self::assertTrue($graph->hasNode('__dummy_A_C_1'));
        self::assertTrue($graph->hasNode('__dummy_B_D_2'));

        (new DummyNodeRemover())->process($graph);

        self::assertFalse($graph->hasNode('__dummy_A_C_1'));
        self::assertFalse($graph->hasNode('__dummy_B_D_2'));
        self::assertContains('C', $graph->successors('A'));
        self::assertContains('D', $graph->successors('B'));
    }

    #[Test]
    public function removesManyDummyNodesWithoutFilteringTheWholeGraphPerNode(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('source', 'Source'));
        $edgeCount = 4000;
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
        (new DummyNodeInserter())->process($layoutGraph);

        $start = hrtime(true);
        (new DummyNodeRemover())->process($layoutGraph);
        $elapsedMilliseconds = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(350.0, $elapsedMilliseconds, sprintf('Dummy-node removal took %.1f ms', $elapsedMilliseconds));
    }

    #[Test]
    public function preservesEdgeStyleWhenRestoringLongEdge(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('A', 'C', edgeStrokeStyle: EdgeStrokeStyle::Dashed));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new BrandesKopfPositioning())->position($layoutGraph);
        (new ChainAwareRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        $restoredEdge = null;
        foreach ($layoutGraph->edges() as $edge) {
            if ('A' === $edge->sourceId() && 'C' === $edge->targetId()) {
                $restoredEdge = $edge;
            }
        }

        self::assertNotNull($restoredEdge);
        self::assertSame(EdgeStrokeStyle::Dashed, $restoredEdge->edge->edgeStrokeStyle);
    }

    #[Test]
    public function preservesReversedFlagWhenRestoringLongBackEdge(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('C', 'A'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new CycleBreaker())->process($layoutGraph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new BrandesKopfPositioning())->position($layoutGraph);
        (new ChainAwareRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        $restoredEdge = null;
        foreach ($layoutGraph->edges() as $edge) {
            if ($edge->reversed) {
                $restoredEdge = $edge;
            }
        }

        self::assertNotNull($restoredEdge);
        self::assertSame('C', $restoredEdge->edge->sourceId);
        self::assertSame('A', $restoredEdge->edge->targetId);
        self::assertSame('A', $restoredEdge->sourceId());
        self::assertSame('C', $restoredEdge->targetId());
        self::assertSame(EdgeStrokeStyle::Dashed, $restoredEdge->edge->edgeStrokeStyle);
    }

    #[Test]
    public function carriesTheCorridorLaneColumnOntoTheRestoredEdge(): void
    {
        $graph = new Graph();
        foreach (['A', 'B', 'C'] as $id) {
            $graph->addNode(new Node($id, $id));
        }
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('A', 'C', label: new Label('lbl-99')));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        foreach ((new LongestPathLayering())->assign($layoutGraph) as $id => $layer) {
            $layoutGraph->getLayoutNode($id)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new BrandesKopfPositioning())->position($layoutGraph);

        $corridor = $layoutGraph->getLayoutNode('__dummy_A_C_1');
        $expectedLane = $corridor->column + intdiv($corridor->boxWidth(), 2);

        (new ChainAwareRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        $restored = null;
        foreach ($layoutGraph->edges() as $edge) {
            if (null !== $edge->edge->label) {
                $restored = $edge;
            }
        }

        self::assertNotNull($restored);
        self::assertSame($expectedLane, $restored->labelLaneColumn);
    }

    #[Test]
    public function carriesTheCorridorLaneColumnWhenTheCorridorDummyIsMidChain(): void
    {
        $graph = new Graph();
        foreach (['A', 'B', 'C', 'D', 'E'] as $id) {
            $graph->addNode(new Node($id, $id));
        }
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('C', 'D'));
        $graph->addEdge(new Edge('D', 'E'));
        $graph->addEdge(new Edge('A', 'E', label: new Label('lbl-99')));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        foreach ((new LongestPathLayering())->assign($layoutGraph) as $id => $layer) {
            $layoutGraph->getLayoutNode($id)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new BrandesKopfPositioning())->position($layoutGraph);

        $corridor = null;
        foreach ($layoutGraph->nodeIds() as $nodeId) {
            $node = $layoutGraph->getLayoutNode($nodeId);
            if ($node instanceof DummyLayoutNode && $node->corridorWidth > 0) {
                $corridor = $node;
            }
        }
        self::assertNotNull($corridor);
        self::assertSame(2, $corridor->layer);
        $expectedLane = $corridor->column + intdiv($corridor->boxWidth(), 2);

        (new ChainAwareRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        $restored = null;
        foreach ($layoutGraph->edges() as $edge) {
            if (null !== $edge->edge->label) {
                $restored = $edge;
            }
        }

        self::assertNotNull($restored);
        self::assertSame($expectedLane, $restored->labelLaneColumn);
    }

    /**
     * @param list<Node> $nodes
     * @param list<Edge> $edges
     */
    private function buildLayeredGraphWithDummies(array $nodes, array $edges = []): LayoutGraph
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

        (new DummyNodeInserter())->process($layoutGraph);

        return $layoutGraph;
    }
}
