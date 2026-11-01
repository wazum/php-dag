<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Label;
use PhpDag\Graph\Node;
use PhpDag\Layout\EdgePorts;
use PhpDag\Layout\FlowDirection;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\RealLayoutNode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EdgePortsTest extends TestCase
{
    #[Test]
    public function ordersExitPortsByWhereTheTargetsSitNotByEdgeOrder(): void
    {
        $graph = $this->placedGraph(['a' => [0, 2, 9], 'b' => [1, 0, 5], 'c' => [1, 10, 5]]);
        $edges = [];
        foreach (['c', 'c', 'b', 'b'] as $targetId) {
            $edges[] = $edge = new LayoutEdge(new Edge('a', $targetId));
            $graph->addEdge($edge);
        }

        $ports = EdgePorts::of($graph, FlowDirection::TopToBottom);

        self::assertSame([7, 9, 3, 5], array_map(static fn (LayoutEdge $edge): int => $ports->exitPositions[spl_object_id($edge)], $edges));
    }

    #[Test]
    public function placesPortsAcrossFromTheirFarEndsButInsideTheBox(): void
    {
        $graph = $this->placedGraph(['a' => [0, 0, 8], 'b' => [1, 0, 5], 'c' => [1, 10, 5]]);
        $pair = [new LayoutEdge(new Edge('a', 'b')), new LayoutEdge(new Edge('a', 'b'))];
        foreach ([...$pair, new LayoutEdge(new Edge('a', 'c'))] as $edge) {
            $graph->addEdge($edge);
        }

        $ports = EdgePorts::of($graph, FlowDirection::TopToBottom);

        self::assertSame([1, 3], [$ports->exitPositions[spl_object_id($pair[0])], $ports->exitPositions[spl_object_id($pair[1])]], 'the pair centres on b at column 2');
        self::assertSame(['a' => 6], $ports->trunkExitPositions, 'c is centred at column 12, but the last inner column of a is 6');
    }

    #[Test]
    public function liftsAPairWhoseBendsTouchTheTrunkBarOneLaneHigher(): void
    {
        $graph = $this->placedGraph(['a' => [0, 10, 7], 'c' => [1, 11, 5], 'b' => [1, 16, 5]]);
        $pair = [new LayoutEdge(new Edge('a', 'b')), new LayoutEdge(new Edge('a', 'b'))];
        foreach ([new LayoutEdge(new Edge('a', 'c')), ...$pair] as $edge) {
            $graph->addEdge($edge);
        }

        $ports = EdgePorts::of($graph, FlowDirection::TopToBottom);

        self::assertSame([13, 15], [$ports->exitPositions[spl_object_id($pair[0])], $ports->exitPositions[spl_object_id($pair[1])]]);
        self::assertSame([1, 2], [$ports->bendDepths[spl_object_id($pair[0])], $ports->bendDepths[spl_object_id($pair[1])]], 'the trunk bar reaches the centre of c at column 13, where the pair starts bending');
        self::assertSame([0 => 2], $ports->extraLanesByGap);
    }

    #[Test]
    public function nestsALeftGoingPairAndLiftsItWhenItsBendsReachTheTrunkBar(): void
    {
        $graph = $this->placedGraph(['a' => [0, 10, 7], 'b' => [1, 0, 5], 'c' => [1, 11, 5]]);
        $pair = [new LayoutEdge(new Edge('a', 'b')), new LayoutEdge(new Edge('a', 'b'))];
        foreach ([...$pair, new LayoutEdge(new Edge('a', 'c'))] as $edge) {
            $graph->addEdge($edge);
        }

        $ports = EdgePorts::of($graph, FlowDirection::TopToBottom);

        self::assertSame([11, 13], [$ports->exitPositions[spl_object_id($pair[0])], $ports->exitPositions[spl_object_id($pair[1])]]);
        self::assertSame([2, 1], [$ports->bendDepths[spl_object_id($pair[0])], $ports->bendDepths[spl_object_id($pair[1])]], 'the outer lane bends highest; the trunk bar starts at column 13, where the inner lane leaves');
        self::assertSame([0 => 2], $ports->extraLanesByGap, 'three bend rows plus the arrow row need four rows; the gap has two');
    }

    #[Test]
    public function pullsSlotsBackInsideTheBoxSide(): void
    {
        $graph = $this->placedGraph(['a' => [0, 0, 7], 'b' => [1, 18, 5], 'c' => [1, 20, 5]]);
        $pair = [new LayoutEdge(new Edge('a', 'b')), new LayoutEdge(new Edge('a', 'b'))];
        foreach ([...$pair, new LayoutEdge(new Edge('a', 'c'))] as $edge) {
            $graph->addEdge($edge);
        }

        $ports = EdgePorts::of($graph, FlowDirection::TopToBottom);

        self::assertSame([1, 3], [$ports->exitPositions[spl_object_id($pair[0])], $ports->exitPositions[spl_object_id($pair[1])]]);
        self::assertSame(['a' => 5], $ports->trunkExitPositions);
    }

    #[Test]
    public function centresASlotOnItsFarEnd(): void
    {
        $graph = $this->placedGraph(['a' => [0, 0, 11], 'b' => [1, 3, 5], 'c' => [1, 18, 5]]);
        $pair = [new LayoutEdge(new Edge('a', 'b')), new LayoutEdge(new Edge('a', 'b'))];
        foreach ([...$pair, new LayoutEdge(new Edge('a', 'c'))] as $edge) {
            $graph->addEdge($edge);
        }

        $ports = EdgePorts::of($graph, FlowDirection::TopToBottom);

        self::assertSame([4, 6], [$ports->exitPositions[spl_object_id($pair[0])], $ports->exitPositions[spl_object_id($pair[1])]], 'b is centred at column 5');
    }

    #[Test]
    public function reportsAGroupAsMisalignedOnlyWhenOneShiftStraightensEveryLane(): void
    {
        $graph = $this->placedGraph(['a' => [0, 0, 27], 'b' => [1, 2, 7], 'c' => [1, 20, 11]]);
        foreach ([['b', 'a'], ['b', 'a'], ['c', 'abcde'], ['c', 'abcde']] as [$targetId, $labelText]) {
            $graph->addEdge(new LayoutEdge(new Edge('a', $targetId, label: new Label($labelText))));
        }

        $ports = EdgePorts::of($graph, FlowDirection::TopToBottom);

        self::assertNotContains('b', array_map(static fn (array $group): string => $group[1]->id, $ports->misalignedGroups), 'a spaces its ports for the wider label, b does not, so the pair\'s lanes are off by 2 and -2');
    }

    #[Test]
    public function bendsTheOtherLanesOfAGroupWhoseFirstLaneRunsStraight(): void
    {
        $graph = $this->placedGraph(['a' => [0, 0, 27], 'b' => [1, 0, 7], 'c' => [1, 20, 11]]);
        $pair = [new LayoutEdge(new Edge('a', 'b', label: new Label('a'))), new LayoutEdge(new Edge('a', 'b', label: new Label('a')))];
        foreach ([...$pair, new LayoutEdge(new Edge('a', 'c', label: new Label('abcde'))), new LayoutEdge(new Edge('a', 'c', label: new Label('abcde')))] as $edge) {
            $graph->addEdge($edge);
        }

        $ports = EdgePorts::of($graph, FlowDirection::TopToBottom);

        self::assertSame([1, 9], [$ports->exitPositions[spl_object_id($pair[0])], $ports->exitPositions[spl_object_id($pair[1])]]);
        self::assertSame([1, 5], [$ports->entryPositions[spl_object_id($pair[0])], $ports->entryPositions[spl_object_id($pair[1])]]);
        self::assertSame(0, $ports->bendDepths[spl_object_id($pair[1])] ?? null, 'the first lane runs straight, the second still bends');
    }

    #[Test]
    public function reservesNoLanesWhenTheGapIsAlreadyDeepEnough(): void
    {
        $graph = $this->placedGraph(['a' => [0, 10, 7], 'b' => [1, 0, 5], 'c' => [1, 20, 5]]);
        $graph->getLayoutNode('b')->row = $graph->getLayoutNode('c')->row = 20;
        foreach ([['a', 'b'], ['a', 'b'], ['a', 'c']] as [$sourceId, $targetId]) {
            $graph->addEdge(new LayoutEdge(new Edge($sourceId, $targetId)));
        }

        self::assertSame([0 => 0], EdgePorts::of($graph, FlowDirection::TopToBottom)->extraLanesByGap);
    }

    /**
     * @param array<string, array{int, int, int}> $nodes id => [layer, column, minimum box width]
     */
    private function placedGraph(array $nodes): LayoutGraph
    {
        $graph = new LayoutGraph();
        foreach ($nodes as $nodeId => [$layer, $column, $minBoxWidth]) {
            $node = new RealLayoutNode($nodeId, new Node($nodeId, $nodeId));
            $node->layer = $layer;
            $node->row = 6 * $layer;
            $node->column = $column;
            $node->minBoxWidth = $minBoxWidth;
            $graph->addNode($node);
        }

        return $graph;
    }
}
