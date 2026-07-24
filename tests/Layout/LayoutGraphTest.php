<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use InvalidArgumentException;
use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Group;
use PhpDag\Graph\Node;
use PhpDag\Layout\DummyLayoutNode;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\RealLayoutNode;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LayoutGraphTest extends TestCase
{
    #[Test]
    public function fromGraphCreatesLayoutNodesForAllNodes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));

        $layoutGraph = LayoutGraph::fromGraph($graph);

        self::assertSame(2, $layoutGraph->nodeCount());
        self::assertInstanceOf(RealLayoutNode::class, $layoutGraph->getLayoutNode('A'));
        self::assertSame('A', $layoutGraph->getLayoutNode('A')->id);
        self::assertSame('B', $layoutGraph->getLayoutNode('B')->id);
    }

    #[Test]
    public function fromGraphCarriesSelfLoopsSeparatelyFromEdges(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addEdge(new Edge('A', 'A'));

        $layoutGraph = LayoutGraph::fromGraph($graph);

        self::assertSame([], $layoutGraph->edges());
        $selfLoops = $layoutGraph->selfLoops();
        self::assertCount(1, $selfLoops);
        self::assertSame('A', $selfLoops[0]->edge->sourceId);
    }

    #[Test]
    public function getLayoutNodeThrowsForUnknownId(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $layoutGraph = LayoutGraph::fromGraph($graph);

        $this->expectException(InvalidArgumentException::class);
        $layoutGraph->getLayoutNode('Z');
    }

    #[Test]
    public function returnsSuccessorsAndPredecessors(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));

        $layoutGraph = LayoutGraph::fromGraph($graph);

        self::assertSame(['B', 'C'], $layoutGraph->successors('A'));
        self::assertSame([], $layoutGraph->successors('B'));
        self::assertSame(['A'], $layoutGraph->predecessors('B'));
        self::assertSame([], $layoutGraph->predecessors('A'));
    }

    #[Test]
    public function fromGraphCarriesGroups(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'Alpha'));
        $graph->addNode(new Node('b', 'Beta'));
        $graph->addGroup(new Group('g', 'Group', ['a', 'b']));

        $layoutGraph = LayoutGraph::fromGraph($graph);

        self::assertCount(1, $layoutGraph->groups());
        self::assertSame('g', $layoutGraph->groups()[0]->id);
        self::assertSame(['a', 'b'], $layoutGraph->groups()[0]->nodeIds);
    }

    #[Test]
    public function graphWithoutGroupsHasEmptyLayoutGroups(): void
    {
        self::assertSame([], LayoutGraph::fromGraph(new Graph())->groups());
    }

    #[Test]
    public function removingTheOnlyOutgoingEdgeMakesSourceALeaf(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addEdge(new Edge('A', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->replaceEdges([$layoutGraph->outgoingEdges('A')[0]], []);

        self::assertSame(['A', 'B'], $layoutGraph->leaves());
    }

    #[Test]
    public function removingTheOnlyIncomingEdgeMakesTargetARoot(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addEdge(new Edge('A', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->replaceEdges([$layoutGraph->incomingEdges('B')[0]], []);

        self::assertSame(['A', 'B'], $layoutGraph->roots());
    }

    #[Test]
    public function returnsRootsAndLeaves(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));

        $layoutGraph = LayoutGraph::fromGraph($graph);

        self::assertSame(['A'], $layoutGraph->roots());
        self::assertSame(['C'], $layoutGraph->leaves());
    }

    #[Test]
    public function returnsMultipleRootsAndLeaves(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addNode(new Node('D', 'Delta'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('B', 'D'));

        $layoutGraph = LayoutGraph::fromGraph($graph);

        self::assertSame(['A', 'B'], $layoutGraph->roots());
        self::assertSame(['C', 'D'], $layoutGraph->leaves());
    }

    #[Test]
    public function returnsIncomingAndOutgoingEdges(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addEdge(new Edge('A', 'B', minLength: 3));

        $layoutGraph = LayoutGraph::fromGraph($graph);

        $outgoingEdges = $layoutGraph->outgoingEdges('A');
        self::assertCount(1, $outgoingEdges);
        self::assertSame('A', $outgoingEdges[0]->sourceId());
        self::assertSame('B', $outgoingEdges[0]->targetId());
        self::assertSame(3, $outgoingEdges[0]->minLength());

        $incomingEdges = $layoutGraph->incomingEdges('B');
        self::assertCount(1, $incomingEdges);
        self::assertSame($outgoingEdges[0], $incomingEdges[0]);

        self::assertSame([], $layoutGraph->incomingEdges('A'));
        self::assertSame([], $layoutGraph->outgoingEdges('B'));
    }

    #[Test]
    public function descriptiveEdgeAccessorsReturnIncomingAndOutgoingLayoutEdges(): void
    {
        $layoutGraph = LayoutGraph::fromGraph(Graph::fromEdges(
            nodes: ['A' => 'A', 'B' => 'B'],
            edges: [['A', 'B']],
        ));

        self::assertSame($layoutGraph->edges(), $layoutGraph->outgoingEdges('A'));
        self::assertSame($layoutGraph->edges(), $layoutGraph->incomingEdges('B'));
    }

    #[Test]
    public function returnsNodeIdsEdgesAndHasNode(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addEdge(new Edge('A', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);

        self::assertSame(['A', 'B'], $layoutGraph->nodeIds());
        self::assertCount(1, $layoutGraph->edges());
        self::assertTrue($layoutGraph->hasNode('A'));
        self::assertFalse($layoutGraph->hasNode('Z'));
    }

    #[Test]
    public function buildsLayerIndexFromNodeLayers(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->getLayoutNode('A')->layer = 0;
        $layoutGraph->getLayoutNode('B')->layer = 1;
        $layoutGraph->getLayoutNode('C')->layer = 1;

        $layoutGraph->buildLayerIndex();

        self::assertSame(2, $layoutGraph->layerCount());
        $index = $layoutGraph->layerIndex();
        self::assertSame(['A'], $index[0]);
        self::assertEqualsCanonicalizing(['B', 'C'], $index[1]);
    }

    #[Test]
    public function removeNodesRemovesNodeFromLayerIndexPreservingOrder(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->getLayoutNode('A')->layer = 0;
        $layoutGraph->getLayoutNode('B')->layer = 0;
        $layoutGraph->getLayoutNode('C')->layer = 0;
        $layoutGraph->buildLayerIndex();
        $layoutGraph->setLayerOrder(0, ['C', 'B', 'A']);

        $layoutGraph->removeNodes(['B']);

        self::assertSame([0 => ['C', 'A']], $layoutGraph->layerIndex());
    }

    #[Test]
    public function layerIndexIsSortedByLayerNumber(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->getLayoutNode('B')->layer = 0;
        $layoutGraph->getLayoutNode('A')->layer = 2;

        $layoutGraph->buildLayerIndex();

        $keys = array_keys($layoutGraph->layerIndex());
        self::assertSame([0, 2], $keys);
    }

    #[Test]
    public function addNodeMakesNodeRetrievable(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $layoutGraph = LayoutGraph::fromGraph($graph);

        $dummy = new DummyLayoutNode('__dummy_A_B_1', 'A', 'B');
        $layoutGraph->addNode($dummy);

        self::assertTrue($layoutGraph->hasNode('__dummy_A_B_1'));
        self::assertSame($dummy, $layoutGraph->getLayoutNode('__dummy_A_B_1'));
        self::assertSame(2, $layoutGraph->nodeCount());
    }

    #[Test]
    public function addEdgeUpdatesAllMaps(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $layoutGraph = LayoutGraph::fromGraph($graph);

        $edge = new LayoutEdge(edge: new Edge('A', 'B'));
        $layoutGraph->addEdge($edge);

        self::assertCount(1, $layoutGraph->edges());
        self::assertSame(['B'], $layoutGraph->successors('A'));
        self::assertSame(['A'], $layoutGraph->predecessors('B'));
        self::assertCount(1, $layoutGraph->outgoingEdges('A'));
        self::assertCount(1, $layoutGraph->incomingEdges('B'));
    }

    #[Test]
    public function replaceEdgesCanRemoveAnEdgeAndCleanAllIndexes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addEdge(new Edge('A', 'B'));
        $layoutGraph = LayoutGraph::fromGraph($graph);

        $edge = $layoutGraph->edges()[0];
        $layoutGraph->replaceEdges([$edge], []);

        self::assertSame([], $layoutGraph->edges());
        self::assertSame([], $layoutGraph->successors('A'));
        self::assertSame([], $layoutGraph->predecessors('B'));
        self::assertSame([], $layoutGraph->outgoingEdges('A'));
        self::assertSame([], $layoutGraph->incomingEdges('B'));
    }

    #[Test]
    public function replaceEdgesMaintainsSequentialIndexesAfterRemoval(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('A', 'B'));
        $layoutGraph = LayoutGraph::fromGraph($graph);

        $edgeAC = $layoutGraph->edges()[0];
        $layoutGraph->replaceEdges([$edgeAC], []);

        self::assertCount(2, $layoutGraph->edges());
        self::assertSame('C', $layoutGraph->edges()[0]->targetId());
        self::assertSame(['B'], $layoutGraph->successors('A'));
        self::assertSame('B', $layoutGraph->predecessors('C')[0]);
        self::assertCount(1, $layoutGraph->outgoingEdges('A'));
        self::assertSame('B', $layoutGraph->outgoingEdges('A')[0]->targetId());
        self::assertCount(1, $layoutGraph->incomingEdges('C'));
        self::assertSame('B', $layoutGraph->incomingEdges('C')[0]->sourceId());
    }

    #[Test]
    public function replaceEdgesRebuildsEveryAdjacencyIndexOnce(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $layoutGraph = LayoutGraph::fromGraph($graph);

        $removed = $layoutGraph->edges()[0];
        $replacement = new LayoutEdge(new Edge('A', 'C'));
        $layoutGraph->replaceEdges([$removed], [$replacement]);

        self::assertSame([$layoutGraph->edges()[0], $replacement], $layoutGraph->edges());
        self::assertSame(['C'], $layoutGraph->successors('A'));
        self::assertSame(['B', 'A'], $layoutGraph->predecessors('C'));
        self::assertSame([$replacement], $layoutGraph->outgoingEdges('A'));
        self::assertSame([$layoutGraph->edges()[0], $replacement], $layoutGraph->incomingEdges('C'));
    }

    #[Test]
    public function removeNodesDeletesNodeAndCleansMaps(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addEdge(new Edge('A', 'B'));
        $layoutGraph = LayoutGraph::fromGraph($graph);

        $layoutGraph->removeNodes(['B']);

        self::assertFalse($layoutGraph->hasNode('B'));
        self::assertSame(1, $layoutGraph->nodeCount());
        self::assertSame([], $layoutGraph->successors('A'));
        self::assertSame([], $layoutGraph->outgoingEdges('A'));
        self::assertSame([], $layoutGraph->edges());
    }

    #[Test]
    public function removeNodesDeletesAllNodesAndRebuildsIndexesOnce(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addNode(new Node('D', 'Delta'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('A', 'D'));
        $layoutGraph = LayoutGraph::fromGraph($graph);

        $layoutGraph->removeNodes(['B', 'C']);

        self::assertSame(['A', 'D'], $layoutGraph->nodeIds());
        self::assertCount(1, $layoutGraph->edges());
        self::assertSame(['D'], $layoutGraph->successors('A'));
        self::assertSame(['A'], $layoutGraph->predecessors('D'));
    }

    #[Test]
    public function setLayerOrderReplacesTheLayerOrder(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->getLayoutNode('A')->layer = 0;
        $layoutGraph->getLayoutNode('B')->layer = 1;
        $layoutGraph->getLayoutNode('C')->layer = 1;
        $layoutGraph->buildLayerIndex();

        $layoutGraph->setLayerOrder(1, ['C', 'B']);

        self::assertSame(['C', 'B'], $layoutGraph->layerIndex()[1]);
    }

    #[Test]
    public function setLayerOrderThrowsForMismatchedNodes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->getLayoutNode('A')->layer = 0;
        $layoutGraph->getLayoutNode('B')->layer = 0;
        $layoutGraph->buildLayerIndex();

        $this->expectException(InvalidArgumentException::class);
        $layoutGraph->setLayerOrder(0, ['A', 'C']);
    }

    #[Test]
    public function storesEveryOriginalEdgeIncludingParallelEdges(): void
    {
        $graph = new LayoutGraph();
        $firstEdge = new Edge('A', 'B', edgeStrokeStyle: EdgeStrokeStyle::Dashed);
        $secondEdge = new Edge('A', 'B', edgeStrokeStyle: EdgeStrokeStyle::Heavy);
        $graph->storeOriginalEdge($firstEdge);
        $graph->storeOriginalEdge($secondEdge);

        self::assertSame([$firstEdge, $secondEdge], $graph->originalEdges());
    }

    #[Test]
    public function fromGraphStoresOriginalEdges(): void
    {
        $domainGraph = new Graph();
        $domainGraph->addNode(new Node('A', 'Alpha'));
        $domainGraph->addNode(new Node('B', 'Beta'));
        $domainGraph->addEdge(new Edge('A', 'B', edgeStrokeStyle: EdgeStrokeStyle::Dashed));

        $layoutGraph = LayoutGraph::fromGraph($domainGraph);
        $originalEdges = $layoutGraph->originalEdges();

        self::assertCount(1, $originalEdges);
        self::assertSame(EdgeStrokeStyle::Dashed, $originalEdges[0]->edgeStrokeStyle);
    }
}
