<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\CrossingCounter;
use PhpDag\Layout\LayoutGraph;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CrossingCounterTest extends TestCase
{
    #[Test]
    public function zeroCrossingsForParallelEdges(): void
    {
        $graph = $this->buildTwoLayerGraph(
            upperNodeIds: ['A', 'B'],
            lowerNodeIds: ['C', 'D'],
            edges: [['A', 'C'], ['B', 'D']],
        );

        $counter = new CrossingCounter();

        self::assertSame(0, $counter->countBetweenLayers($graph, 0, 1));
    }

    #[Test]
    public function oneCrossingForCrossedEdges(): void
    {
        $graph = $this->buildTwoLayerGraph(
            upperNodeIds: ['A', 'B'],
            lowerNodeIds: ['C', 'D'],
            edges: [['A', 'D'], ['B', 'C']],
        );

        $counter = new CrossingCounter();

        self::assertSame(1, $counter->countBetweenLayers($graph, 0, 1));
    }

    #[Test]
    public function threeCrossingsForFullyReversedEdges(): void
    {
        $graph = $this->buildTwoLayerGraph(
            upperNodeIds: ['A', 'B', 'C'],
            lowerNodeIds: ['D', 'E', 'F'],
            edges: [['A', 'F'], ['B', 'E'], ['C', 'D']],
        );

        $counter = new CrossingCounter();

        self::assertSame(3, $counter->countBetweenLayers($graph, 0, 1));
    }

    #[Test]
    public function sameSourceFanOutNeverCrossesButDifferentSourcesStillDo(): void
    {
        // A fans out to Z and X (one parent cannot cross itself, in any child
        // order); B goes to Y. The only real crossing is A->Z against B->Y.
        $graph = $this->buildTwoLayerGraph(
            upperNodeIds: ['A', 'B'],
            lowerNodeIds: ['X', 'Y', 'Z'],
            edges: [['A', 'Z'], ['A', 'X'], ['B', 'Y']],
        );

        self::assertSame(1, (new CrossingCounter())->countBetweenLayers($graph, 0, 1));
    }

    #[Test]
    public function countAllSumsAllLayerPairs(): void
    {
        $graph = new Graph();
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $id) {
            $graph->addNode(new Node($id, $id));
        }
        $graph->addEdge(new Edge('A', 'D'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('C', 'F'));
        $graph->addEdge(new Edge('D', 'E'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        foreach (['A' => 0, 'B' => 0, 'C' => 1, 'D' => 1, 'E' => 2, 'F' => 2] as $id => $layer) {
            $layoutGraph->getLayoutNode($id)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();

        $counter = new CrossingCounter();

        self::assertSame(2, $counter->countAll($layoutGraph));
    }

    #[Test]
    public function fiveInversionsForFourCrossedEdges(): void
    {
        $graph = $this->buildTwoLayerGraph(
            upperNodeIds: ['A', 'B', 'C', 'D'],
            lowerNodeIds: ['E', 'F', 'G', 'H'],
            edges: [['A', 'H'], ['B', 'F'], ['C', 'G'], ['D', 'E']],
        );

        $counter = new CrossingCounter();

        self::assertSame(5, $counter->countBetweenLayers($graph, 0, 1));
    }

    /**
     * @param list<string>                      $upperNodeIds
     * @param list<string>                      $lowerNodeIds
     * @param list<array{0: string, 1: string}> $edges
     */
    private function buildTwoLayerGraph(array $upperNodeIds, array $lowerNodeIds, array $edges): LayoutGraph
    {
        $graph = new Graph();
        foreach ($upperNodeIds as $id) {
            $graph->addNode(new Node($id, $id));
        }
        foreach ($lowerNodeIds as $id) {
            $graph->addNode(new Node($id, $id));
        }
        foreach ($edges as [$source, $target]) {
            $graph->addEdge(new Edge($source, $target));
        }

        $layoutGraph = LayoutGraph::fromGraph($graph);
        foreach ($upperNodeIds as $id) {
            $layoutGraph->getLayoutNode($id)->layer = 0;
        }
        foreach ($lowerNodeIds as $id) {
            $layoutGraph->getLayoutNode($id)->layer = 1;
        }
        $layoutGraph->buildLayerIndex();

        return $layoutGraph;
    }
}
