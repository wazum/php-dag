<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Label;
use PhpDag\Graph\Node;
use PhpDag\Layout\CycleBreaker;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\Processor;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CycleBreakerTest extends TestCase
{
    #[Test]
    public function implementsProcessor(): void
    {
        self::assertInstanceOf(Processor::class, new CycleBreaker());
    }

    #[Test]
    public function doesNotModifyAcyclicGraph(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new CycleBreaker())->process($layoutGraph);

        $edges = $layoutGraph->edges();
        self::assertCount(2, $edges);
        foreach ($edges as $edge) {
            self::assertFalse($edge->reversed, 'No edges should be reversed in an acyclic graph');
        }
    }

    #[Test]
    public function reversesBackEdgeInTwoNodeCycle(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'A'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new CycleBreaker())->process($layoutGraph);

        $reversedEdges = array_filter(
            $layoutGraph->edges(),
            static fn (LayoutEdge $edge): bool => $edge->reversed,
        );

        self::assertCount(1, $reversedEdges, 'Exactly one edge must be reversed to break the cycle');
    }

    #[Test]
    public function reversedEdgesGetDashedStrokeStyle(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'A'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new CycleBreaker())->process($layoutGraph);

        $reversedEdge = current(array_filter(
            $layoutGraph->edges(),
            static fn (LayoutEdge $edge): bool => $edge->reversed,
        ));

        self::assertNotFalse($reversedEdge);
        self::assertSame(EdgeStrokeStyle::Dashed, $reversedEdge->edge->edgeStrokeStyle);
    }

    #[Test]
    public function doesNotReverseCrossEdgesInDiamondDag(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addNode(new Node('D', 'D'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('B', 'D'));
        $graph->addEdge(new Edge('C', 'D'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new CycleBreaker())->process($layoutGraph);

        $reversedCount = count(array_filter(
            $layoutGraph->edges(),
            static fn (LayoutEdge $edge): bool => $edge->reversed,
        ));

        self::assertSame(0, $reversedCount, 'Diamond DAG has no cycles — no edges should be reversed');
    }

    #[Test]
    public function breaksTriangleCycle(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('C', 'A'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new CycleBreaker())->process($layoutGraph);

        $reversedEdges = array_filter(
            $layoutGraph->edges(),
            static fn (LayoutEdge $edge): bool => $edge->reversed,
        );

        self::assertCount(1, $reversedEdges, 'Exactly one edge reversed to break triangle');

        $nonReversedEdges = array_filter(
            $layoutGraph->edges(),
            static fn (LayoutEdge $edge): bool => !$edge->reversed,
        );
        self::assertCount(2, $nonReversedEdges, 'Two edges remain unreversed');
    }

    #[Test]
    public function reversedEdgesPreserveOriginalProperties(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        // A→B is the heavier edge and is kept; the lighter B→A is reversed and
        // must carry its original label, colour and weight through the reversal.
        $graph->addEdge(new Edge('A', 'B', weight: 7));
        $graph->addEdge(new Edge('B', 'A', label: new Label('feedback'), color: AnsiColor::Red, weight: 2));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new CycleBreaker())->process($layoutGraph);

        $reversedEdge = current(array_filter(
            $layoutGraph->edges(),
            static fn (LayoutEdge $edge): bool => $edge->reversed,
        ));

        self::assertNotFalse($reversedEdge);
        self::assertSame('B', $reversedEdge->edge->sourceId);
        self::assertNotNull($reversedEdge->edge->label);
        self::assertSame('feedback', $reversedEdge->edge->label->text);
        self::assertSame(AnsiColor::Red, $reversedEdge->edge->color);
        self::assertSame(2, $reversedEdge->edge->weight);
    }

    #[Test]
    public function handlesPureCycleWithNoRoots(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('C', 'A'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new CycleBreaker())->process($layoutGraph);

        $reversedCount = count(array_filter(
            $layoutGraph->edges(),
            static fn (LayoutEdge $edge): bool => $edge->reversed,
        ));

        self::assertGreaterThanOrEqual(1, $reversedCount, 'At least one edge reversed in pure cycle');
        self::assertCount(3, $layoutGraph->edges(), 'Total edge count preserved');
    }

    #[Test]
    public function handlesMultipleIndependentCycles(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addNode(new Node('D', 'D'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'A'));
        $graph->addEdge(new Edge('C', 'D'));
        $graph->addEdge(new Edge('D', 'C'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new CycleBreaker())->process($layoutGraph);

        $reversedCount = count(array_filter(
            $layoutGraph->edges(),
            static fn (LayoutEdge $edge): bool => $edge->reversed,
        ));

        self::assertSame(2, $reversedCount, 'One edge reversed per independent cycle');
        self::assertCount(4, $layoutGraph->edges(), 'Total edge count preserved');
    }

    #[Test]
    public function reversesTheLighterEdgeWhenBreakingAWeightedTwoCycle(): void
    {
        // The greedy heuristic weights each vertex by edge weight, so it keeps the
        // heavier edge (B→A, weight 5) and reverses the lighter one (A→B, weight 1).
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B', weight: 1));
        $graph->addEdge(new Edge('B', 'A', weight: 5));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new CycleBreaker())->process($layoutGraph);

        $reversed = array_values(array_filter(
            $layoutGraph->edges(),
            static fn (LayoutEdge $edge): bool => $edge->reversed,
        ));

        self::assertCount(1, $reversed);
        self::assertSame('A', $reversed[0]->edge->sourceId, 'The lighter edge A→B is reversed');
        self::assertSame('B', $reversed[0]->edge->targetId);
    }

    #[Test]
    public function accumulatesParallelEdgeWeightsWhenBreakingACycle(): void
    {
        // Two parallel A→B edges (weight 2 each) sum to 4 and outweigh the single
        // B→A edge (weight 3), so the heuristic keeps both A→B edges and reverses
        // only B→A. This fails unless parallel-edge weights accumulate.
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B', weight: 2));
        $graph->addEdge(new Edge('A', 'B', weight: 2));
        $graph->addEdge(new Edge('B', 'A', weight: 3));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new CycleBreaker())->process($layoutGraph);

        $reversed = array_values(array_filter(
            $layoutGraph->edges(),
            static fn (LayoutEdge $edge): bool => $edge->reversed,
        ));

        self::assertCount(1, $reversed, 'Only the single B→A edge is reversed; the heavier parallel A→B pair is kept');
        self::assertSame('B', $reversed[0]->edge->sourceId);
        self::assertSame('A', $reversed[0]->edge->targetId);
        self::assertCount(3, $layoutGraph->edges(), 'Total edge count preserved');
    }

    #[Test]
    public function reversesOnlyTheSharedEdgeOfOverlappingCycles(): void
    {
        // A→B→C→A and B→C→D→B overlap on B→C; reversing that single edge breaks
        // both cycles. A naive DFS reverses one edge per cycle (C→A and D→B); the
        // greedy heuristic finds the shared edge and reverses only it.
        $graph = new Graph();
        foreach (['A', 'B', 'C', 'D'] as $id) {
            $graph->addNode(new Node($id, $id));
        }
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('C', 'A'));
        $graph->addEdge(new Edge('C', 'D'));
        $graph->addEdge(new Edge('D', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new CycleBreaker())->process($layoutGraph);

        $reversed = array_values(array_filter(
            $layoutGraph->edges(),
            static fn (LayoutEdge $edge): bool => $edge->reversed,
        ));

        self::assertCount(1, $reversed, 'The greedy heuristic reverses a single shared edge, not one per cycle');
        self::assertSame('B', $reversed[0]->edge->sourceId);
        self::assertSame('C', $reversed[0]->edge->targetId);
    }

    #[Test]
    public function handlesMixedCyclicAndAcyclicSubgraphs(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addNode(new Node('D', 'D'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('C', 'B'));
        $graph->addEdge(new Edge('C', 'D'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        (new CycleBreaker())->process($layoutGraph);

        $reversedCount = count(array_filter(
            $layoutGraph->edges(),
            static fn (LayoutEdge $edge): bool => $edge->reversed,
        ));

        self::assertSame(1, $reversedCount, 'Only the back-edge in the B-C cycle should be reversed');

        $nonReversedSources = array_map(
            static fn (LayoutEdge $edge): string => $edge->sourceId(),
            array_values(array_filter($layoutGraph->edges(), static fn (LayoutEdge $edge): bool => !$edge->reversed)),
        );

        self::assertContains('A', $nonReversedSources, 'A→B should remain unreversed');
        self::assertContains('C', $nonReversedSources, 'C→D should remain unreversed');
    }

    #[Test]
    public function breaksLargeSparseStronglyConnectedGraphWithoutQuadraticRescans(): void
    {
        $graph = new Graph();
        $nodeCount = 2000;
        for ($index = 0; $index < $nodeCount; ++$index) {
            $graph->addNode(new Node((string) $index, (string) $index));
        }
        for ($index = 0; $index < $nodeCount; ++$index) {
            $next = ($index + 1) % $nodeCount;
            $graph->addEdge(new Edge((string) $index, (string) $next));
            $graph->addEdge(new Edge((string) $next, (string) $index));
        }

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $start = hrtime(true);
        (new CycleBreaker())->process($layoutGraph);
        $elapsedMilliseconds = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(250.0, $elapsedMilliseconds, sprintf('Sparse SCC cycle breaking took %.1f ms', $elapsedMilliseconds));
    }
}
