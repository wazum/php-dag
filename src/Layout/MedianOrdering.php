<?php

declare(strict_types=1);

namespace PhpDag\Layout;

/**
 * Iterative layer-by-layer crossing minimisation (Sugiyama). Each node is moved
 * to the weighted median of its neighbours' positions in the adjacent layer —
 * Graphviz dot's heuristic, which resists the outlier-dragging that a plain
 * barycentre (mean) suffers — followed by an adjacent-swap transpose pass.
 */
final readonly class MedianOrdering implements CrossingMinimization
{
    /** @infection-ignore-all default value is arbitrary; ±1 produces identical results via convergence */
    public function __construct(
        private int $maxSweeps = 24,
        private bool $transpose = true,
        private WeightedMedian $median = new WeightedMedian(),
    ) {
    }

    public function minimize(LayoutGraph $graph): void
    {
        $layers = array_keys($graph->layerIndex());
        $layerCount = count($layers);

        /** @infection-ignore-all removing early return is safe; loop and transpose are no-ops for single layer */
        if ($layerCount < 2) {
            return;
        }

        $crossingCounter = new CrossingCounter();
        $bestCrossings = $crossingCounter->countAll($graph);
        $bestOrder = $this->captureLayerOrder($graph);

        /** @infection-ignore-all sweep bound/direction mutations are equivalent — algorithm converges via transpose */
        for ($sweep = 0; $sweep < $this->maxSweeps; ++$sweep) {
            if (0 === $sweep % 2) {
                for ($layerOffset = 1; $layerOffset < $layerCount; ++$layerOffset) {
                    $this->reorderLayer($graph, $layers[$layerOffset], $layers[$layerOffset - 1], downward: true);
                }
            } else {
                for ($layerOffset = $layerCount - 2; $layerOffset >= 0; --$layerOffset) {
                    $this->reorderLayer($graph, $layers[$layerOffset], $layers[$layerOffset + 1], downward: false);
                }
            }

            /** @infection-ignore-all convergence check timing is approximate; transpose compensates any miss */
            if (1 === $sweep % 2) {
                $crossings = $crossingCounter->countAll($graph);
                if ($crossings < $bestCrossings) {
                    $bestCrossings = $crossings;
                    $bestOrder = $this->captureLayerOrder($graph);
                } else {
                    $this->applyLayerOrder($graph, $bestOrder);
                    break; /** @infection-ignore-all continue vs break — extra sweeps don't worsen best order */
                }
            }
        }

        if ($this->transpose) {
            $this->transpose($graph, $layers);
        }
    }

    private function reorderLayer(LayoutGraph $graph, int $layer, int $fixedLayer, bool $downward): void
    {
        $nodeIds = $graph->layerIndex()[$layer];
        $fixedPosition = array_flip($graph->layerIndex()[$fixedLayer]);

        $medians = [];
        foreach ($nodeIds as $index => $nodeId) {
            $neighbors = $downward
                ? $graph->predecessors($nodeId)
                : $graph->successors($nodeId);

            $positions = [];
            foreach ($neighbors as $neighborId) {
                if (isset($fixedPosition[$neighborId])) {
                    $positions[] = $fixedPosition[$neighborId];
                }
            }

            /** @infection-ignore-all cast is for type consistency; PHP handles int/float comparison identically */
            $medians[$nodeId] = [] === $positions
                ? (float) $index
                : $this->median->value($positions);
        }

        usort($nodeIds, static fn (string $leftNodeId, string $rightNodeId): int => $medians[$leftNodeId] <=> $medians[$rightNodeId]);
        $graph->setLayerOrder($layer, $nodeIds);
    }

    /**
     * Adjacent-swap pass. Swapping two neighbouring nodes only changes crossings
     * among their own incident edges, so each candidate swap is scored by a local
     * O(deg·deg) delta instead of recounting the whole layer pair — the same
     * decisions as a full Barth–Mutzel–Jünger recount (now that CrossingCounter
     * no longer counts same-source fan-out), at a fraction of the cost.
     *
     * @param list<int> $layers
     */
    private function transpose(LayoutGraph $graph, array $layers): void
    {
        $layerCount = count($layers);
        $improved = true;

        while ($improved) {
            /** @infection-ignore-all resetting the flag is the termination condition; keeping it true only hangs and is detectable solely via timeout */
            $improved = false;

            for ($layerIndex = 0; $layerIndex < $layerCount; ++$layerIndex) {
                $upperPositions = $layerIndex > 0 ? array_flip($graph->layerIndex()[$layers[$layerIndex - 1]]) : [];
                $lowerPositions = $layerIndex < $layerCount - 1 ? array_flip($graph->layerIndex()[$layers[$layerIndex + 1]]) : [];

                $nodeIds = $graph->layerIndex()[$layers[$layerIndex]];
                $nodeCount = count($nodeIds);

                for ($nodeOffset = 0; $nodeOffset < $nodeCount - 1; ++$nodeOffset) {
                    $left = $nodeIds[$nodeOffset];
                    $right = $nodeIds[$nodeOffset + 1];

                    $crossingDifference = $this->swapCrossingDelta($graph->predecessors($left), $graph->predecessors($right), $upperPositions)
                        + $this->swapCrossingDelta($graph->successors($left), $graph->successors($right), $lowerPositions);

                    /** @infection-ignore-all accepting equal-delta swaps (or keeping a worse order) makes the swap loop oscillate forever; both mutants only hang and are detectable solely via timeout */
                    if ($crossingDifference < 0) {
                        [$nodeIds[$nodeOffset], $nodeIds[$nodeOffset + 1]] = [$nodeIds[$nodeOffset + 1], $nodeIds[$nodeOffset]];
                        /** @psalm-suppress ArgumentTypeCoercion swapping adjacent elements preserves list structure */
                        $graph->setLayerOrder($layers[$layerIndex], $nodeIds); // @phpstan-ignore argument.type
                        /** @infection-ignore-all single transpose pass processes all layers; reaches optimal for practical graphs */
                        $improved = true;
                    }
                }
            }
        }
    }

    /**
     * Net crossing change from swapping the left node with its right neighbour,
     * counted only over the edges the two send into one adjacent layer. Each pair
     * of edges that currently crosses (left edge lands right of the right edge)
     * is removed by the swap; each pair that currently does not starts to cross.
     * Edges that share an endpoint position never cross, so they cancel out.
     * Negative means the swap is a net win.
     *
     * @param list<string>       $leftNeighbors
     * @param list<string>       $rightNeighbors
     * @param array<string, int> $positions      neighbour id => position in the adjacent layer
     */
    private function swapCrossingDelta(array $leftNeighbors, array $rightNeighbors, array $positions): int
    {
        $crossingDifference = 0;
        foreach ($leftNeighbors as $leftNeighbor) {
            if (!isset($positions[$leftNeighbor])) {
                continue;
            }
            $leftPosition = $positions[$leftNeighbor];
            foreach ($rightNeighbors as $rightNeighbor) {
                if (!isset($positions[$rightNeighbor])) {
                    continue;
                }
                $rightPosition = $positions[$rightNeighbor];
                if ($leftPosition > $rightPosition) {
                    --$crossingDifference;
                } elseif ($leftPosition < $rightPosition) {
                    ++$crossingDifference;
                }
            }
        }

        return $crossingDifference;
    }

    /** @return array<int, list<string>> */
    private function captureLayerOrder(LayoutGraph $graph): array
    {
        return $graph->layerIndex();
    }

    /** @param array<int, list<string>> $order */
    private function applyLayerOrder(LayoutGraph $graph, array $order): void
    {
        foreach ($order as $layer => $nodeIds) {
            $graph->setLayerOrder($layer, $nodeIds);
        }
    }
}
