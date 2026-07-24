<?php

declare(strict_types=1);

namespace PhpDag\Tests\Graph;

use InvalidArgumentException;
use LogicException;
use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Group;
use PhpDag\Graph\Node;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GraphTest extends TestCase
{
    #[Test]
    public function emptyGraphHasZeroCounts(): void
    {
        $graph = new Graph();

        self::assertSame(0, $graph->nodeCount());
        self::assertSame(0, $graph->edgeCount());
    }

    #[Test]
    public function fromEdgesBuildsNodesWithTitlesAndTheEdgesBetweenThem(): void
    {
        $graph = Graph::fromEdges(
            nodes: ['a' => 'Start', 'b' => 'Middle', 'c' => 'End'],
            edges: [['a', 'b'], ['b', 'c']],
        );

        self::assertSame(3, $graph->nodeCount());
        self::assertSame('Start', $graph->getNode('a')->title);
        self::assertSame('End', $graph->getNode('c')->title);
        self::assertSame(2, $graph->edgeCount());
        self::assertSame(['b'], $graph->successors('a'));
        self::assertSame(['c'], $graph->successors('b'));
    }

    #[Test]
    public function connectAutoCreatesMissingEndpointsAndAddsTheEdge(): void
    {
        $graph = new Graph();

        $result = $graph->connect('a', 'b');

        self::assertSame($graph, $result, 'connect returns $this');
        self::assertSame(2, $graph->nodeCount());
        self::assertSame('a', $graph->getNode('a')->title, 'auto-created node title defaults to its id');
        self::assertSame(['b'], $graph->successors('a'));
    }

    #[Test]
    public function connectReusesExistingNodesInsteadOfDuplicating(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'Custom title'));

        $graph->connect('a', 'b')->connect('b', 'a');

        self::assertSame(2, $graph->nodeCount());
        self::assertSame('Custom title', $graph->getNode('a')->title, 'an existing node is not overwritten');
        self::assertSame(2, $graph->edgeCount());
    }

    #[Test]
    public function fromEdgesAcceptsNumericStringNodeIds(): void
    {
        $graph = Graph::fromEdges(
            nodes: ['0' => 'Zero', '1' => 'One'],
            edges: [['0', '1']],
        );

        self::assertSame('Zero', $graph->getNode('0')->title);
        self::assertSame('0', $graph->getNode('0')->id);
        self::assertSame(['1'], $graph->successors('0'));
    }

    #[Test]
    public function addNodeStoresNodeAndReturnsSelf(): void
    {
        $graph = new Graph();
        $node = new Node('A', 'Hello');

        $result = $graph->addNode($node);

        self::assertSame($graph, $result);
        self::assertSame($node, $graph->getNode('A'));
        self::assertSame(1, $graph->nodeCount());
    }

    #[Test]
    public function getNodeThrowsForUnknownId(): void
    {
        $graph = new Graph();

        $this->expectException(InvalidArgumentException::class);

        $graph->getNode('X');
    }

    #[Test]
    public function addNodeRejectsDuplicateId(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Hello'));

        $this->expectException(InvalidArgumentException::class);

        $graph->addNode(new Node('A', 'World'));
    }

    #[Test]
    public function addEdgeStoresEdgeAndReturnsSelf(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));

        $result = $graph->addEdge(new Edge('A', 'B'));

        self::assertSame($graph, $result);
        self::assertSame(1, $graph->edgeCount());
    }

    #[Test]
    public function addEdgeRejectsUnknownSourceNode(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('B', 'B'));

        $this->expectException(InvalidArgumentException::class);

        $graph->addEdge(new Edge('A', 'B'));
    }

    #[Test]
    public function addEdgeRejectsUnknownTargetNode(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));

        $this->expectException(InvalidArgumentException::class);

        $graph->addEdge(new Edge('A', 'B'));
    }

    #[Test]
    public function allowsParallelEdgesButKeepsAdjacencyDeduplicated(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $first = new Edge('A', 'B');
        $second = new Edge('A', 'B');
        $graph->addEdge($first);
        $graph->addEdge($second);

        self::assertSame([$first, $second], $graph->edges());
        self::assertSame(['B'], $graph->successors('A'));
        self::assertSame(['A'], $graph->predecessors('B'));
    }

    #[Test]
    public function selfLoopDoesNotMakeNodeItsOwnNeighbour(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addEdge(new Edge('A', 'A'));

        self::assertSame([], $graph->successors('A'));
        self::assertSame([], $graph->predecessors('A'));
    }

    #[Test]
    public function selfLoopsAreExposedSeparatelyFromEdges(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $normal = new Edge('A', 'B');
        $loop = new Edge('A', 'A');
        $graph->addEdge($normal);
        $graph->addEdge($loop);

        self::assertSame([$normal], $graph->edges());
        self::assertSame([$loop], $graph->selfLoops());
    }

    #[Test]
    public function rootsExcludeSelfLoopedNodes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'A'));

        self::assertSame([], $graph->roots());
    }

    #[Test]
    public function leavesExcludeSelfLoopedNodes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('B', 'A'));
        $graph->addEdge(new Edge('A', 'A'));

        self::assertSame([], $graph->leaves());
    }

    #[Test]
    public function everySelfLoopedNodeIsExcludedFromRootsAndLeaves(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'A'));
        $graph->addEdge(new Edge('B', 'B'));

        self::assertSame([], $graph->roots());
        self::assertSame([], $graph->leaves());
    }

    #[Test]
    public function edgeCountIncludesSelfLoops(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'A'));

        self::assertSame(2, $graph->edgeCount());
    }

    #[Test]
    public function selfLoopMakesGraphCyclic(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addEdge(new Edge('A', 'A'));

        self::assertTrue($graph->isCyclic());
    }

    #[Test]
    public function edgesReturnsAllEdges(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $edgeAB = new Edge('A', 'B');
        $edgeBC = new Edge('B', 'C');
        $graph->addEdge($edgeAB);
        $graph->addEdge($edgeBC);

        self::assertSame([$edgeAB, $edgeBC], $graph->edges());
    }

    #[Test]
    public function nodesReturnsAllNodesKeyedById(): void
    {
        $graph = new Graph();
        $nodeA = new Node('A', 'A');
        $nodeB = new Node('B', 'B');
        $graph->addNode($nodeA);
        $graph->addNode($nodeB);

        self::assertSame(['A' => $nodeA, 'B' => $nodeB], $graph->nodes());
    }

    #[Test]
    public function successorsReturnsDirectChildren(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));

        self::assertSame(['B', 'C'], $graph->successors('A'));
    }

    #[Test]
    public function predecessorsReturnsDirectParents(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('B', 'C'));

        self::assertSame(['A', 'B'], $graph->predecessors('C'));
    }

    #[Test]
    public function rootsReturnsNodesWithNoPredecessors(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));

        self::assertSame(['A'], $graph->roots());
    }

    #[Test]
    public function leavesReturnsNodesWithNoSuccessors(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));

        self::assertSame(['B', 'C'], $graph->leaves());
    }

    #[Test]
    public function outgoingEdgesReturnsOutgoingEdges(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $edgeAB = new Edge('A', 'B');
        $edgeAC = new Edge('A', 'C');
        $graph->addEdge($edgeAB);
        $graph->addEdge($edgeAC);

        self::assertSame([$edgeAB, $edgeAC], $graph->outgoingEdges('A'));
        self::assertSame([], $graph->outgoingEdges('B'));
    }

    #[Test]
    public function incomingEdgesReturnsIncomingEdges(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $edgeAC = new Edge('A', 'C');
        $edgeBC = new Edge('B', 'C');
        $graph->addEdge($edgeAC);
        $graph->addEdge($edgeBC);

        self::assertSame([$edgeAC, $edgeBC], $graph->incomingEdges('C'));
        self::assertSame([], $graph->incomingEdges('A'));
    }

    #[Test]
    public function descriptiveEdgeAccessorsReturnIncomingAndOutgoingEdges(): void
    {
        $graph = Graph::fromEdges(
            nodes: ['A' => 'A', 'B' => 'B'],
            edges: [['A', 'B']],
        );

        self::assertSame($graph->edges(), $graph->outgoingEdges('A'));
        self::assertSame($graph->edges(), $graph->incomingEdges('B'));
    }

    #[Test]
    public function isolatedNodeIsBothRootAndLeaf(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));

        self::assertSame(['A'], $graph->roots());
        self::assertSame(['A'], $graph->leaves());
    }

    #[Test]
    public function multipleRootsInFanInPattern(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('B', 'C'));

        self::assertSame(['A', 'B'], $graph->roots());
        self::assertSame(['C'], $graph->leaves());
    }

    #[Test]
    public function duplicateEdgeDetectionUsesNullSeparator(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('BC', 'BC'));
        $graph->addNode(new Node('AB', 'AB'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'BC'));
        $graph->addEdge(new Edge('AB', 'C'));

        self::assertSame(2, $graph->edgeCount());
    }

    #[Test]
    public function highlightPathLeavesEarlierEdgesUnchangedWhenALaterEdgeIsMissing(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'B'));

        try {
            $graph->highlightPath(['A', 'B', 'C']);
            self::fail('Expected InvalidArgumentException for the missing B→C edge');
        } catch (InvalidArgumentException) {
        }

        self::assertSame(EdgeStrokeStyle::Solid, $graph->edges()[0]->edgeStrokeStyle, 'A→B must be untouched when the path fails on a later edge');
    }

    #[Test]
    public function highlightPathStylesEveryParallelEdgeBetweenTwoNodes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'B'));

        $graph->highlightPath(['A', 'B']);

        $edges = $graph->edges();
        self::assertCount(2, $edges);
        self::assertSame(EdgeStrokeStyle::Heavy, $edges[0]->edgeStrokeStyle);
        self::assertSame(EdgeStrokeStyle::Heavy, $edges[1]->edgeStrokeStyle, 'Both parallel A→B edges must be highlighted');
    }

    #[Test]
    public function highlightPathSetsStrokeStyleOnPathEdges(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));

        $result = $graph->highlightPath(['A', 'B', 'C'], EdgeStrokeStyle::Heavy);

        self::assertSame($graph, $result, 'highlightPath returns $this');

        $edges = $graph->edges();
        self::assertSame(EdgeStrokeStyle::Heavy, $edges[0]->edgeStrokeStyle);
        self::assertSame(EdgeStrokeStyle::Heavy, $edges[1]->edgeStrokeStyle);

        self::assertSame(EdgeStrokeStyle::Heavy, $graph->outgoingEdges('A')[0]->edgeStrokeStyle);
        self::assertSame(EdgeStrokeStyle::Heavy, $graph->incomingEdges('B')[0]->edgeStrokeStyle);
    }

    #[Test]
    public function highlightPathThrowsForMissingEdge(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'B'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No edge from "B" to "C"');

        $graph->highlightPath(['A', 'B', 'C']);
    }

    #[Test]
    public function highlightPathDefaultsToHeavyStyle(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B'));

        $graph->highlightPath(['A', 'B']);

        self::assertSame(EdgeStrokeStyle::Heavy, $graph->edges()[0]->edgeStrokeStyle);
    }

    #[Test]
    public function colorPathThrowsForMissingSingleNode(): void
    {
        $graph = new Graph();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Node "ghost" does not exist');

        $graph->colorPath(['ghost'], AnsiColor::Red);
    }

    #[Test]
    public function colorPathColorsEveryParallelEdgeBetweenTwoNodes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'B'));

        $graph->colorPath(['A', 'B'], AnsiColor::Red);

        $edges = $graph->edges();
        self::assertCount(2, $edges);
        self::assertSame(AnsiColor::Red, $edges[0]->color);
        self::assertSame(AnsiColor::Red, $edges[1]->color, 'Both parallel A→B edges must be coloured');
    }

    #[Test]
    public function colorPathSetsColorOnPathEdges(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));

        $graph->colorPath(['A', 'B', 'C'], AnsiColor::Red);

        self::assertSame(AnsiColor::Red, $graph->outgoingEdges('A')[0]->color);
        self::assertSame(AnsiColor::Red, $graph->outgoingEdges('B')[0]->color);
    }

    #[Test]
    public function colorPathSetsColorOnPathNodes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addEdge(new Edge('A', 'B'));

        $graph->colorPath(['A', 'B'], AnsiColor::Green);

        self::assertSame(AnsiColor::Green, $graph->getNode('A')->color);
        self::assertSame(AnsiColor::Green, $graph->getNode('B')->color);
    }

    #[Test]
    public function colorPathThrowsForMissingEdge(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));

        $this->expectException(InvalidArgumentException::class);
        $graph->colorPath(['A', 'B'], AnsiColor::Red);
    }

    #[Test]
    public function colorPathLeavesEarlierEdgesUnchangedWhenALaterEdgeIsMissing(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'B'));

        try {
            $graph->colorPath(['A', 'B', 'C'], AnsiColor::Red);
            self::fail('Expected InvalidArgumentException for the missing B→C edge');
        } catch (InvalidArgumentException) {
        }

        self::assertNull($graph->edges()[0]->color, 'A→B must be untouched when the path fails on a later edge');
        self::assertNull($graph->getNode('A')->color, 'Node A must be untouched too');
    }

    #[Test]
    public function isCyclicReturnsFalseForAcyclicGraph(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('B', 'C'));

        self::assertFalse($graph->isCyclic());
    }

    #[Test]
    public function isCyclicReturnsTrueForCyclicGraph(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('C', 'A'));

        self::assertTrue($graph->isCyclic());
    }

    #[Test]
    public function isCyclicReturnsFalseForSingleEdgeGraph(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addEdge(new Edge('A', 'B'));

        self::assertFalse($graph->isCyclic());
    }

    #[Test]
    public function isCyclicReturnsFalseWhenTargetNodeWasAddedBeforeSource(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addEdge(new Edge('A', 'B'));

        self::assertFalse($graph->isCyclic());
    }

    #[Test]
    public function colorPathReplacesOnlyTheTargetedEdge(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addNode(new Node('D', 'D'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('D', 'B'));

        $graph->colorPath(['A', 'B'], AnsiColor::Red);

        $outgoingEdges = $graph->outgoingEdges('A');
        self::assertCount(2, $outgoingEdges);
        self::assertSame('B', $outgoingEdges[0]->targetId);
        self::assertSame(AnsiColor::Red, $outgoingEdges[0]->color);
        self::assertSame('C', $outgoingEdges[1]->targetId);
        self::assertNull($outgoingEdges[1]->color);

        $incomingEdges = $graph->incomingEdges('B');
        self::assertCount(2, $incomingEdges);
        self::assertSame('A', $incomingEdges[0]->sourceId);
        self::assertSame(AnsiColor::Red, $incomingEdges[0]->color);
        self::assertSame('D', $incomingEdges[1]->sourceId);
        self::assertNull($incomingEdges[1]->color);

        $edges = $graph->edges();
        self::assertCount(3, $edges);
        self::assertSame(AnsiColor::Red, $edges[0]->color);
        self::assertSame('C', $edges[1]->targetId);
        self::assertNull($edges[1]->color);
        self::assertSame('D', $edges[2]->sourceId);
        self::assertNull($edges[2]->color);
    }

    #[Test]
    public function highlightPathReplacesOnlyTheTargetedEdge(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addNode(new Node('D', 'D'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('D', 'B'));

        $graph->highlightPath(['A', 'B']);

        $outgoingEdges = $graph->outgoingEdges('A');
        self::assertCount(2, $outgoingEdges);
        self::assertSame(EdgeStrokeStyle::Heavy, $outgoingEdges[0]->edgeStrokeStyle);
        self::assertSame(EdgeStrokeStyle::Solid, $outgoingEdges[1]->edgeStrokeStyle);

        $incomingEdges = $graph->incomingEdges('B');
        self::assertCount(2, $incomingEdges);
        self::assertSame(EdgeStrokeStyle::Heavy, $incomingEdges[0]->edgeStrokeStyle);
        self::assertSame(EdgeStrokeStyle::Solid, $incomingEdges[1]->edgeStrokeStyle);

        $edges = $graph->edges();
        self::assertCount(3, $edges);
        self::assertSame(EdgeStrokeStyle::Heavy, $edges[0]->edgeStrokeStyle);
        self::assertSame(EdgeStrokeStyle::Solid, $edges[1]->edgeStrokeStyle);
        self::assertSame(EdgeStrokeStyle::Solid, $edges[2]->edgeStrokeStyle);
    }

    #[Test]
    public function fromDotParsesAndToDotExports(): void
    {
        $graph = Graph::fromDot('digraph { a [label="Start"]; a -> b; }');

        self::assertSame('Start', $graph->getNode('a')->title);
        self::assertSame(['b'], $graph->successors('a'));
        self::assertStringContainsString('"a" -> "b"', $graph->toDot());
    }

    #[Test]
    public function addGroupStoresAndReturnsGroups(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('lint', 'Lint'));
        $graph->addNode(new Node('test', 'Tests'));

        $graph->addGroup(new Group('quality', 'Quality', ['lint', 'test']));

        self::assertCount(1, $graph->groups());
        self::assertSame('quality', $graph->groups()[0]->id);
        self::assertSame(['lint', 'test'], $graph->groups()[0]->nodeIds);
    }

    #[Test]
    public function addGroupIsFluent(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'A'));

        self::assertSame($graph, $graph->addGroup(new Group('g', 'G', ['a'])));
    }

    #[Test]
    public function addGroupRejectsUnknownMember(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('lint', 'Lint'));

        $this->expectException(InvalidArgumentException::class);
        $graph->addGroup(new Group('quality', 'Quality', ['lint', 'ghost']));
    }

    #[Test]
    public function addGroupRejectsDuplicateGroupId(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'A'));
        $graph->addGroup(new Group('g', 'G', ['a']));

        $this->expectException(InvalidArgumentException::class);
        $graph->addGroup(new Group('g', 'Other', ['a']));
    }

    #[Test]
    public function graphWithoutGroupsReturnsEmptyArray(): void
    {
        self::assertSame([], (new Graph())->groups());
    }

    #[Test]
    public function shortestPathUsesBreadthFirstTraversalAndReturnsNullWhenUnreachable(): void
    {
        $graph = Graph::fromEdges(
            nodes: ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D', 'E' => 'E'],
            edges: [['A', 'B'], ['B', 'D'], ['A', 'C'], ['C', 'E'], ['E', 'D']],
        );

        self::assertSame(['A', 'B', 'D'], $graph->shortestPath('A', 'D'));
        self::assertNull($graph->shortestPath('D', 'A'));
    }

    #[Test]
    public function shortestPathThrowsWhenSourceNodeDoesNotExist(): void
    {
        $graph = Graph::fromEdges(nodes: ['b' => 'B'], edges: []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Node "missing" does not exist');

        $graph->shortestPath('missing', 'b');
    }

    #[Test]
    public function descendantsThrowWhenNodeDoesNotExist(): void
    {
        $graph = new Graph();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Node "missing" does not exist');

        $graph->descendants('missing');
    }

    #[Test]
    public function descendantsReturnsEveryReachableNodeOnceWithoutReturningTheStartNode(): void
    {
        $graph = Graph::fromEdges(
            nodes: ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D'],
            edges: [['A', 'B'], ['A', 'C'], ['B', 'D'], ['C', 'D'], ['D', 'A']],
        );

        self::assertSame(['B', 'C', 'D'], $graph->descendants('A'));
    }

    #[Test]
    public function ancestorsReturnsEveryReachableParentOnceWithoutReturningTheStartNode(): void
    {
        $graph = Graph::fromEdges(
            nodes: ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D'],
            edges: [['A', 'B'], ['A', 'C'], ['B', 'D'], ['C', 'D'], ['D', 'A']],
        );

        self::assertSame(['B', 'C', 'A'], $graph->ancestors('D'));
    }

    #[Test]
    public function topologicalOrderReturnsAStableOrderAndRejectsCycles(): void
    {
        $graph = Graph::fromEdges(
            nodes: ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D', 'E' => 'E'],
            edges: [['A', 'C'], ['B', 'C'], ['C', 'D']],
        );

        self::assertSame(['A', 'B', 'E', 'C', 'D'], $graph->topologicalOrder());

        $cyclicGraph = Graph::fromEdges(
            nodes: ['A' => 'A', 'B' => 'B'],
            edges: [['A', 'B'], ['B', 'A']],
        );

        $this->expectException(LogicException::class);
        $cyclicGraph->topologicalOrder();
    }
}
