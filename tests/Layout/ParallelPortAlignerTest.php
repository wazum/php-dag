<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Group;
use PhpDag\Graph\Node;
use PhpDag\Layout\DummyLayoutNode;
use PhpDag\Layout\FlowDirection;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\ParallelPortAligner;
use PhpDag\Layout\RealLayoutNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParallelPortAlignerTest extends TestCase
{
    /**
     * Box a sends a pair to b and a single edge to c. With a one cell off,
     * a's pair ports sit one cell away from b's.
     *
     * @param array<string, int> $extraNodes     id => start of a box beside a in layer 0
     * @param list<string>       $groupedIds
     * @param array<string, int> $expectedStarts
     */
    #[Test]
    #[DataProvider('layouts')]
    public function shiftsOneEndOfAParallelGroupSoItsLanesRunStraight(FlowDirection $flowDirection, array $extraNodes, int $cStart, array $groupedIds, array $expectedStarts): void
    {
        $alongRows = FlowDirection::LeftToRight === $flowDirection;
        $nodes = array_filter(
            ['z' => [0, $extraNodes['z'] ?? null, 5], 'a' => [0, 1, 7], 'b' => [1, 0, 5], 'c' => [1, $cStart, 5]],
            static fn (array $node): bool => null !== $node[1],
        );
        $domainGraph = new Graph();
        foreach (array_keys($nodes) as $nodeId) {
            $domainGraph->addNode(new Node($nodeId, $nodeId));
        }
        foreach ([['a', 'b'], ['a', 'b'], ['a', 'c']] as [$sourceId, $targetId]) {
            $domainGraph->addEdge(new Edge($sourceId, $targetId));
        }
        if ([] !== $groupedIds) {
            $domainGraph->addGroup(new Group('fixed', 'fixed', $groupedIds));
        }
        $graph = LayoutGraph::fromGraph($domainGraph);
        foreach ($nodes as $nodeId => [$layer, $start, $extent]) {
            $node = $graph->getLayoutNode($nodeId);
            self::assertInstanceOf(RealLayoutNode::class, $node);
            $node->layer = $layer;
            if ($alongRows) {
                $node->column = 10 * $layer;
                $node->row = $start;
                $node->minBoxHeight = $extent;
            } else {
                $node->row = 6 * $layer;
                $node->column = $start;
                $node->minBoxWidth = $extent;
            }
        }
        $graph->buildLayerIndex();

        (new ParallelPortAligner($flowDirection, 2))->process($graph);

        foreach ($expectedStarts as $nodeId => $start) {
            $node = $graph->getLayoutNode($nodeId);
            self::assertSame($start, $alongRows ? $node->row : $node->column, $nodeId);
        }
    }

    #[Test]
    public function usesTheOffsetsAfterEarlierMovesForLaterGroups(): void
    {
        $domainGraph = new Graph();
        foreach (['a', 'b', 'c'] as $nodeId) {
            $domainGraph->addNode(new Node($nodeId, $nodeId));
        }
        foreach (['b', 'b', 'c', 'c'] as $targetId) {
            $domainGraph->addEdge(new Edge('a', $targetId));
        }
        $graph = LayoutGraph::fromGraph($domainGraph);
        foreach (['a' => [0, 1, 13], 'b' => [1, 0, 5], 'c' => [1, 30, 5]] as $nodeId => [$layer, $column, $width]) {
            $node = $graph->getLayoutNode($nodeId);
            self::assertInstanceOf(RealLayoutNode::class, $node);
            $node->layer = $layer;
            $node->row = 6 * $layer;
            $node->column = $column;
            $node->minBoxWidth = $width;
        }
        $graph->buildLayerIndex();

        (new ParallelPortAligner(FlowDirection::TopToBottom, 2))->process($graph);

        self::assertSame(0, $graph->getLayoutNode('a')->column, 'a moves one column left to straighten the pair to b');
        self::assertSame(8, $graph->getLayoutNode('c')->column, 'after that move, c needs 22 columns left instead of 21 to straighten its pair');
    }

    /**
     * @param list<string>       $groupedIds
     * @param array<string, int> $expectedColumns
     */
    #[Test]
    #[DataProvider('chainedGroups')]
    public function alignsLaterGroupsWithTheOffsetsAfterEarlierMoves(array $groupedIds, array $expectedColumns): void
    {
        $domainGraph = new Graph();
        foreach (['a', 'b', 'c', 'd', 'e'] as $nodeId) {
            $domainGraph->addNode(new Node($nodeId, $nodeId));
        }
        foreach ([['a', 'b'], ['a', 'b'], ['a', 'c'], ['b', 'd'], ['b', 'd'], ['b', 'e']] as [$sourceId, $targetId]) {
            $domainGraph->addEdge(new Edge($sourceId, $targetId));
        }
        $domainGraph->addGroup(new Group('fixed', 'fixed', $groupedIds));
        $graph = LayoutGraph::fromGraph($domainGraph);
        foreach (['a' => [0, 0, 7], 'b' => [1, 1, 7], 'c' => [1, 10, 5], 'd' => [2, 0, 5], 'e' => [2, 20, 5]] as $nodeId => [$layer, $column, $width]) {
            $node = $graph->getLayoutNode($nodeId);
            self::assertInstanceOf(RealLayoutNode::class, $node);
            $node->layer = $layer;
            $node->row = 6 * $layer;
            $node->column = $column;
            $node->minBoxWidth = $width;
        }
        $graph->buildLayerIndex();

        (new ParallelPortAligner(FlowDirection::TopToBottom, 2))->process($graph);

        foreach ($expectedColumns as $nodeId => $column) {
            self::assertSame($column, $graph->getLayoutNode($nodeId)->column, $nodeId);
        }
    }

    #[Test]
    public function undoesAMoveThatWouldRunALongEdgeLaneOntoABoxAndMovesTheOtherEndInstead(): void
    {
        $graph = new LayoutGraph();
        $boxes = ['a' => [0, 4, 7], 'b' => [1, 10, 5], 'c' => [2, 0, 5]];
        foreach ($boxes as $nodeId => [$layer, $column, $width]) {
            $node = new RealLayoutNode($nodeId, new Node($nodeId, $nodeId));
            $node->layer = $layer;
            $node->row = 6 * $layer;
            $node->column = $column;
            $node->minBoxWidth = $width;
            $graph->addNode($node);
        }
        foreach (['first' => 0, 'second' => 2] as $dummyId => $column) {
            $dummy = new DummyLayoutNode($dummyId, 'a', 'c');
            $dummy->layer = 1;
            $dummy->row = 6;
            $dummy->column = $column;
            $graph->addNode($dummy);
            $graph->addEdge(new LayoutEdge(new Edge('a', $dummyId)));
            $graph->addEdge(new LayoutEdge(new Edge($dummyId, 'c')));
        }
        $graph->addEdge(new LayoutEdge(new Edge('a', 'b')));
        $graph->buildLayerIndex();
        $graph->setLayerOrder(1, ['first', 'second', 'b']);

        (new ParallelPortAligner(FlowDirection::TopToBottom, 2))->process($graph);

        self::assertSame(4, $graph->getLayoutNode('a')->column, 'moving a three columns right would run its second lane down column 10, the first column of b');
        self::assertSame(7, $graph->getLayoutNode('b')->column, 'b moves three columns left instead, under the trunk port of a');
    }

    /** @return iterable<string, array{list<string>, array<string, int>}> */
    public static function chainedGroups(): iterable
    {
        yield 'b moves for the pair from a, which already straightens the pair to d' => [['a'], ['b' => 0, 'd' => 0]];
        yield 'a and b stay, so d moves for the pair from b' => [['a', 'b'], ['b' => 1, 'd' => 1]];
    }

    /** @return iterable<string, array{FlowDirection, array<string, int>, int, list<string>, array<string, int>}> */
    public static function layouts(): iterable
    {
        yield 'the source moves' => [FlowDirection::TopToBottom, [], 9, [], ['a' => 0, 'b' => 0]];
        yield 'the source moves up to exactly the spacing' => [FlowDirection::TopToBottom, ['z' => -7], 9, [], ['a' => 0, 'b' => 0]];
        yield 'a close neighbour moves the target instead' => [FlowDirection::TopToBottom, ['z' => -6], 9, [], ['a' => 1, 'b' => 1]];
        yield 'the target moves up to exactly the spacing' => [FlowDirection::TopToBottom, ['z' => -6], 8, [], ['a' => 1, 'b' => 1]];
        yield 'both ends blocked' => [FlowDirection::TopToBottom, ['z' => -6], 7, [], ['a' => 1, 'b' => 0]];
        yield 'a group member stays put' => [FlowDirection::TopToBottom, [], 9, ['a'], ['a' => 1, 'b' => 1]];
        yield 'the free end moves when only the target is fixed' => [FlowDirection::TopToBottom, [], 9, ['b'], ['a' => 0, 'b' => 0]];
        yield 'left-to-right moves along rows' => [FlowDirection::LeftToRight, [], 9, [], ['a' => 0, 'b' => 0]];
    }
}
