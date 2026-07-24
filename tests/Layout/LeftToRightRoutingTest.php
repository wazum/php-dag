<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\DummyLayoutNode;
use PhpDag\Layout\DummyNodeInserter;
use PhpDag\Layout\EdgeRouting;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LeftToRightPositioning;
use PhpDag\Layout\LeftToRightRouting;
use PhpDag\Layout\LongestPathLayering;
use PhpDag\Layout\RealLayoutNode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LeftToRightRoutingTest extends TestCase
{
    #[Test]
    public function implementsEdgeRoutingInterface(): void
    {
        self::assertInstanceOf(EdgeRouting::class, new LeftToRightRouting());
    }

    #[Test]
    public function routesDirectEdgeWithVerticalBend(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta'), new Node('C', 'Gamma')],
            edges: [new Edge('A', 'B'), new Edge('A', 'C')],
        );

        (new LeftToRightRouting())->route($graph);

        $edgeAB = $graph->edges()[0];
        self::assertCount(3, $edgeAB->waypoints, 'L-shape needs exactly 3 waypoints');

        $exitWaypoint = $edgeAB->waypoints[0];
        $bendWaypoint = $edgeAB->waypoints[1];
        self::assertSame($exitWaypoint->column, $bendWaypoint->column, 'Bend must be at exit column (layer right side), not midpoint');
    }

    #[Test]
    public function routesStraightHorizontalDirectEdge(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta')],
            edges: [new Edge('A', 'B')],
        );

        (new LeftToRightRouting())->route($graph);

        $edge = $graph->edges()[0];
        self::assertCount(2, $edge->waypoints);
        self::assertSame($edge->waypoints[0]->row, $edge->waypoints[1]->row, 'Straight horizontal edge must have same row');
    }

    #[Test]
    public function keepsReversedCycleEdgesOnTheAcyclicRouteBeforeFeedbackRouting(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta')],
            edges: [new Edge('A', 'B')],
        );
        $graph->addEdge(new LayoutEdge(new Edge('B', 'A'), reversed: true));

        (new LeftToRightRouting())->route($graph);

        $forwardEdge = $graph->edges()[0];
        $backEdge = $graph->edges()[1];

        self::assertFalse($forwardEdge->reversed);
        self::assertTrue($backEdge->reversed);
        self::assertSame(
            $forwardEdge->waypoints[0]->row,
            $backEdge->waypoints[0]->row,
            'FeedbackEdgeRouter is responsible for moving reversed cycle edges onto separate lanes',
        );
    }

    #[Test]
    public function routesAllEdgesInFanOut(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta'), new Node('C', 'Gamma'), new Node('D', 'Delta')],
            edges: [new Edge('A', 'B'), new Edge('A', 'C'), new Edge('A', 'D')],
        );

        (new LeftToRightRouting())->route($graph);

        foreach ($graph->edges() as $edge) {
            self::assertNotEmpty(
                $edge->waypoints,
                sprintf('Edge %s->%s has no waypoints', $edge->sourceId(), $edge->targetId()),
            );
        }
    }

    #[Test]
    public function routesChainWithPreferredRowPropagation(): void
    {
        $graph = new LayoutGraph();

        $sourceNode = new RealLayoutNode('A', new Node('A', 'Top'));
        $sourceNode->layer = 0;
        $sourceNode->row = 0;
        $sourceNode->column = 0;
        $graph->addNode($sourceNode);

        $dummy1 = new DummyLayoutNode('__dummy_A_T_1', 'A', 'T');
        $dummy1->layer = 1;
        $dummy1->row = 0;
        $dummy1->column = 20;
        $graph->addNode($dummy1);

        $dummy2 = new DummyLayoutNode('__dummy_A_T_2', 'A', 'T');
        $dummy2->layer = 2;
        $dummy2->row = 0;
        $dummy2->column = 25;
        $graph->addNode($dummy2);

        $targetNode = new RealLayoutNode('T', new Node('T', 'Target'));
        $targetNode->layer = 3;
        $targetNode->row = 0;
        $targetNode->column = 30;
        $graph->addNode($targetNode);

        $graph->addEdge(new LayoutEdge(edge: new Edge('A', '__dummy_A_T_1')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('__dummy_A_T_1', '__dummy_A_T_2')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('__dummy_A_T_2', 'T')));
        $graph->buildLayerIndex();

        (new LeftToRightRouting())->route($graph);

        $firstEdge = $graph->outgoingEdges('A')[0];
        $sourceRow = $sourceNode->row + intdiv($sourceNode->boxHeight(), 2);
        self::assertSame($sourceRow, $firstEdge->waypoints[0]->row, 'First chain segment must exit at source vertical center');

        $secondEdge = $graph->outgoingEdges('__dummy_A_T_1')[0];
        self::assertSame(
            $firstEdge->waypoints[0]->row,
            $secondEdge->waypoints[0]->row,
            'Second chain segment must continue at same row (preferred row propagation)',
        );

        $thirdEdge = $graph->outgoingEdges('__dummy_A_T_2')[0];
        self::assertSame(
            $secondEdge->waypoints[0]->row,
            $thirdEdge->waypoints[0]->row,
            'Third chain segment must continue at same row (preferred row propagation through chain)',
        );
    }

    #[Test]
    public function routesStraightWhenCentersDifferByOneRow(): void
    {
        $graph = new LayoutGraph();

        $sourceNode = new RealLayoutNode('A', new Node('A', 'Source'));
        $sourceNode->layer = 0;
        $sourceNode->row = 0;
        $sourceNode->column = 0;
        $graph->addNode($sourceNode);

        $targetNode = new RealLayoutNode('B', new Node('B', 'Target', ['line1', 'line2']));
        $targetNode->layer = 1;
        $targetNode->row = 0;
        $targetNode->column = 15;
        $graph->addNode($targetNode);

        $graph->addEdge(new LayoutEdge(edge: new Edge('A', 'B')));
        $graph->buildLayerIndex();

        $exitRow = $sourceNode->row + intdiv($sourceNode->boxHeight(), 2);
        $targetRow = $targetNode->row + intdiv($targetNode->boxHeight(), 2);
        self::assertSame(1, abs($exitRow - $targetRow), 'Centers must differ by exactly 1 for this test');

        (new LeftToRightRouting())->route($graph);

        $edge = $graph->edges()[0];
        self::assertCount(2, $edge->waypoints, 'A 1-row offset should route straight instead of creating an L-shaped jog');
        self::assertSame($edge->waypoints[0]->row, $edge->waypoints[1]->row, 'Both waypoints must share the same row');
    }

    #[Test]
    public function bendsChainWhenPreferredRowConflictsWithRealNode(): void
    {
        $graph = new LayoutGraph();

        $sourceNode = new RealLayoutNode('A', new Node('A', 'Source'));
        $sourceNode->layer = 0;
        $sourceNode->row = 0;
        $sourceNode->column = 0;
        $graph->addNode($sourceNode);

        $dummy1 = new DummyLayoutNode('__dummy_A_T_1', 'A', 'T');
        $dummy1->layer = 1;
        $dummy1->row = 0;
        $dummy1->column = 20;
        $graph->addNode($dummy1);

        $tallNode = new RealLayoutNode('W', new Node('W', 'TallNode'));
        $tallNode->layer = 2;
        $tallNode->row = 0;
        $tallNode->column = 25;
        $graph->addNode($tallNode);

        $dummy2 = new DummyLayoutNode('__dummy_A_T_2', 'A', 'T');
        $dummy2->layer = 2;
        $dummy2->row = 10;
        $dummy2->column = 30;
        $graph->addNode($dummy2);

        $targetNode = new RealLayoutNode('T', new Node('T', 'Target'));
        $targetNode->layer = 3;
        $targetNode->row = 10;
        $targetNode->column = 35;
        $graph->addNode($targetNode);

        $graph->addEdge(new LayoutEdge(edge: new Edge('A', '__dummy_A_T_1')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('__dummy_A_T_1', '__dummy_A_T_2')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('__dummy_A_T_2', 'T')));
        $graph->buildLayerIndex();

        (new LeftToRightRouting())->route($graph);

        $sourceCenter = $sourceNode->row + intdiv($sourceNode->boxHeight(), 2);

        $secondEdge = $graph->outgoingEdges('__dummy_A_T_1')[0];
        $inheritedRow = $secondEdge->waypoints[0]->row;

        self::assertTrue(
            $inheritedRow >= $tallNode->row && $inheritedRow < $tallNode->row + $tallNode->boxHeight(),
            'Inherited row must overlap with the tall node for this test to be valid',
        );

        $lastWaypoint = $secondEdge->waypoints[count($secondEdge->waypoints) - 1];
        self::assertNotSame(
            $sourceCenter,
            $lastWaypoint->row,
            'When inherited row conflicts with a real node, the chain must bend away',
        );
    }

    #[Test]
    public function routesLongEdgeThroughDummies(): void
    {
        $graph = $this->buildPositionedGraphWithDummies(
            nodes: [new Node('A', 'Top'), new Node('B', 'Middle'), new Node('C', 'Bottom')],
            edges: [new Edge('A', 'B'), new Edge('B', 'C'), new Edge('A', 'C')],
        );

        (new LeftToRightRouting())->route($graph);

        foreach ($graph->edges() as $edge) {
            self::assertNotEmpty(
                $edge->waypoints,
                sprintf('Edge %s->%s has no waypoints', $edge->sourceId(), $edge->targetId()),
            );
        }
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
        (new LeftToRightPositioning())->position($layoutGraph);

        return $layoutGraph;
    }

    /**
     * @param list<Node> $nodes
     * @param list<Edge> $edges
     */
    private function buildPositionedGraphWithDummies(array $nodes, array $edges = []): LayoutGraph
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
        (new LeftToRightPositioning())->position($layoutGraph);

        return $layoutGraph;
    }
}
