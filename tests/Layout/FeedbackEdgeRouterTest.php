<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\CycleBreaker;
use PhpDag\Layout\DummyNodeInserter;
use PhpDag\Layout\DummyNodeRemover;
use PhpDag\Layout\EdgePort;
use PhpDag\Layout\FeedbackEdgeRouter;
use PhpDag\Layout\FlowDirection;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LeftToRightPositioning;
use PhpDag\Layout\LeftToRightRouting;
use PhpDag\Layout\LongestPathLayering;
use PhpDag\Layout\RealLayoutNode;
use PhpDag\Render\Waypoint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FeedbackEdgeRouterTest extends TestCase
{
    #[Test]
    public function routesTopToBottomFeedbackEdgeThroughRightSidePorts(): void
    {
        $graph = $this->buildManualThreeNodeCycle();

        (new FeedbackEdgeRouter(FlowDirection::TopToBottom))->process($graph);

        $edge = $this->reversedEdge($graph);

        self::assertSame('C', $edge->visualSourceId());
        self::assertSame('A', $edge->visualTargetId());
        self::assertSame(EdgePort::East, $edge->sourcePort);
        self::assertSame(EdgePort::East, $edge->targetPort);
        self::assertEquals([
            new Waypoint(11, 11),
            new Waypoint(11, 14),
            new Waypoint(1, 14),
            new Waypoint(1, 12),
        ], $edge->waypoints);
    }

    #[Test]
    public function routesLeftToRightFeedbackEdgeThroughBottomPorts(): void
    {
        $graph = $this->buildPositionedLeftToRightAdjacentCycle();

        (new FeedbackEdgeRouter(FlowDirection::LeftToRight))->process($graph);

        $edge = $this->reversedEdge($graph);

        self::assertSame('C', $edge->visualSourceId());
        self::assertSame('B', $edge->visualTargetId());
        self::assertSame(EdgePort::South, $edge->sourcePort);
        self::assertSame(EdgePort::South, $edge->targetPort);
        self::assertGreaterThan($graph->getLayoutNode('C')->row + $graph->getLayoutNode('C')->boxHeight() - 1, $edge->waypoints[1]->row);
        self::assertSame($edge->waypoints[1]->row, $edge->waypoints[2]->row);
        self::assertLessThan($edge->waypoints[0]->column, $edge->waypoints[2]->column);
    }

    #[Test]
    public function assignsSeparateLanesToMultipleTopToBottomFeedbackEdges(): void
    {
        $graph = $this->buildManualThreeNodeCycle();
        $graph->addEdge(new LayoutEdge(new Edge('B', 'A'), reversed: true));

        (new FeedbackEdgeRouter(FlowDirection::TopToBottom))->process($graph);

        $maxRightColumn = $this->maxRightColumn($graph);
        $reversedEdges = $this->reversedEdges($graph);

        self::assertCount(2, $reversedEdges);
        self::assertSame($maxRightColumn + 3, $reversedEdges[0]->waypoints[1]->column, 'First feedback edge must use the innermost lane');
        self::assertSame($maxRightColumn + 5, $reversedEdges[1]->waypoints[1]->column, 'Second feedback edge must use the next lane, two columns further out');
    }

    #[Test]
    public function assignsSeparateLanesToMultipleLeftToRightFeedbackEdges(): void
    {
        $graph = $this->buildManualThreeNodeCycle();
        $graph->addEdge(new LayoutEdge(new Edge('B', 'A'), reversed: true));

        (new FeedbackEdgeRouter(FlowDirection::LeftToRight))->process($graph);

        $maxBottomRow = $this->maxBottomRow($graph);
        $reversedEdges = $this->reversedEdges($graph);

        self::assertCount(2, $reversedEdges);
        $firstSource = $graph->getLayoutNode('C');
        self::assertSame(
            $firstSource->row + $firstSource->boxHeight(),
            $reversedEdges[0]->waypoints[0]->row,
            'Feedback edge must exit at the bottom border of its source box',
        );
        self::assertSame($maxBottomRow + 2, $reversedEdges[0]->waypoints[1]->row, 'First feedback edge must use the innermost lane');
        self::assertSame($maxBottomRow + 4, $reversedEdges[1]->waypoints[1]->row, 'Second feedback edge must use the next lane, two rows further down');
    }

    #[Test]
    public function centersTopToBottomFeedbackPortsOnTallBoxes(): void
    {
        $graph = new LayoutGraph();

        $start = new RealLayoutNode('A', new Node('A', 'Start', body: ['first', 'second']));
        $start->row = 0;
        $start->column = 0;
        $graph->addNode($start);

        $end = new RealLayoutNode('B', new Node('B', 'End', body: ['first', 'second']));
        $end->row = 10;
        $end->column = 0;
        $graph->addNode($end);

        $graph->addEdge(new LayoutEdge(new Edge('A', 'B')));
        $graph->addEdge(new LayoutEdge(new Edge('B', 'A'), reversed: true));

        (new FeedbackEdgeRouter(FlowDirection::TopToBottom))->process($graph);

        $edge = $this->reversedEdge($graph);
        $sourceCenterRow = $end->row + intdiv($end->boxHeight(), 2);
        $targetCenterRow = $start->row + intdiv($start->boxHeight(), 2);

        self::assertSame(5, $end->boxHeight(), 'Tall box is required so the vertical center is parity-sensitive');
        self::assertSame($sourceCenterRow, $edge->waypoints[0]->row);
        self::assertSame($sourceCenterRow, $edge->waypoints[1]->row);
        self::assertSame($targetCenterRow, $edge->waypoints[2]->row);
        self::assertSame($targetCenterRow, $edge->waypoints[3]->row);
    }

    #[Test]
    public function dropsDuplicateConsecutiveWaypointsWhenSourceAndTargetShareCenterRow(): void
    {
        $graph = new LayoutGraph();

        $left = new RealLayoutNode('A', new Node('A', 'Left'));
        $left->row = 0;
        $left->column = 0;
        $graph->addNode($left);

        $right = new RealLayoutNode('B', new Node('B', 'Right'));
        $right->row = 0;
        $right->column = 20;
        $graph->addNode($right);

        $graph->addEdge(new LayoutEdge(new Edge('A', 'B')));
        $graph->addEdge(new LayoutEdge(new Edge('B', 'A'), reversed: true));

        (new FeedbackEdgeRouter(FlowDirection::TopToBottom))->process($graph);

        $edge = $this->reversedEdge($graph);

        self::assertCount(3, $edge->waypoints, 'The two coinciding lane waypoints must collapse into one');
    }

    private function buildManualThreeNodeCycle(): LayoutGraph
    {
        $graph = new LayoutGraph();

        $start = new RealLayoutNode('A', new Node('A', 'Start'));
        $start->row = 0;
        $start->column = 3;
        $graph->addNode($start);

        $process = new RealLayoutNode('B', new Node('B', 'Process'));
        $process->row = 5;
        $process->column = 0;
        $graph->addNode($process);

        $end = new RealLayoutNode('C', new Node('C', 'End'));
        $end->row = 10;
        $end->column = 4;
        $graph->addNode($end);

        $graph->addEdge(new LayoutEdge(new Edge('A', 'B')));
        $graph->addEdge(new LayoutEdge(new Edge('B', 'C')));
        $graph->addEdge(new LayoutEdge(new Edge('C', 'A'), reversed: true));

        return $graph;
    }

    private function buildPositionedLeftToRightAdjacentCycle(): LayoutGraph
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Init'));
        $graph->addNode(new Node('B', 'Loop'));
        $graph->addNode(new Node('C', 'Done'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('C', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new CycleBreaker())->process($layoutGraph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new LeftToRightPositioning())->position($layoutGraph);
        (new LeftToRightRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        return $layoutGraph;
    }

    /** @return list<LayoutEdge> */
    private function reversedEdges(LayoutGraph $graph): array
    {
        $reversedEdges = [];
        foreach ($graph->edges() as $edge) {
            if ($edge->reversed) {
                $reversedEdges[] = $edge;
            }
        }

        return $reversedEdges;
    }

    private function maxRightColumn(LayoutGraph $graph): int
    {
        $maxRightColumn = 0;
        foreach ($graph->nodeIds() as $nodeId) {
            $node = $graph->getLayoutNode($nodeId);
            $maxRightColumn = max($maxRightColumn, $node->column + $node->boxWidth() - 1);
        }

        return $maxRightColumn;
    }

    private function maxBottomRow(LayoutGraph $graph): int
    {
        $maxBottomRow = 0;
        foreach ($graph->nodeIds() as $nodeId) {
            $node = $graph->getLayoutNode($nodeId);
            $maxBottomRow = max($maxBottomRow, $node->row + $node->boxHeight() - 1);
        }

        return $maxBottomRow;
    }

    private function reversedEdge(LayoutGraph $graph): LayoutEdge
    {
        foreach ($graph->edges() as $edge) {
            if ($edge->reversed) {
                return $edge;
            }
        }

        self::fail('Expected one reversed edge');
    }
}
