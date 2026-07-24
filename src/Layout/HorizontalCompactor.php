<?php

declare(strict_types=1);

namespace PhpDag\Layout;

final readonly class HorizontalCompactor implements Processor
{
    public function __construct(
        private int $minimumHorizontalSpacing = 2,
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        $layerIndex = $graph->layerIndex();
        $layers = array_keys($layerIndex);

        if (count($layers) < 2) {
            return;
        }

        [$clusterFirstLayers, $clusterLastLayers] = LayerTransitions::clusterBoundaryLayers($graph);

        for ($layerOffset = 0, $layerCount = count($layers); $layerOffset < $layerCount - 1; ++$layerOffset) {
            /** @infection-ignore-all starting at -1 is equivalent: max() with box widths >= 1 always wins */
            $maxRightColumn = 0;
            foreach ($layerIndex[$layers[$layerOffset]] as $nodeId) {
                $node = $graph->getLayoutNode($nodeId);
                // A self-loop draws a lane one column past the node's right edge
                // (SelfLoopRouter); count it so the next layer is not compacted
                // onto the loop.
                $rightEdge = $node->column + $node->boxWidth() + $this->selfLoopLaneWidth($graph, $nodeId);
                $maxRightColumn = max($maxRightColumn, $rightEdge);
            }

            $minLeftColumn = PHP_INT_MAX;
            foreach ($layerIndex[$layers[$layerOffset + 1]] as $nodeId) {
                $minLeftColumn = min($minLeftColumn, $graph->getLayoutNode($nodeId)->column);
            }

            /** @infection-ignore-all bend allowance can only be reclaimed, never added: the positioner emits exactly the required spacing, so a larger requirement is a no-op */
            $requiredSpacing = $this->minimumHorizontalSpacing
                + (LayerTransitions::hasBendingEdgeLeftToRight($graph, $layers[$layerOffset]) ? 1 : 0)
                + LayerTransitions::clusterBorderAllowance($layers[$layerOffset], $layers[$layerOffset + 1], $clusterFirstLayers, $clusterLastLayers);
            $excess = ($minLeftColumn - $maxRightColumn) - $requiredSpacing;

            /** @infection-ignore-all shifting by an excess of zero is a no-op */
            if ($excess > 0) {
                for ($followingLayerOffset = $layerOffset + 1; $followingLayerOffset < $layerCount; ++$followingLayerOffset) {
                    foreach ($layerIndex[$layers[$followingLayerOffset]] as $nodeId) {
                        $graph->getLayoutNode($nodeId)->column -= $excess;
                    }
                }
            }
        }
    }

    private function selfLoopLaneWidth(LayoutGraph $graph, string $nodeId): int
    {
        foreach ($graph->selfLoops() as $loop) {
            if ($loop->edge->sourceId === $nodeId) {
                return 2;
            }
        }

        return 0;
    }
}
