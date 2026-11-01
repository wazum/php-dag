<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
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
    public function centresThePortsOfAnEvenWidthBoxOnTheLeftOfTwoMiddles(): void
    {
        $graph = $this->placedGraph(['a' => [0, 0, 8], 'b' => [1, 0, 5], 'c' => [1, 10, 5]]);
        $pair = [new LayoutEdge(new Edge('a', 'b')), new LayoutEdge(new Edge('a', 'b'))];
        foreach ([...$pair, new LayoutEdge(new Edge('a', 'c'))] as $edge) {
            $graph->addEdge($edge);
        }

        $ports = EdgePorts::of($graph, FlowDirection::TopToBottom);

        self::assertSame([1, 3], [$ports->exitPositions[spl_object_id($pair[0])], $ports->exitPositions[spl_object_id($pair[1])]]);
        self::assertSame(['a' => 5], $ports->trunkExitPositions);
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

    /**
     * @param array<string, array{int, int, int}> $nodes id => [layer, column, minimum box width]
     */
    private function placedGraph(array $nodes): LayoutGraph
    {
        $graph = new LayoutGraph();
        foreach ($nodes as $nodeId => [$layer, $column, $minBoxWidth]) {
            $node = new RealLayoutNode($nodeId, new Node($nodeId, $nodeId));
            $node->layer = $layer;
            $node->column = $column;
            $node->minBoxWidth = $minBoxWidth;
            $graph->addNode($node);
        }

        return $graph;
    }
}
