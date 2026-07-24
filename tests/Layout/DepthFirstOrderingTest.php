<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\DepthFirstOrdering;
use PhpDag\Layout\LayoutGraph;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DepthFirstOrderingTest extends TestCase
{
    #[Test]
    public function seedsEachLayerInDepthFirstDiscoveryOrder(): void
    {
        // A->D and B->C. A DFS from A places D first in layer 1, then the DFS
        // from B places C after it, giving [D, C] — which happens to be the
        // crossing-free order, unlike the insertion order [C, D].
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'C', 'D'],
            layers: ['A' => 0, 'B' => 0, 'C' => 1, 'D' => 1],
            edges: [['A', 'D'], ['B', 'C']],
        );

        (new DepthFirstOrdering())->process($layoutGraph);

        self::assertSame(['A', 'B'], $layoutGraph->layerIndex()[0]);
        self::assertSame(['D', 'C'], $layoutGraph->layerIndex()[1]);
    }

    #[Test]
    public function visitsASharedDescendantOnlyOnce(): void
    {
        // Diamond A->B, A->C, B->D, C->D. D is reached from both B and C but
        // must land in its layer exactly once, or setLayerOrder rejects the
        // duplicated membership.
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'C', 'D'],
            layers: ['A' => 0, 'B' => 1, 'C' => 1, 'D' => 2],
            edges: [['A', 'B'], ['A', 'C'], ['B', 'D'], ['C', 'D']],
        );

        (new DepthFirstOrdering())->process($layoutGraph);

        self::assertSame(['B', 'C'], $layoutGraph->layerIndex()[1]);
        self::assertSame(['D'], $layoutGraph->layerIndex()[2]);
    }

    #[Test]
    public function groupsAnEntireChainAcrossLayersBeforeTheNext(): void
    {
        // Two disjoint chains X->Y->Z and A->B->C. Starting the DFS from X
        // pulls its whole chain to the front of every layer before A's chain,
        // regrouping the interleaved insertion order layer by layer.
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['X', 'A', 'B', 'Y', 'C', 'Z'],
            layers: ['X' => 0, 'A' => 0, 'B' => 1, 'Y' => 1, 'C' => 2, 'Z' => 2],
            edges: [['X', 'Y'], ['Y', 'Z'], ['A', 'B'], ['B', 'C']],
        );

        (new DepthFirstOrdering())->process($layoutGraph);

        self::assertSame(['X', 'A'], $layoutGraph->layerIndex()[0]);
        self::assertSame(['Y', 'B'], $layoutGraph->layerIndex()[1]);
        self::assertSame(['Z', 'C'], $layoutGraph->layerIndex()[2]);
    }

    /**
     * @param list<string>                      $nodeIds
     * @param array<string, int>                $layers
     * @param list<array{0: string, 1: string}> $edges
     */
    private function buildLayeredGraph(array $nodeIds, array $layers, array $edges): LayoutGraph
    {
        $graph = new Graph();
        foreach ($nodeIds as $id) {
            $graph->addNode(new Node($id, $id));
        }
        foreach ($edges as [$source, $target]) {
            $graph->addEdge(new Edge($source, $target));
        }

        $layoutGraph = LayoutGraph::fromGraph($graph);
        foreach ($layers as $id => $layer) {
            $layoutGraph->getLayoutNode($id)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();

        return $layoutGraph;
    }
}
