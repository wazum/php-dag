<?php

declare(strict_types=1);

namespace PhpDag\Layout;

final readonly class VerticalCompactor implements Processor
{
    public function __construct(
        private int $minimumVerticalSpacing = 2,
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        $layerIndex = $graph->layerIndex();
        $layers = array_keys($layerIndex);

        /** @infection-ignore-all removing the early return is safe; the layer loop is a no-op for fewer than two layers */
        if (count($layers) < 2) {
            return;
        }

        [$clusterTopLayers, $clusterBottomLayers] = LayerTransitions::clusterBoundaryLayers($graph);

        for ($layerOffset = 0, $layerCount = count($layers); $layerOffset < $layerCount - 1; ++$layerOffset) {
            /** @infection-ignore-all init value is consumed by max(); every node bottom row is positive */
            $maxBottomRow = 0;
            foreach ($layerIndex[$layers[$layerOffset]] as $nodeId) {
                $node = $graph->getLayoutNode($nodeId);
                $maxBottomRow = max($maxBottomRow, $node->row + $node->boxHeight());
            }

            $minTopRow = PHP_INT_MAX;
            foreach ($layerIndex[$layers[$layerOffset + 1]] as $nodeId) {
                $minTopRow = min($minTopRow, $graph->getLayoutNode($nodeId)->row);
            }

            $requiredSpacing = $this->minimumVerticalSpacing
                + (LayerTransitions::hasBendingEdge($graph, $layers[$layerOffset]) ? 1 : 0)
                + LayerTransitions::clusterBorderAllowance($layers[$layerOffset], $layers[$layerOffset + 1], $clusterTopLayers, $clusterBottomLayers);
            $excess = ($minTopRow - $maxBottomRow) - $requiredSpacing;

            /** @infection-ignore-all shifting by a zero excess is a no-op; > 0 and >= 0 are equivalent */
            if ($excess > 0) {
                for ($followingLayerOffset = $layerOffset + 1; $followingLayerOffset < $layerCount; ++$followingLayerOffset) {
                    foreach ($layerIndex[$layers[$followingLayerOffset]] as $nodeId) {
                        $graph->getLayoutNode($nodeId)->row -= $excess;
                    }
                }
            }
        }
    }
}
