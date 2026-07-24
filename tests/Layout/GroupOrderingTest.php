<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Group;
use PhpDag\Graph\Node;
use PhpDag\Layout\GroupOrdering;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LongestPathLayering;
use PhpDag\Layout\MedianOrdering;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GroupOrderingTest extends TestCase
{
    #[Test]
    public function slotsUngroupedNodesAroundTheContiguousBlockByPosition(): void
    {
        // root fans out to g1, x, y, g2 in declaration order, so the layer
        // settles as [g1, x, y, g2]. The group's key is the average of its
        // members' positions (0.5), which lands the block between x (0.375) and
        // y (0.625) — proving the items are re-sorted by key, not left in
        // encounter order, and that the members stay one contiguous run.
        $layoutGraph = $this->layeredGraph(
            nodes: ['root', 'g1', 'x', 'y', 'g2'],
            edges: [['root', 'g1'], ['root', 'x'], ['root', 'y'], ['root', 'g2']],
            groups: [['grp', ['g1', 'g2']]],
        );

        (new MedianOrdering())->minimize($layoutGraph);
        (new GroupOrdering())->process($layoutGraph);

        self::assertSame(['x', 'g1', 'g2', 'y'], $layoutGraph->layerIndex()[1]);
    }

    #[Test]
    public function keepsTheGroupBlockAheadOfATiedUngroupedNodeWithASmallerId(): void
    {
        // The group's key (0.5) ties with the ungrouped node's position (0.5).
        // The ungrouped id 'a' sorts before the group id 'zgroup', so only the
        // groups-first rank keeps the block ahead of it.
        $layoutGraph = $this->layeredGraph(
            nodes: ['root', 'g1', 'a', 'g2'],
            edges: [['root', 'g1'], ['root', 'a'], ['root', 'g2']],
            groups: [['zgroup', ['g1', 'g2']]],
        );

        (new MedianOrdering())->minimize($layoutGraph);
        (new GroupOrdering())->process($layoutGraph);

        self::assertSame(['g1', 'g2', 'a'], $layoutGraph->layerIndex()[1]);
    }

    #[Test]
    public function breaksTiesBetweenGroupBlocksByGroupId(): void
    {
        // Two groups whose members mirror each other share the same key (0.5).
        // 'zzz' is declared first (so it would lead in encounter order), but the
        // id tiebreak must place 'aaa' first, proving ties resolve by id.
        $layoutGraph = $this->layeredGraph(
            nodes: ['root', 'a', 'b', 'c', 'd'],
            edges: [['root', 'a'], ['root', 'b'], ['root', 'c'], ['root', 'd']],
            groups: [['zzz', ['a', 'd']], ['aaa', ['b', 'c']]],
        );

        (new MedianOrdering())->minimize($layoutGraph);
        (new GroupOrdering())->process($layoutGraph);

        self::assertSame(['b', 'c', 'a', 'd'], $layoutGraph->layerIndex()[1]);
    }

    #[Test]
    public function pullsMembersContiguousEvenWhenSplitByAForeignNode(): void
    {
        // The non-member 'x' sits between the two members in the fan-out layer;
        // the block must close up around it.
        $layoutGraph = $this->layeredGraph(
            nodes: ['root', 'g1', 'x', 'g2'],
            edges: [['root', 'g1'], ['root', 'x'], ['root', 'g2']],
            groups: [['grp', ['g1', 'g2']]],
        );

        (new MedianOrdering())->minimize($layoutGraph);
        (new GroupOrdering())->process($layoutGraph);

        $order = $layoutGraph->layerIndex()[1];
        self::assertSame(1, abs(array_search('g1', $order, true) - array_search('g2', $order, true)));
    }

    #[Test]
    public function keepsTwoGroupsOnAConsistentSideAcrossLayers(): void
    {
        // A->P, A->Q (layer 1 = P, Q); P->R, Q->S (layer 2 = R, S). The averaged
        // keys must put each group's members on the same side in both layers, so
        // their column bands never cross.
        $layoutGraph = $this->layeredGraph(
            nodes: ['A', 'P', 'Q', 'R', 'S'],
            edges: [['A', 'P'], ['A', 'Q'], ['P', 'R'], ['Q', 'S']],
            groups: [['left', ['P', 'R']], ['right', ['Q', 'S']]],
        );

        (new MedianOrdering())->minimize($layoutGraph);
        (new GroupOrdering())->process($layoutGraph);

        $layer1 = $layoutGraph->layerIndex()[1];
        $layer2 = $layoutGraph->layerIndex()[2];

        self::assertSame(
            array_search('P', $layer1, true) < array_search('Q', $layer1, true),
            array_search('R', $layer2, true) < array_search('S', $layer2, true),
            'Group bands must not cross between layers',
        );
    }

    #[Test]
    public function averagesEveryMemberPositionWhenOrderingAGroupAgainstSiblings(): void
    {
        // The group spans a, d, e of the five fan-out children; its true average
        // key (0.1, 0.7, 0.9 -> ~0.567) sits just past the ungrouped c (0.5), so
        // both b and c lead the block. Summing only the last member, miscounting,
        // or dropping a position collapses the average below c and reorders the
        // layer — so the whole sum and count must be accumulated.
        $layoutGraph = $this->layeredGraph(
            nodes: ['root', 'a', 'b', 'c', 'd', 'e'],
            edges: [['root', 'a'], ['root', 'b'], ['root', 'c'], ['root', 'd'], ['root', 'e']],
            groups: [['grp', ['a', 'd', 'e']]],
        );

        (new MedianOrdering())->minimize($layoutGraph);
        (new GroupOrdering())->process($layoutGraph);

        self::assertSame(['b', 'c', 'a', 'd', 'e'], $layoutGraph->layerIndex()[1]);
    }

    /**
     * @param list<string>                      $nodes
     * @param list<array{string, string}>       $edges
     * @param list<array{string, list<string>}> $groups
     */
    private function layeredGraph(array $nodes, array $edges, array $groups): LayoutGraph
    {
        $graph = new Graph();
        foreach ($nodes as $nodeId) {
            $graph->addNode(new Node($nodeId, $nodeId));
        }
        foreach ($edges as [$source, $target]) {
            $graph->addEdge(new Edge($source, $target));
        }
        foreach ($groups as [$id, $members]) {
            $graph->addGroup(new Group($id, $id, $members));
        }

        $layoutGraph = LayoutGraph::fromGraph($graph);
        foreach ((new LongestPathLayering())->assign($layoutGraph) as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();

        return $layoutGraph;
    }
}
