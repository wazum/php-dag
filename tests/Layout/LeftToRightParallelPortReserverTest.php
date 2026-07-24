<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LeftToRightParallelPortReserver;
use PhpDag\Layout\Processor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LeftToRightParallelPortReserverTest extends TestCase
{
    #[Test]
    public function implementsProcessor(): void
    {
        self::assertInstanceOf(Processor::class, new LeftToRightParallelPortReserver());
    }

    #[Test]
    public function growsBothEndpointsOfAParallelPairToHostOnePortRowPerEdge(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new LeftToRightParallelPortReserver())->process($layoutGraph);

        // Two parallel edges need two port rows with a one-row gap inside the
        // box: interior 2*2-1 = 3 rows plus two borders = height 5.
        self::assertSame(5, $layoutGraph->getLayoutNode('A')->boxHeight());
        self::assertSame(5, $layoutGraph->getLayoutNode('B')->boxHeight());
    }

    #[Test]
    public function growsToTheLargerOfTwoParallelGroupsSharingANode(): void
    {
        // B sits in two parallel groups: B→C (×3, added first) and A→B (×2,
        // added last). Its height must follow the larger group (3 → height 7),
        // proving the per-node count keeps the maximum rather than the last seen.
        $graph = new Graph();
        foreach (['A', 'B', 'C'] as $id) {
            $graph->addNode(new Node($id, $id));
        }
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new LeftToRightParallelPortReserver())->process($layoutGraph);

        self::assertSame(7, $layoutGraph->getLayoutNode('B')->boxHeight());
    }

    #[Test]
    public function growsAParallelPairThatFollowsASingleEdge(): void
    {
        // The single A→B edge is encountered before the parallel C→D pair; the
        // pair must still grow (the count<2 case must skip, not stop).
        $graph = new Graph();
        foreach (['A', 'B', 'C', 'D'] as $id) {
            $graph->addNode(new Node($id, $id));
        }
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('C', 'D'));
        $graph->addEdge(new Edge('C', 'D'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new LeftToRightParallelPortReserver())->process($layoutGraph);

        self::assertSame(5, $layoutGraph->getLayoutNode('C')->boxHeight());
        self::assertSame(5, $layoutGraph->getLayoutNode('D')->boxHeight());
    }

    #[Test]
    public function growsAParallelPairEvenWhenAReversedEdgeComesFirst(): void
    {
        // A reversed (feedback) edge added before the parallel pair must be
        // skipped, not stop the scan — the pair still grows.
        $graph = new Graph();
        foreach (['A', 'B', 'X', 'Y'] as $id) {
            $graph->addNode(new Node($id, $id));
        }
        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->addEdge(new LayoutEdge(new Edge('X', 'Y'), reversed: true));
        $layoutGraph->addEdge(new LayoutEdge(new Edge('A', 'B')));
        $layoutGraph->addEdge(new LayoutEdge(new Edge('A', 'B')));

        (new LeftToRightParallelPortReserver())->process($layoutGraph);

        self::assertSame(5, $layoutGraph->getLayoutNode('A')->boxHeight());
        self::assertSame(5, $layoutGraph->getLayoutNode('B')->boxHeight());
    }

    #[Test]
    public function leavesNodesWithoutParallelEdgesAtTheirNaturalHeight(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $naturalHeight = $layoutGraph->getLayoutNode('A')->boxHeight();
        (new LeftToRightParallelPortReserver())->process($layoutGraph);

        self::assertSame($naturalHeight, $layoutGraph->getLayoutNode('A')->boxHeight());
        self::assertNull($layoutGraph->getLayoutNode('A')->minBoxHeight);
    }
}
