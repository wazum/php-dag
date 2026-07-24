<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Graph\LabelPosition;

final readonly class LeftToRightLabelReserver implements Processor
{
    public function process(LayoutGraph $graph): void
    {
        $gapShifts = $this->calculateGapShifts($graph);

        /** @infection-ignore-all Guard is a pure optimization: empty gapShifts means no labeled edges, so the remaining steps are no-ops */
        if ([] === $gapShifts) {
            return;
        }

        $this->shiftLayers($graph, $gapShifts);
        $this->ensureOuterLabelMargins($graph);
    }

    /** @return array<int, int> */
    private function calculateGapShifts(LayoutGraph $graph): array
    {
        $gapShifts = [];

        foreach ($graph->originalEdges() as $edge) {
            if (null === $edge->label) {
                continue;
            }

            $sourceLayer = $graph->getLayoutNode($edge->sourceId)->layer;
            $targetLayer = $graph->getLayoutNode($edge->targetId)->layer;

            $labelGap = match ($edge->label->position) {
                LabelPosition::Source => $sourceLayer,
                LabelPosition::Target => $targetLayer - 1,
                LabelPosition::Middle => intdiv($sourceLayer + $targetLayer, 2),
            };

            /** @infection-ignore-all Coalesce is defensive for first-seen gap key; max(0,1) == max(-1,1) == max(1,1) */
            $gapShifts[$labelGap] = max($gapShifts[$labelGap] ?? 0, 1);
        }

        return $gapShifts;
    }

    /** @param array<int, int> $gapShifts */
    private function shiftLayers(LayoutGraph $graph, array $gapShifts): void
    {
        $layerIndex = $graph->layerIndex();
        $layerNumbers = array_keys($layerIndex);
        /** @infection-ignore-all layerIndex() already returns ksorted keys; sort is defensive */
        sort($layerNumbers);

        $cumulativeShift = 0;
        foreach ($layerNumbers as $layerNumber) {
            /** @infection-ignore-all No gap exists before the first layer; >= adds gapShifts[-1] ?? 0 which is 0 */
            if ($layerNumber > $layerNumbers[0]) {
                $cumulativeShift += $gapShifts[$layerNumber - 1] ?? 0;
            }

            /** @infection-ignore-all cumulativeShift is always >= 0; skip is a no-op optimization (column += 0) */
            if (0 === $cumulativeShift) {
                continue;
            }

            foreach ($layerIndex[$layerNumber] as $nodeId) {
                $graph->getLayoutNode($nodeId)->column += $cumulativeShift;
            }
        }
    }

    private function ensureOuterLabelMargins(LayoutGraph $graph): void
    {
        $minTopRow = 0;

        foreach ($graph->originalEdges() as $edge) {
            if (null === $edge->label) {
                continue;
            }

            $sourceNode = $graph->getLayoutNode($edge->sourceId);
            $targetNode = $graph->getLayoutNode($edge->targetId);
            $sourceCenter = $sourceNode->row + intdiv($sourceNode->boxHeight(), 2);
            $targetCenter = $targetNode->row + intdiv($targetNode->boxHeight(), 2);

            if ($targetCenter < $sourceCenter) {
                // Labels occupy a single row rendered above the upward
                // target's box; one row of headroom keeps it on the canvas.
                $minTopRow = min($minTopRow, $targetNode->row - 1);
            }
        }

        /** @infection-ignore-all minTopRow starts at 0 and only decreases via min(); the boundary case shifts every row by zero */
        if ($minTopRow >= 0) {
            return;
        }

        $downShift = -$minTopRow;
        foreach ($graph->nodeIds() as $nodeId) {
            $graph->getLayoutNode($nodeId)->row += $downShift;
        }
    }
}
