<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\CrossingCounter;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\MedianOrdering;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MedianOrderingTest extends TestCase
{
    #[Test]
    public function reducesCrossingsInTwoLayerGraph(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'C', 'D'],
            layers: ['A' => 0, 'B' => 0, 'C' => 1, 'D' => 1],
            edges: [['A', 'D'], ['B', 'C']],
        );

        $counter = new CrossingCounter();
        self::assertSame(1, $counter->countAll($layoutGraph), 'Precondition: 1 crossing before minimize');

        $ordering = new MedianOrdering();
        $ordering->minimize($layoutGraph);

        self::assertSame(0, $counter->countAll($layoutGraph));
    }

    #[Test]
    public function reducesCrossingsInThreeLayerGraph(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'C', 'D', 'E', 'F'],
            layers: ['A' => 0, 'B' => 0, 'C' => 1, 'D' => 1, 'E' => 2, 'F' => 2],
            edges: [['A', 'D'], ['B', 'C'], ['C', 'F'], ['D', 'E']],
        );

        $counter = new CrossingCounter();
        self::assertSame(2, $counter->countAll($layoutGraph), 'Precondition: 2 crossings before minimize');

        $ordering = new MedianOrdering();
        $ordering->minimize($layoutGraph);

        self::assertSame(0, $counter->countAll($layoutGraph));
    }

    #[Test]
    public function maxSweepsLimitsIterations(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'C', 'D'],
            layers: ['A' => 0, 'B' => 0, 'C' => 1, 'D' => 1],
            edges: [['A', 'D'], ['B', 'C']],
        );

        $ordering = new MedianOrdering(maxSweeps: 1);
        $ordering->minimize($layoutGraph);

        $counter = new CrossingCounter();
        self::assertSame(0, $counter->countAll($layoutGraph));
    }

    #[Test]
    public function handlesGraphWithSingleLayer(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B'],
            layers: ['A' => 0, 'B' => 0],
            edges: [],
        );

        $ordering = new MedianOrdering();
        $ordering->minimize($layoutGraph);

        self::assertSame(['A', 'B'], $layoutGraph->layerIndex()[0]);
    }

    #[Test]
    public function preservesAlreadyOptimalOrdering(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'C', 'D'],
            layers: ['A' => 0, 'B' => 0, 'C' => 1, 'D' => 1],
            edges: [['A', 'C'], ['B', 'D']],
        );

        $counter = new CrossingCounter();
        self::assertSame(0, $counter->countAll($layoutGraph), 'Precondition: 0 crossings');

        $ordering = new MedianOrdering();
        $ordering->minimize($layoutGraph);

        self::assertSame(0, $counter->countAll($layoutGraph));
        self::assertSame(['C', 'D'], $layoutGraph->layerIndex()[1]);
    }

    #[Test]
    public function zeroMaxSweepsStillRunsTranspose(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'C', 'D'],
            layers: ['A' => 0, 'B' => 0, 'C' => 1, 'D' => 1],
            edges: [['A', 'D'], ['B', 'C']],
        );

        $counter = new CrossingCounter();
        self::assertSame(1, $counter->countAll($layoutGraph), 'Precondition: 1 crossing');

        $ordering = new MedianOrdering(maxSweeps: 0);
        $ordering->minimize($layoutGraph);

        self::assertSame(0, $counter->countAll($layoutGraph), 'Transpose fixes the crossing even without sweeps');
    }

    #[Test]
    public function disabledTransposeLeavesTheCrossingSweepsCannotFix(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'C', 'D'],
            layers: ['A' => 0, 'B' => 0, 'C' => 1, 'D' => 1],
            edges: [['A', 'D'], ['B', 'C']],
        );

        $ordering = new MedianOrdering(maxSweeps: 0, transpose: false);
        $ordering->minimize($layoutGraph);

        self::assertSame(1, (new CrossingCounter())->countAll($layoutGraph), 'Without transpose the crossing must remain — this is the fast preset trade-off');
    }

    #[Test]
    public function equalMediansKeepDeclarationOrder(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['R1', 'R2', 'X', 'Y', 'Z'],
            layers: ['R1' => 0, 'R2' => 0, 'X' => 1, 'Y' => 1, 'Z' => 1],
            edges: [['R1', 'X'], ['R2', 'X'], ['R1', 'Y'], ['R2', 'Y'], ['R1', 'Z'], ['R2', 'Z']],
        );

        (new MedianOrdering())->minimize($layoutGraph);

        self::assertSame(['X', 'Y', 'Z'], $layoutGraph->layerIndex()[1], 'Siblings with identical medians must keep their declaration order');
    }

    #[Test]
    public function usesWeightedMedianNotMeanToOrderALayer(): void
    {
        // P's predecessors sit at positions {0,1,7}: weighted median 1, mean
        // 2.67. Q's single predecessor sits at 2. The median orders P before Q;
        // the mean would order Q before P (which is the initial order here).
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['u0', 'u1', 'u2', 'u3', 'u4', 'u5', 'u6', 'u7', 'Q', 'P'],
            layers: ['u0' => 0, 'u1' => 0, 'u2' => 0, 'u3' => 0, 'u4' => 0, 'u5' => 0, 'u6' => 0, 'u7' => 0, 'Q' => 1, 'P' => 1],
            edges: [['u0', 'P'], ['u1', 'P'], ['u7', 'P'], ['u2', 'Q']],
        );

        (new MedianOrdering(maxSweeps: 1, transpose: false))->minimize($layoutGraph);

        self::assertSame(['P', 'Q'], $layoutGraph->layerIndex()[1]);
    }

    #[Test]
    public function singleSweepPlusTransposeFixesCrossing(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'C', 'D', 'E'],
            layers: ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 1, 'E' => 1],
            edges: [['A', 'E'], ['B', 'D'], ['C', 'E']],
        );

        $counter = new CrossingCounter();
        self::assertSame(1, $counter->countAll($layoutGraph), 'Precondition: 1 crossing');

        $ordering = new MedianOrdering(maxSweeps: 1);
        $ordering->minimize($layoutGraph);

        self::assertSame(0, $counter->countAll($layoutGraph), 'Transpose fixes what single downward sweep could not');
    }

    #[Test]
    public function upwardSweepReducesCrossingsDownwardSweepCannot(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'C', 'D', 'E'],
            layers: ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 1, 'E' => 1],
            edges: [['A', 'E'], ['B', 'D'], ['C', 'E']],
        );

        $counter = new CrossingCounter();
        self::assertSame(1, $counter->countAll($layoutGraph), 'Precondition: 1 crossing');

        $ordering = new MedianOrdering();
        $ordering->minimize($layoutGraph);

        self::assertSame(0, $counter->countAll($layoutGraph));
    }

    #[Test]
    public function convergencePreservesBestOrderForDisconnectedNodes(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'X', 'B', 'C', 'D', 'E'],
            layers: ['A' => 0, 'X' => 0, 'B' => 0, 'C' => 0, 'D' => 1, 'E' => 1],
            edges: [['A', 'E'], ['B', 'D'], ['C', 'E']],
        );

        $counter = new CrossingCounter();
        self::assertSame(1, $counter->countAll($layoutGraph), 'Precondition: 1 crossing');

        $ordering = new MedianOrdering(maxSweeps: 24);
        $ordering->minimize($layoutGraph);

        self::assertSame(0, $counter->countAll($layoutGraph));
        self::assertSame(['A', 'C', 'X', 'B'], $layoutGraph->layerIndex()[0], 'Must revert to first order that achieved best crossings');
    }

    #[Test]
    public function transposeReducesCrossingsWhenSweepsSkipped(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'C', 'D'],
            layers: ['A' => 0, 'B' => 0, 'C' => 1, 'D' => 1],
            edges: [['A', 'D'], ['B', 'C']],
        );

        $counter = new CrossingCounter();
        self::assertSame(1, $counter->countAll($layoutGraph), 'Precondition: 1 crossing');

        $ordering = new MedianOrdering(maxSweeps: 0);
        $ordering->minimize($layoutGraph);

        self::assertSame(0, $counter->countAll($layoutGraph));
    }

    #[Test]
    public function transposeIteratesUntilNoMoreImprovements(): void
    {
        // Layer 0: [X, Y, Z], Layer 1: [C, B, A]
        // Edges: X->A, Y->B, Z->C — all crossing with reverse order in layer 1
        // Single left-to-right pass: swap(C,B)->[B,C,A] reduces 3->1, swap(C,A)->[B,A,C] reduces 1->1 (no improvement, revert)
        // Second pass needed: swap(B,A)->[A,B,C] reduces 1->0
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['X', 'Y', 'Z', 'C', 'B', 'A'],
            layers: ['X' => 0, 'Y' => 0, 'Z' => 0, 'C' => 1, 'B' => 1, 'A' => 1],
            edges: [['X', 'A'], ['Y', 'B'], ['Z', 'C']],
        );

        $counter = new CrossingCounter();
        self::assertSame(3, $counter->countAll($layoutGraph), 'Precondition: 3 crossings');

        $ordering = new MedianOrdering(maxSweeps: 0);
        $ordering->minimize($layoutGraph);

        self::assertSame(0, $counter->countAll($layoutGraph), 'Transpose must iterate until fully optimized');
    }

    #[Test]
    public function transposeReordersTheSecondToLastLayerViaItsLowerNeighbour(): void
    {
        // A fans to B and C (shared parent, so the layer above contributes no
        // crossing). B->E and C->D cross in the layer-1/2 pair. The fix must
        // reorder layer 1 — driven by its *lower* neighbour — to [C, B];
        // reordering the last layer instead would leave layer 1 as [B, C].
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'C', 'D', 'E'],
            layers: ['A' => 0, 'B' => 1, 'C' => 1, 'D' => 2, 'E' => 2],
            edges: [['A', 'B'], ['A', 'C'], ['B', 'E'], ['C', 'D']],
        );

        $counter = new CrossingCounter();
        self::assertSame(1, $counter->countAll($layoutGraph), 'Precondition: 1 crossing');

        (new MedianOrdering(maxSweeps: 0))->minimize($layoutGraph);

        self::assertSame(0, $counter->countAll($layoutGraph));
        self::assertSame(['C', 'B'], $layoutGraph->layerIndex()[1], 'Layer 1 must be reordered via its lower neighbour, not the last layer');
    }

    #[Test]
    public function transposeReordersALayerViaItsUpperNeighbour(): void
    {
        // A->C, B->D pin layer 1 (reordering it would re-cross the layer-0/1
        // pair). C->F and D->E cross in the layer-1/2 pair; E and F share child G
        // so the layer-2/3 pair never crosses. The only fix is to reorder layer 2
        // to [F, E], driven by its *upper* neighbour.
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'C', 'D', 'E', 'F', 'G'],
            layers: ['A' => 0, 'B' => 0, 'C' => 1, 'D' => 1, 'E' => 2, 'F' => 2, 'G' => 3],
            edges: [['A', 'C'], ['B', 'D'], ['C', 'F'], ['D', 'E'], ['E', 'G'], ['F', 'G']],
        );

        $counter = new CrossingCounter();
        self::assertSame(1, $counter->countAll($layoutGraph), 'Precondition: 1 crossing');

        (new MedianOrdering(maxSweeps: 0))->minimize($layoutGraph);

        self::assertSame(0, $counter->countAll($layoutGraph));
        self::assertSame(['F', 'E'], $layoutGraph->layerIndex()[2], 'Layer 2 must be reordered via its upper neighbour');
    }

    #[Test]
    public function transposeFixesCrossingsAcrossMultipleLayers(): void
    {
        // Layer 0: [A, B], Layer 1: [D, C], Layer 2: [E, F]
        // Edges: A->C, B->D create crossing in 0-1; D->F, C->E create crossing in 1-2
        // Swapping C,D in layer 1 fixes 0-1 but swaps 1-2 targets — need layer 2 swap too
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'D', 'C', 'E', 'F'],
            layers: ['A' => 0, 'B' => 0, 'D' => 1, 'C' => 1, 'E' => 2, 'F' => 2],
            edges: [['A', 'C'], ['B', 'D'], ['D', 'F'], ['C', 'E']],
        );

        $counter = new CrossingCounter();
        self::assertSame(2, $counter->countAll($layoutGraph), 'Precondition: 2 crossings');

        $ordering = new MedianOrdering(maxSweeps: 0);
        $ordering->minimize($layoutGraph);

        self::assertSame(0, $counter->countAll($layoutGraph));
    }

    #[Test]
    public function convergenceDoesNotStopBeforeBothDirectionsTried(): void
    {
        $layoutGraph = $this->buildLayeredGraph(
            nodeIds: ['A', 'B', 'C', 'D', 'E'],
            layers: ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 1, 'E' => 1],
            edges: [['A', 'E'], ['B', 'D'], ['C', 'E']],
        );

        $counter = new CrossingCounter();
        self::assertSame(1, $counter->countAll($layoutGraph), 'Precondition: 1 crossing');

        $ordering = new MedianOrdering(maxSweeps: 24);
        $ordering->minimize($layoutGraph);

        self::assertSame(0, $counter->countAll($layoutGraph), 'Must try both sweep directions before stopping');
        self::assertSame(['B', 'A', 'C'], $layoutGraph->layerIndex()[0]);
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
