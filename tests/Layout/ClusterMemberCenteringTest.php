<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Graph;
use PhpDag\Graph\Group;
use PhpDag\Graph\Node;
use PhpDag\Layout\ClusterMemberCentering;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\Processor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClusterMemberCenteringTest extends TestCase
{
    #[Test]
    public function implementsProcessor(): void
    {
        self::assertInstanceOf(Processor::class, new ClusterMemberCentering());
    }

    #[Test]
    public function slidesAnOffsetMemberLayerToTheBandCentre(): void
    {
        // ml and mr span the band (columns 0..34, centre 17) on the top layer;
        // mm sits off to the left on the next layer. Centring must slide mm so
        // its centre lands on the band centre (column 0 -> 15, a 5-wide box),
        // while the already-centred top layer stays put.
        $graph = new Graph();
        foreach (['ml', 'mr', 'mm'] as $id) {
            $graph->addNode(new Node($id, 'N'));
        }
        $graph->addGroup(new Group('cluster', 'Cluster', ['ml', 'mr', 'mm']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'ml', layer: 1, column: 0);
        $this->place($layoutGraph, 'mr', layer: 1, column: 30);
        $this->place($layoutGraph, 'mm', layer: 2, column: 0);

        (new ClusterMemberCentering())->process($layoutGraph);

        self::assertSame(15, $layoutGraph->getLayoutNode('mm')->column);
        self::assertSame(0, $layoutGraph->getLayoutNode('ml')->column);
        self::assertSame(30, $layoutGraph->getLayoutNode('mr')->column);
    }

    #[Test]
    public function keepsTheRelativeSpacingOfMembersSharingALayer(): void
    {
        // Two members 4 columns apart on a narrow layer shift together to the
        // band centre, preserving their gap.
        $graph = new Graph();
        foreach (['ml', 'mr', 'ca', 'cb'] as $id) {
            $graph->addNode(new Node($id, 'N'));
        }
        $graph->addGroup(new Group('cluster', 'Cluster', ['ml', 'mr', 'ca', 'cb']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'ml', layer: 1, column: 0);
        $this->place($layoutGraph, 'mr', layer: 1, column: 40);
        // ca/cb occupy columns 0..4 and 9..13 -> block 0..13, centre 6.
        $this->place($layoutGraph, 'ca', layer: 2, column: 0);
        $this->place($layoutGraph, 'cb', layer: 2, column: 9);

        (new ClusterMemberCentering())->process($layoutGraph);

        // Band centre is (0+44)/2 = 22; the block centre 6 shifts by 16.
        self::assertSame(16, $layoutGraph->getLayoutNode('ca')->column);
        self::assertSame(25, $layoutGraph->getLayoutNode('cb')->column);
    }

    #[Test]
    public function leavesGraphsWithoutGroupsUntouched(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'A'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'a', layer: 0, column: 7);

        (new ClusterMemberCentering())->process($layoutGraph);

        self::assertSame(7, $layoutGraph->getLayoutNode('a')->column);
    }

    private function place(LayoutGraph $layoutGraph, string $nodeId, int $layer, int $column): void
    {
        $node = $layoutGraph->getLayoutNode($nodeId);
        $node->layer = $layer;
        $node->column = $column;
    }
}
