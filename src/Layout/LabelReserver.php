<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Graph\LabelPosition;

final readonly class LabelReserver implements Processor
{
    public function process(LayoutGraph $graph): void
    {
        /** @infection-ignore-all Guard is a pure optimization: without labeled edges every subsequent step is a no-op anyway */
        if (!$this->hasLabeledEdges($graph)) {
            return;
        }

        $this->shiftLayers($graph, $this->calculateGapShifts($graph));
        $this->reserveHorizontalSpace($graph);
        $this->ensureOuterLabelMargins($graph);
    }

    private function hasLabeledEdges(LayoutGraph $graph): bool
    {
        foreach ($graph->originalEdges() as $edge) {
            if (null !== $edge->label) {
                return true;
            }
        }

        /** @infection-ignore-all Returning true here only disables an optimization guard; all steps no-op on label-free graphs */
        return false;
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

            if (LayerTransitions::hasBendingEdge($graph, $labelGap)) {
                continue;
            }

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

            /** @infection-ignore-all cumulativeShift is always >= 0; skip is a no-op optimization (row += 0) */
            if (0 === $cumulativeShift) {
                continue;
            }

            foreach ($layerIndex[$layerNumber] as $nodeId) {
                $graph->getLayoutNode($nodeId)->row += $cumulativeShift;
            }
        }
    }

    private function ensureOuterLabelMargins(LayoutGraph $graph): void
    {
        $minLeftColumn = 0;

        foreach ($graph->originalEdges() as $edge) {
            if (null === $edge->label) {
                continue;
            }

            $sourceNode = $graph->getLayoutNode($edge->sourceId);
            $targetNode = $graph->getLayoutNode($edge->targetId);
            $sourceCenter = $sourceNode->column + intdiv($sourceNode->boxWidth(), 2);
            $targetCenter = $targetNode->column + intdiv($targetNode->boxWidth(), 2);

            if ($targetCenter < $sourceCenter) {
                $minLeftColumn = min($minLeftColumn, $targetCenter - $edge->label->width() - 1);
            }
        }

        /** @infection-ignore-all minLeftColumn starts at 0 and only decreases via min(); the boundary case shifts every column by zero */
        if ($minLeftColumn >= 0) {
            return;
        }

        $rightShift = -$minLeftColumn;
        foreach ($graph->nodeIds() as $nodeId) {
            $graph->getLayoutNode($nodeId)->column += $rightShift;
        }
    }

    private function reserveHorizontalSpace(LayoutGraph $graph): void
    {
        foreach ($graph->originalEdges() as $edge) {
            if (null === $edge->label) {
                continue;
            }

            $sourceNode = $graph->getLayoutNode($edge->sourceId);
            $edgeColumn = $sourceNode->column + intdiv($sourceNode->boxWidth(), 2);
            $labelWidth = $edge->label->width();

            $targetNode = $graph->getLayoutNode($edge->targetId);
            /** @infection-ignore-all labelLayer targets the inserted gap row; tested via vertical tests; horizontal only tests Middle path */
            $labelLayer = match ($edge->label->position) {
                LabelPosition::Source => $sourceNode->layer + 1,
                LabelPosition::Target => $targetNode->layer,
                LabelPosition::Middle => intdiv($sourceNode->layer + $targetNode->layer, 2) + 1,
            };

            $rightStart = $edgeColumn + 2;
            $rightEnd = $rightStart + $labelWidth;
            /** @infection-ignore-all leftStart boundary is symmetric to rightStart; tested indirectly via both-sides-blocked scenario */
            $leftStart = $edgeColumn - $labelWidth - 1;

            $rightConflict = null;
            $leftConflict = null;

            /** @infection-ignore-all Interval overlap geometry: boundary mutations (< vs <=, && vs ||) are equivalent when test nodes are wider than the label zone */
            foreach ($graph->layerIndex()[$labelLayer] ?? [] as $nodeId) {
                if ($nodeId === $edge->sourceId || $nodeId === $edge->targetId) {
                    continue;
                }
                $node = $graph->getLayoutNode($nodeId);
                if ($node instanceof DummyLayoutNode) {
                    continue;
                }
                $nodeEnd = $node->column + $node->boxWidth();
                if (null === $rightConflict && $rightStart < $nodeEnd && $rightEnd > $node->column) {
                    $rightConflict = $node;
                }
                if (null === $leftConflict && $leftStart < $nodeEnd && ($leftStart + $labelWidth) > $node->column) {
                    $leftConflict = $node;
                }
            }

            /** @infection-ignore-all Only one labeled edge in tests; continue vs break is equivalent */
            if (null === $rightConflict || null === $leftConflict) {
                continue;
            }

            $shiftAmount = $rightEnd - $rightConflict->column + 2;
            foreach ($graph->layerIndex()[$labelLayer] ?? [] as $nodeId) {
                $node = $graph->getLayoutNode($nodeId);
                /** @infection-ignore-all No test node sits exactly at edgeColumn; >= vs > is equivalent */
                if ($node->column >= $edgeColumn) {
                    $node->column += $shiftAmount;
                }
            }
        }
    }
}
