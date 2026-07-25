<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Label;
use PhpDag\Graph\Node;
use PhpDag\Layout\BrandesKopfPositioning;
use PhpDag\Layout\ChainAwareRouting;
use PhpDag\Layout\DummyLayoutNode;
use PhpDag\Layout\DummyNodeInserter;
use PhpDag\Layout\EdgeRouting;
use PhpDag\Layout\LabelReserver;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LongestPathLayering;
use PhpDag\Layout\RealLayoutNode;
use PhpDag\Render\Waypoint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChainAwareRoutingTest extends TestCase
{
    #[Test]
    public function implementsEdgeRoutingInterface(): void
    {
        self::assertInstanceOf(EdgeRouting::class, new ChainAwareRouting());
    }

    #[Test]
    public function routesStraightVerticalDirectEdge(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta')],
            edges: [new Edge('A', 'B')],
        );

        (new ChainAwareRouting())->route($graph);

        $edge = $graph->edges()[0];
        self::assertCount(2, $edge->waypoints);
        self::assertSame($edge->waypoints[0]->column, $edge->waypoints[1]->column, 'Straight vertical edge must have same column');
    }

    #[Test]
    public function routesDirectEdgeWithBendAtLayerBottom(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta'), new Node('C', 'Gamma')],
            edges: [new Edge('A', 'B'), new Edge('A', 'C')],
        );

        (new ChainAwareRouting())->route($graph);

        $edgeAB = $graph->edges()[0];
        self::assertCount(3, $edgeAB->waypoints, 'L-shape needs exactly 3 waypoints');

        $exitWaypoint = $edgeAB->waypoints[0];
        $bendWaypoint = $edgeAB->waypoints[1];
        self::assertSame($exitWaypoint->row, $bendWaypoint->row, 'Bend must be at exit row (layer bottom), not midpoint');
    }

    #[Test]
    public function bendsLongEdgeAroundRealNodeInDummyLayer(): void
    {
        $graph = $this->buildPositionedGraphWithDummies(
            nodes: [new Node('A', 'Top'), new Node('B', 'Middle'), new Node('C', 'Bottom')],
            edges: [new Edge('A', 'B'), new Edge('B', 'C'), new Edge('A', 'C')],
        );

        (new ChainAwareRouting())->route($graph);

        $sourceEdge = null;
        foreach ($graph->outgoingEdges('A') as $edge) {
            if ('__dummy_A_C_1' === $edge->targetId()) {
                $sourceEdge = $edge;
            }
        }

        self::assertNotNull($sourceEdge);
        self::assertCount(3, $sourceEdge->waypoints, 'First segment must bend (L-shape) because source center overlaps real node B in dummy layer');

        $outgoingDummyEdge = $graph->outgoingEdges('__dummy_A_C_1')[0];
        self::assertNotEmpty($outgoingDummyEdge->waypoints, 'Second segment must have waypoints');
    }

    #[Test]
    public function routesLongEdgeStraightThroughTwoDummiesWithBendAtTarget(): void
    {
        $graph = new LayoutGraph();

        $sourceNode = new RealLayoutNode('A', new Node('A', 'Alpha'));
        $sourceNode->layer = 0;
        $sourceNode->row = 0;
        $sourceNode->column = 0;
        $graph->addNode($sourceNode);

        $realB = new RealLayoutNode('B', new Node('B', 'Beta'));
        $realB->layer = 1;
        $realB->row = 5;
        $realB->column = 0;
        $graph->addNode($realB);

        $dummy1 = new DummyLayoutNode('__dummy_A_D_1', 'A', 'D');
        $dummy1->layer = 1;
        $dummy1->row = 5;
        $dummy1->column = 20;
        $graph->addNode($dummy1);

        $realC = new RealLayoutNode('C', new Node('C', 'Gamma'));
        $realC->layer = 2;
        $realC->row = 10;
        $realC->column = 10;
        $graph->addNode($realC);

        $dummy2 = new DummyLayoutNode('__dummy_A_D_2', 'A', 'D');
        $dummy2->layer = 2;
        $dummy2->row = 10;
        $dummy2->column = 20;
        $graph->addNode($dummy2);

        $targetNode = new RealLayoutNode('D', new Node('D', 'Delta'));
        $targetNode->layer = 3;
        $targetNode->row = 15;
        $targetNode->column = 15;
        $graph->addNode($targetNode);

        $graph->addEdge(new LayoutEdge(edge: new Edge('A', 'B')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('B', 'C')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('C', 'D')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('A', '__dummy_A_D_1')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('__dummy_A_D_1', '__dummy_A_D_2')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('__dummy_A_D_2', 'D')));
        $graph->buildLayerIndex();

        (new ChainAwareRouting())->route($graph);

        $firstEdge = null;
        foreach ($graph->outgoingEdges('A') as $edge) {
            if ('__dummy_A_D_1' === $edge->targetId()) {
                $firstEdge = $edge;
            }
        }
        self::assertNotNull($firstEdge);
        self::assertCount(3, $firstEdge->waypoints, 'First segment must bend (source center overlaps B in dummy layer)');
        $bentColumn = $firstEdge->waypoints[2]->column;

        $secondEdge = $graph->outgoingEdges('__dummy_A_D_1')[0];
        self::assertSame($bentColumn, $secondEdge->waypoints[0]->column, 'Second segment must continue at bent column');
        self::assertSame($bentColumn, $secondEdge->waypoints[1]->column, 'Second segment must stay straight at bent column');

        $lastEdge = null;
        foreach ($graph->outgoingEdges('__dummy_A_D_2') as $edge) {
            if ('D' === $edge->targetId()) {
                $lastEdge = $edge;
            }
        }
        self::assertNotNull($lastEdge);
        self::assertGreaterThanOrEqual(2, count($lastEdge->waypoints), 'Last segment must have waypoints');
    }

    #[Test]
    public function bendsChainWhenInheritedColumnConflictsWithRealNode(): void
    {
        $graph = new LayoutGraph();

        $sourceNode = new RealLayoutNode('A', new Node('A', 'Source'));
        $sourceNode->layer = 0;
        $sourceNode->row = 0;
        $sourceNode->column = 0;
        $graph->addNode($sourceNode);

        $dummy1 = new DummyLayoutNode('__dummy_A_T_1', 'A', 'T');
        $dummy1->layer = 1;
        $dummy1->row = 5;
        $dummy1->column = 20;
        $graph->addNode($dummy1);

        $wideNode = new RealLayoutNode('W', new Node('W', 'VeryWideNode'));
        $wideNode->layer = 2;
        $wideNode->row = 8;
        $wideNode->column = 0;
        $graph->addNode($wideNode);

        $dummy2 = new DummyLayoutNode('__dummy_A_T_2', 'A', 'T');
        $dummy2->layer = 2;
        $dummy2->row = 8;
        $dummy2->column = 20;
        $graph->addNode($dummy2);

        $targetNode = new RealLayoutNode('T', new Node('T', 'Target'));
        $targetNode->layer = 3;
        $targetNode->row = 12;
        $targetNode->column = 20;
        $graph->addNode($targetNode);

        $graph->addEdge(new LayoutEdge(edge: new Edge('A', '__dummy_A_T_1')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('__dummy_A_T_1', '__dummy_A_T_2')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('__dummy_A_T_2', 'T')));
        $graph->buildLayerIndex();

        (new ChainAwareRouting())->route($graph);

        $sourceCenter = $sourceNode->column + intdiv($sourceNode->boxWidth(), 2);

        $secondEdge = $graph->outgoingEdges('__dummy_A_T_1')[0];
        $inheritedColumn = $secondEdge->waypoints[0]->column;

        self::assertTrue(
            $inheritedColumn >= $wideNode->column && $inheritedColumn < $wideNode->column + $wideNode->boxWidth(),
            'Inherited column must overlap with the wide node for this test to be valid',
        );

        $lastWaypoint = $secondEdge->waypoints[count($secondEdge->waypoints) - 1];
        self::assertNotSame(
            $sourceCenter,
            $lastWaypoint->column,
            'When inherited column conflicts with a real node, the chain must bend away',
        );
    }

    #[Test]
    public function bendsChainAwayFromAColumnReservedForAnotherEdgesLabel(): void
    {
        $graph = new LayoutGraph();

        $sourceNode = new RealLayoutNode('X', new Node('X', 'X'));
        $sourceNode->layer = 0;
        $sourceNode->row = 0;
        $sourceNode->column = 0;
        $graph->addNode($sourceNode);

        $dummy = new DummyLayoutNode('__dummy_X_Y_1', 'X', 'Y');
        $dummy->layer = 1;
        $dummy->row = 5;
        $dummy->column = 2;
        $graph->addNode($dummy);

        $targetNode = new RealLayoutNode('Y', new Node('Y', 'Y'));
        $targetNode->layer = 2;
        $targetNode->row = 10;
        $targetNode->column = 0;
        $graph->addNode($targetNode);

        $graph->addEdge(new LayoutEdge(edge: new Edge('X', '__dummy_X_Y_1')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('__dummy_X_Y_1', 'Y')));
        $graph->buildLayerIndex();

        $sourceCenter = $sourceNode->column + intdiv($sourceNode->boxWidth(), 2);
        $graph->reserveLabelSpan($dummy->layer, $sourceCenter - 2, $sourceCenter + 2);

        (new ChainAwareRouting())->route($graph);

        $firstEdge = $graph->outgoingEdges('X')[0];
        $laneColumn = $firstEdge->waypoints[count($firstEdge->waypoints) - 1]->column;

        self::assertTrue(
            $laneColumn < $sourceCenter - 2 || $laneColumn > $sourceCenter + 2,
            'A lane column reserved for another edge\'s label must be avoided, not routed through',
        );
    }

    #[Test]
    public function corridorChainClaimsItsLaneBeforeAnAlreadyQueuedUnlabeledChainCanSquatOnIt(): void
    {
        $graph = new LayoutGraph();

        // Node insertion order matters here: the unlabeled X->Y dummy is added
        // before the corridor S->T dummy, so without the corridor-first sort
        // reconstructChains would route X->Y first and claim column 7 (its
        // natural exit column) before the corridor ever reserves it.
        $unlabeledSource = new RealLayoutNode('X', new Node('X', 'X'));
        $unlabeledSource->layer = 0;
        $unlabeledSource->row = 0;
        $unlabeledSource->column = 5;
        $graph->addNode($unlabeledSource);

        $unlabeledDummy = new DummyLayoutNode('__dummy_X_Y_1', 'X', 'Y');
        $unlabeledDummy->layer = 1;
        $unlabeledDummy->row = 5;
        $unlabeledDummy->column = 7;
        $graph->addNode($unlabeledDummy);

        $unlabeledTarget = new RealLayoutNode('Y', new Node('Y', 'Y'));
        $unlabeledTarget->layer = 2;
        $unlabeledTarget->row = 10;
        $unlabeledTarget->column = 5;
        $graph->addNode($unlabeledTarget);

        $corridorSource = new RealLayoutNode('S', new Node('S', 'S'));
        $corridorSource->layer = 0;
        $corridorSource->row = 0;
        $corridorSource->column = 20;
        $graph->addNode($corridorSource);

        $corridorDummy = new DummyLayoutNode('__dummy_S_T_1', 'S', 'T');
        $corridorDummy->layer = 1;
        $corridorDummy->row = 5;
        $corridorDummy->column = 0;
        $corridorDummy->corridorWidth = 15;
        $graph->addNode($corridorDummy);

        $corridorTarget = new RealLayoutNode('T', new Node('T', 'T'));
        $corridorTarget->layer = 2;
        $corridorTarget->row = 10;
        $corridorTarget->column = 20;
        $graph->addNode($corridorTarget);

        $graph->addEdge(new LayoutEdge(edge: new Edge('X', '__dummy_X_Y_1')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('__dummy_X_Y_1', 'Y')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('S', '__dummy_S_T_1')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('__dummy_S_T_1', 'T')));
        $graph->buildLayerIndex();

        (new ChainAwareRouting())->route($graph);

        $corridorLaneColumn = $corridorDummy->column + intdiv($corridorDummy->boxWidth(), 2);

        $corridorFirstEdge = $graph->outgoingEdges('S')[0];
        self::assertSame(
            $corridorLaneColumn,
            $corridorFirstEdge->waypoints[count($corridorFirstEdge->waypoints) - 1]->column,
            'The corridor chain must reach its fixed lane column',
        );

        $unlabeledFirstEdge = $graph->outgoingEdges('X')[0];
        self::assertNotSame(
            $corridorLaneColumn,
            $unlabeledFirstEdge->waypoints[count($unlabeledFirstEdge->waypoints) - 1]->column,
            'An unlabeled chain queued before the corridor chain must not claim the corridor lane',
        );
    }

    #[Test]
    public function routesChainCorrectlyRegardlessOfEdgeInsertionOrder(): void
    {
        $graph = new LayoutGraph();

        $sourceNode = new RealLayoutNode('A', new Node('A', 'Top'));
        $sourceNode->layer = 0;
        $sourceNode->row = 0;
        $sourceNode->column = 0;
        $graph->addNode($sourceNode);

        $dummy1 = new DummyLayoutNode('__dummy_A_T_1', 'A', 'T');
        $dummy1->layer = 1;
        $dummy1->row = 5;
        $dummy1->column = 20;
        $graph->addNode($dummy1);

        $dummy2 = new DummyLayoutNode('__dummy_A_T_2', 'A', 'T');
        $dummy2->layer = 2;
        $dummy2->row = 8;
        $dummy2->column = 20;
        $graph->addNode($dummy2);

        $targetNode = new RealLayoutNode('T', new Node('T', 'Target'));
        $targetNode->layer = 3;
        $targetNode->row = 12;
        $targetNode->column = 0;
        $graph->addNode($targetNode);

        $graph->addEdge(new LayoutEdge(edge: new Edge('__dummy_A_T_2', 'T')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('__dummy_A_T_1', '__dummy_A_T_2')));
        $graph->addEdge(new LayoutEdge(edge: new Edge('A', '__dummy_A_T_1')));
        $graph->buildLayerIndex();

        (new ChainAwareRouting())->route($graph);

        $sourceCenter = $sourceNode->column + intdiv($sourceNode->boxWidth(), 2);

        $firstEdge = $graph->outgoingEdges('A')[0];
        self::assertSame($sourceCenter, $firstEdge->waypoints[0]->column, 'First chain segment must exit at source center');

        $secondEdge = $graph->outgoingEdges('__dummy_A_T_1')[0];
        self::assertSame(
            $firstEdge->waypoints[0]->column,
            $secondEdge->waypoints[0]->column,
            'Second chain segment must continue at same column as first (preferred column propagation)',
        );

        $thirdEdge = $graph->outgoingEdges('__dummy_A_T_2')[0];
        self::assertSame(
            $secondEdge->waypoints[0]->column,
            $thirdEdge->waypoints[0]->column,
            'Third chain segment must continue at same column (preferred column propagation through chain)',
        );
    }

    #[Test]
    public function routesStraightWhenCentersDifferByOneColumn(): void
    {
        $graph = new LayoutGraph();

        $sourceNode = new RealLayoutNode('A', new Node('A', 'laravel/framework'));
        $sourceNode->layer = 0;
        $sourceNode->row = 0;
        $sourceNode->column = 0;
        $graph->addNode($sourceNode);

        $targetNode = new RealLayoutNode('B', new Node('B', 'monolog/monolog'));
        $targetNode->layer = 1;
        $targetNode->row = 5;
        $targetNode->column = 2;
        $graph->addNode($targetNode);

        $graph->addEdge(new LayoutEdge(edge: new Edge('A', 'B')));
        $graph->buildLayerIndex();

        $exitColumn = $sourceNode->column + intdiv($sourceNode->boxWidth(), 2);
        $targetColumn = $targetNode->column + intdiv($targetNode->boxWidth(), 2);
        self::assertSame(1, abs($exitColumn - $targetColumn), 'Centers must differ by exactly 1 for this test');

        (new ChainAwareRouting())->route($graph);

        $edge = $graph->edges()[0];
        self::assertCount(2, $edge->waypoints, 'A 1-column offset should route straight instead of creating an L-shaped jog');
        self::assertSame($edge->waypoints[0]->column, $edge->waypoints[1]->column, 'Both waypoints must share the same column');
    }

    #[Test]
    public function bendsWhenPreferredColumnOverlapsRealNode(): void
    {
        $graph = $this->buildPositionedGraphWithDummies(
            nodes: [
                new Node('A', 'Wide Source Node'),
                new Node('B', 'Target'),
                new Node('C', 'Another Wide Node Here'),
            ],
            edges: [
                new Edge('A', 'B'),
                new Edge('A', 'C'),
            ],
        );

        (new ChainAwareRouting())->route($graph);

        foreach ($graph->edges() as $edge) {
            self::assertNotEmpty($edge->waypoints, sprintf('Edge %s→%s has no waypoints', $edge->sourceId(), $edge->targetId()));
        }
    }

    #[Test]
    public function labeledChainKeepsItsCorridorLaneExclusive(): void
    {
        $this->assertCorridorLaneIsExclusive(labeledFirst: true);
    }

    #[Test]
    public function labeledChainKeepsItsCorridorLaneExclusiveWhenRoutedAfterTheTrunk(): void
    {
        $this->assertCorridorLaneIsExclusive(labeledFirst: false);
    }

    private function assertCorridorLaneIsExclusive(bool $labeledFirst): void
    {
        $graph = new Graph();
        foreach (['A', 'B', 'C', 'D'] as $id) {
            $graph->addNode(new Node($id, $id));
        }
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('C', 'D'));
        $labeled = new Edge('A', 'D', label: new Label('wide-label-99'));
        $unlabeled = new Edge('B', 'D');
        foreach ($labeledFirst ? [$labeled, $unlabeled] : [$unlabeled, $labeled] as $edge) {
            $graph->addEdge($edge);
        }

        $layoutGraph = LayoutGraph::fromGraph($graph);
        foreach ((new LongestPathLayering())->assign($layoutGraph) as $id => $layer) {
            $layoutGraph->getLayoutNode($id)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new BrandesKopfPositioning())->position($layoutGraph);
        (new LabelReserver())->process($layoutGraph);
        (new ChainAwareRouting())->route($layoutGraph);

        $corridorDummy = null;
        foreach ($layoutGraph->nodeIds() as $nodeId) {
            $node = $layoutGraph->getLayoutNode($nodeId);
            if ($node instanceof DummyLayoutNode && $node->corridorWidth > 0) {
                $corridorDummy = $node;
            }
        }
        self::assertNotNull($corridorDummy);
        $laneColumn = $corridorDummy->column + intdiv($corridorDummy->boxWidth(), 2);

        $laneIntervals = [];
        foreach ($layoutGraph->edges() as $edge) {
            $targetNode = $layoutGraph->getLayoutNode($edge->targetId());
            // The loose string matcher from the brief also flags the terminal
            // hop into the real target D, whose column the corridor never
            // controls (it reuses the ordinary real-target alignment, shared
            // with every other edge converging on D). Match on the dummy
            // chain's identity instead, which naturally excludes that hop.
            $isLabeledChain = $targetNode instanceof DummyLayoutNode && $targetNode->identityKey() === $corridorDummy->identityKey();
            foreach ($this->verticalIntervals($edge->waypoints) as [$column, $fromRow, $toRow]) {
                $laneIntervals[] = [$isLabeledChain, $column, $fromRow, $toRow];
            }
        }

        $corridorIntervals = array_filter($laneIntervals, static fn (array $interval): bool => $interval[0]);
        $otherIntervals = array_filter($laneIntervals, static fn (array $interval): bool => !$interval[0]);

        self::assertNotSame([], $corridorIntervals);
        foreach ($corridorIntervals as [, $column]) {
            self::assertSame($laneColumn, $column, 'Every vertical run of the labeled chain must sit on the corridor lane');
        }
        foreach ($otherIntervals as [, $column, $fromRow, $toRow]) {
            if ($column !== $laneColumn) {
                continue;
            }
            foreach ($corridorIntervals as [, , $corridorFrom, $corridorTo]) {
                self::assertFalse(
                    $fromRow <= $corridorTo && $toRow >= $corridorFrom,
                    'No other lane may overlap the corridor lane over the same rows',
                );
            }
        }
    }

    /**
     * @param list<Waypoint> $waypoints
     *
     * @return list<array{int, int, int}> [column, fromRow, toRow] per vertical run
     */
    private function verticalIntervals(array $waypoints): array
    {
        $intervals = [];
        for ($index = 1, $count = count($waypoints); $index < $count; ++$index) {
            $from = $waypoints[$index - 1];
            $to = $waypoints[$index];
            if ($from->column === $to->column && $from->row !== $to->row) {
                $intervals[] = [$from->column, min($from->row, $to->row), max($from->row, $to->row)];
            }
        }

        return $intervals;
    }

    /**
     * @param list<Node> $nodes
     * @param list<Edge> $edges
     */
    private function buildPositionedGraph(
        array $nodes,
        array $edges = [],
        int $verticalSpacing = 2,
    ): LayoutGraph {
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
        (new BrandesKopfPositioning(verticalSpacing: $verticalSpacing))->position($layoutGraph);

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
        (new BrandesKopfPositioning())->position($layoutGraph);

        return $layoutGraph;
    }
}
