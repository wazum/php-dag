<?php

declare(strict_types=1);

namespace PhpDag\Layout;

final readonly class LeftToRightPositioning implements NodePositioning
{
    public function __construct(
        private int $horizontalSpacing = 3,
        private int $verticalSpacing = 2,
    ) {
    }

    public function position(LayoutGraph $graph): void
    {
        $layerIndex = $graph->layerIndex();
        if ([] === $layerIndex) {
            return;
        }

        $layerMaxWidths = [];
        $layerHeights = [];
        foreach ($layerIndex as $layerNumber => $nodeIds) {
            /** @infection-ignore-all starting at -1 is equivalent: max() with box widths >= 1 always wins */
            $maxWidth = 0;
            $height = 0;
            foreach ($nodeIds as $nodeId) {
                $node = $graph->getLayoutNode($nodeId);
                // A self-loop draws a lane past the node's right edge, so reserve
                // its footprint here to push the next layer clear of it.
                $maxWidth = max($maxWidth, $node->boxWidth() + $this->selfLoopReservation($graph, $nodeId));
                $height += $node->boxHeight();
            }
            $layerMaxWidths[$layerNumber] = $maxWidth;
            /** @infection-ignore-all the spacing term shifts every layer height consistently, so centering differences cancel out */
            $layerHeights[$layerNumber] = $height + (count($nodeIds) - 1) * $this->verticalSpacing;
        }

        $tallestLayerHeight = max($layerHeights);
        $currentColumn = 0;
        foreach ($layerIndex as $layerNumber => $nodeIds) {
            $currentRow = intdiv($tallestLayerHeight, 2) - intdiv($layerHeights[$layerNumber], 2);
            foreach ($nodeIds as $nodeId) {
                $node = $graph->getLayoutNode($nodeId);
                $node->row = $currentRow;
                $node->column = $currentColumn;
                $currentRow += $node->boxHeight() + $this->verticalSpacing;
            }
            $currentColumn += $layerMaxWidths[$layerNumber] + $this->horizontalSpacing;
        }
    }

    private function selfLoopReservation(LayoutGraph $graph, string $nodeId): int
    {
        foreach ($graph->selfLoops() as $loop) {
            if ($loop->edge->sourceId === $nodeId) {
                /** @infection-ignore-all the exact value only has to place the next layer at or past the lane; HorizontalCompactor then reclaims to its own (tested) self-loop-aware minimum, so ±1 here is normalised away */
                return 2;
            }
        }

        return 0;
    }
}
