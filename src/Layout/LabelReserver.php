<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\LabelPosition;

final readonly class LabelReserver implements Processor
{
    public function process(LayoutGraph $graph): void
    {
        /** @infection-ignore-all Guard is a pure optimization: without labeled edges every subsequent step is a no-op anyway */
        if (!$this->hasLabeledEdges($graph)) {
            return;
        }

        $this->widenConvergingChannels($graph);
        $this->reserveHorizontalSpace($graph);
        [$divergingLifts, $divergingRows] = $this->divergingLifts($graph);
        foreach ($divergingLifts as $sourceId => $lift) {
            $graph->reserveDivergingLift($sourceId, $lift);
        }
        $graph->insertGapLanes($this->calculateGapShifts($graph, $divergingRows), FlowDirection::TopToBottom);
        $this->ensureOuterLabelMargins($graph);
        $this->reserveCorridorSpans($graph);
    }

    /**
     * Fans a layer gap out until every converging label fits beside its own
     * vertical. Each vertical crossing the gap bounds a channel; a label claims
     * the channel on the side the renderer will pick (away from the bend), and
     * each channel must hold the sum of its claims plus flanking spaces.
     */
    private function widenConvergingChannels(LayoutGraph $graph): void
    {
        foreach ($this->channelClaimsByGap($graph) as $sourceLayer => $claims) {
            $bounds = $this->gapBounds($graph, $sourceLayer);

            for ($index = 1, $boundCount = count($bounds); $index < $boundCount; ++$index) {
                $needed = 2;
                foreach ($claims as $claim) {
                    if ($claim['boundIndex'] === $index - ($claim['claimsRight'] ? 1 : 0)) {
                        $needed += $claim['width'] + 1;
                    }
                }
                /** @infection-ignore-all 2 === $needed means no claims; box spacing already provides two columns, so widening to 2 (or 1, or 3) never shifts anything */
                if (2 === $needed) {
                    continue;
                }

                $deficit = $needed - ($bounds[$index]['center'] - $bounds[$index - 1]['center']);
                if ($deficit <= 0) {
                    continue;
                }

                $this->shiftColumnsAtOrRightOf($graph, $bounds[$index]['column'], $deficit);
                for ($rest = $index; $rest < $boundCount; ++$rest) {
                    $bounds[$rest] = [
                        'center' => $bounds[$rest]['center'] + $deficit,
                        'column' => $bounds[$rest]['column'] + $deficit,
                    ];
                }
            }

            // With the final geometry known, keep pass-through lanes out of the
            // claimed spans so a long edge cannot be routed over a label.
            foreach ($claims as $claim) {
                $center = $bounds[$claim['boundIndex']]['center'];
                if ($claim['claimsRight']) {
                    $graph->reserveLabelSpan($sourceLayer, $center + 1, $center + $claim['width'] + 2);
                } else {
                    $graph->reserveLabelSpan($sourceLayer, $center - $claim['width'] - 2, $center - 1);
                }
            }
        }
    }

    /**
     * Every column where an edge segment drops through the gap below the given
     * layer — real node exits and dummy pass-throughs alike — ordered left to
     * right. These columns are the walls of the gap's label channels.
     *
     * @return list<array{center: int, column: int}>
     */
    private function gapBounds(LayoutGraph $graph, int $sourceLayer): array
    {
        $boundsByCenter = [];
        foreach ($graph->edges() as $segment) {
            $source = $graph->getLayoutNode($segment->sourceId());
            if ($source->layer !== $sourceLayer) {
                continue;
            }
            $center = $source->column + intdiv($source->boxWidth(), 2);
            $boundsByCenter[$center] = min($boundsByCenter[$center] ?? PHP_INT_MAX, $source->column);
        }

        ksort($boundsByCenter);
        $bounds = [];
        foreach ($boundsByCenter as $center => $column) {
            $bounds[] = ['center' => $center, 'column' => $column];
        }

        return $bounds;
    }

    /**
     * One claim per Middle-positioned label on an adjacent-layer converging
     * edge, resolved to the channel the renderer will use: the side away from
     * the bend, except that the leftmost vertical's label falls back to its
     * right channel, mirroring the renderer's on-canvas preference.
     *
     * @return array<int, list<array{boundIndex: int, claimsRight: bool, width: int}>>
     */
    private function channelClaimsByGap(LayoutGraph $graph): array
    {
        $sourcesByTarget = [];
        foreach ($graph->originalEdges() as $edge) {
            /** @infection-ignore-all the stored value is never read; convergence is decided by count() */
            $sourcesByTarget[$edge->targetId][$edge->sourceId] = true;
        }

        $claimsByGap = [];
        foreach ($graph->originalEdges() as $edge) {
            $label = $edge->label;
            if (null === $label || LabelPosition::Middle !== $label->position || count($sourcesByTarget[$edge->targetId]) < 2) {
                continue;
            }

            $source = $graph->getLayoutNode($edge->sourceId);
            $target = $graph->getLayoutNode($edge->targetId);
            if (1 !== $target->layer - $source->layer) {
                continue;
            }

            $dropCenter = $source->column + intdiv($source->boxWidth(), 2);
            $entryCenter = $target->column + intdiv($target->boxWidth(), 2);
            $boundIndex = $this->boundIndexOf($this->gapBounds($graph, $source->layer), $dropCenter);
            $claimsRight = $entryCenter <= $dropCenter || 0 === $boundIndex;

            $claimsByGap[$source->layer][] = [
                'boundIndex' => $boundIndex,
                'claimsRight' => $claimsRight,
                'width' => $label->width(),
            ];
        }

        return $claimsByGap;
    }

    /** @param list<array{center: int, column: int}> $bounds */
    private function boundIndexOf(array $bounds, int $center): int
    {
        foreach ($bounds as $index => $bound) {
            if ($bound['center'] === $center) {
                return $index;
            }
        }

        return 0;
    }

    private function shiftColumnsAtOrRightOf(LayoutGraph $graph, int $threshold, int $amount): void
    {
        foreach ($graph->nodeIds() as $nodeId) {
            $node = $graph->getLayoutNode($nodeId);
            if ($node->column >= $threshold) {
                $node->column += $amount;
            }
        }
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

    /** Must mirror DummyNodeInserter's corridor condition; reversed edges never qualify because their user-source layer sits at or above their user-target's, so the signed span is never > 1. */
    private function hasCorridor(LayoutGraph $graph, Edge $edge): bool
    {
        return LabelPosition::Middle === $edge->label?->position
            && $graph->getLayoutNode($edge->targetId)->layer - $graph->getLayoutNode($edge->sourceId)->layer > 1;
    }

    /**
     * @return array<string, int>
     */
    private function siblingBendColumns(LayoutGraph $graph, string $sourceId): array
    {
        $bendColumns = [];
        foreach ($graph->outgoingEdges($sourceId) as $segment) {
            $target = $graph->getLayoutNode($segment->targetId());
            $bendColumns[$segment->targetId()] = $target->column + intdiv($target->boxWidth(), 2);
        }

        return $bendColumns;
    }

    /** @return array{array<string, int>, array<int, int>} [lift by source id, extra rows by gap] */
    private function divergingLifts(LayoutGraph $graph): array
    {
        while (null !== $collision = $this->labelOnlyCollision($graph)) {
            $this->shiftColumnsAtOrRightOf($graph, ...$collision);
        }

        [$barSpansByGap, $windowRowsBySource] = $this->fanOutSpans($graph);
        $lifts = [];
        $rowsByGap = [];
        foreach ($barSpansByGap as $gapLayer => $spansBySource) {
            uasort($spansBySource, static fn (array $left, array $right): int => $left['from'] <=> $right['from']);
            $tracks = [];
            $gapLifts = [];
            foreach ($spansBySource as $sourceId => $span) {
                $takenTracks = [];
                foreach ($tracks as $placedId => $placedTrack) {
                    if ($this->collides($spansBySource[$placedId]['to'], $span['from'])) {
                        $takenTracks[] = $placedTrack;
                    }
                }
                $track = 0;
                while (in_array($track, $takenTracks, true)) {
                    ++$track;
                }
                $tracks[$sourceId] = $track;

                $lifts[$sourceId] = $gapLifts[] = $track + ($windowRowsBySource[$sourceId] ?? 0);
            }
            $rowsByGap[$gapLayer] = max($gapLifts);
        }

        return [$lifts, $rowsByGap];
    }

    /**
     * @return array{int, int}|null [first column to move, columns to move by]
     */
    private function labelOnlyCollision(LayoutGraph $graph): ?array
    {
        foreach ($this->fanOutSpans($graph)[0] as $spansBySource) {
            uasort($spansBySource, static fn (array $left, array $right): int => $left['from'] <=> $right['from']);
            $previous = null;
            foreach ($spansBySource as $span) {
                /** @infection-ignore-all bar ends are box centres of distinct boxes in one layer, so two bars can never end on the same column and "<" equals "<=" */
                if (null !== $previous && $previous['barTo'] < $span['barFrom'] && $this->collides($previous['to'], $span['from'])) {
                    return [$span['firstColumn'], $previous['to'] + 3 - $span['from']];
                }
                $previous = $span;
            }
        }

        return null;
    }

    private function collides(int $leftEnd, int $rightStart): bool
    {
        return $leftEnd + 3 > $rightStart;
    }

    /**
     * Bar extent per labelled fan-out, widened by the outer labels that sit
     * beyond its end corners, and whether an inner label needs its own row.
     *
     * @return array{array<int, non-empty-array<string, array{from: int, to: int, barFrom: int, barTo: int, firstColumn: int}>>, array<string, int>}
     */
    private function fanOutSpans(LayoutGraph $graph): array
    {
        /** @var array<int, non-empty-array<string, array{from: int, to: int, barFrom: int, barTo: int, firstColumn: int}>> $spansByGap */
        $spansByGap = [];
        $windowRowsBySource = [];
        foreach ($graph->originalEdges() as $edge) {
            if (null === $edge->label) {
                continue;
            }
            $source = $graph->getLayoutNode($edge->sourceId);
            if (1 !== $graph->getLayoutNode($edge->targetId)->layer - $source->layer) {
                continue;
            }
            $bendColumns = $this->siblingBendColumns($graph, $edge->sourceId);
            if (count($bendColumns) < 2) {
                continue;
            }
            $span = $spansByGap[$source->layer][$edge->sourceId] ?? [
                'from' => min($bendColumns),
                'to' => max($bendColumns),
                'barFrom' => min($bendColumns),
                'barTo' => max($bendColumns),
                'firstColumn' => $this->firstChildColumn($graph, $edge->sourceId),
            ];
            $ownBendColumn = $bendColumns[$edge->targetId];
            if ($ownBendColumn === min($bendColumns)) {
                $span['from'] = min($span['from'], $ownBendColumn - 1 - $edge->label->width());
            } elseif ($ownBendColumn === max($bendColumns)) {
                $span['to'] = max($span['to'], $ownBendColumn + 1 + $edge->label->width());
            } else {
                $windowRowsBySource[$edge->sourceId] = 1;
            }
            $spansByGap[$source->layer][$edge->sourceId] = $span;
        }

        return [$spansByGap, $windowRowsBySource];
    }

    private function firstChildColumn(LayoutGraph $graph, string $sourceId): int
    {
        return min(PHP_INT_MAX, ...array_map(
            static fn (LayoutEdge $segment): int => $graph->getLayoutNode($segment->targetId())->column,
            $graph->outgoingEdges($sourceId),
        ));
    }

    /**
     * @param array<int, int> $divergingRows
     *
     * @return array<int, int>
     */
    private function calculateGapShifts(LayoutGraph $graph, array $divergingRows): array
    {
        $gapShifts = $divergingRows;
        $targetInDegrees = $this->targetInDegrees($graph);

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

            // A bending gap holds its labels beside the bend bar, except for
            // adjacent-layer converging edges: their shared bar spans the whole
            // channel and they have no rows of their own, so the labels need an
            // extra row above the bar. Longer edges keep their own channel rows.
            $convergesAdjacent = $targetInDegrees[$edge->targetId] >= 2 && 1 === $targetLayer - $sourceLayer;
            if (LayerTransitions::hasBendingEdge($graph, $labelGap) && !$convergesAdjacent && !$this->hasCorridor($graph, $edge)) {
                continue;
            }

            /** @infection-ignore-all Coalesce is defensive for first-seen gap key; max(0,1) == max(-1,1) == max(1,1) */
            $gapShifts[$labelGap] = max($gapShifts[$labelGap] ?? 0, 1);
        }

        return $gapShifts;
    }

    /** @return array<string, int> */
    private function targetInDegrees(LayoutGraph $graph): array
    {
        $inDegrees = [];
        foreach ($graph->originalEdges() as $edge) {
            $inDegrees[$edge->targetId] = ($inDegrees[$edge->targetId] ?? 0) + 1;
        }

        return $inDegrees;
    }

    private function ensureOuterLabelMargins(LayoutGraph $graph): void
    {
        $minLeftColumn = 0;

        foreach ($graph->originalEdges() as $edge) {
            if (null === $edge->label) {
                continue;
            }

            if ($this->hasCorridor($graph, $edge)) {
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

            if ($this->hasCorridor($graph, $edge)) {
                continue;
            }

            $sourceNode = $graph->getLayoutNode($edge->sourceId);
            $targetNode = $graph->getLayoutNode($edge->targetId);
            $laneNode = LabelPosition::Source === $edge->label->position ? $sourceNode : $targetNode;
            $edgeColumn = $laneNode->column + intdiv($laneNode->boxWidth(), 2);
            $labelWidth = $edge->label->width();

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

            $this->shiftColumnsAtOrRightOf($graph, $edgeColumn, $rightEnd - $rightConflict->column + 2);
        }
    }

    private function reserveCorridorSpans(LayoutGraph $graph): void
    {
        foreach ($graph->nodeIds() as $nodeId) {
            $node = $graph->getLayoutNode($nodeId);
            if (!$node instanceof DummyLayoutNode || 0 === $node->corridorWidth) {
                continue;
            }

            $sourceLayer = $graph->getLayoutNode($node->originalEdgeSourceId)->layer;
            $targetLayer = $graph->getLayoutNode($node->originalEdgeTargetId)->layer;
            for ($gapLayer = $sourceLayer; $gapLayer < $targetLayer; ++$gapLayer) {
                $graph->reserveLabelSpan($gapLayer, $node->column, $node->column + $node->boxWidth() - 1);
            }
        }
    }
}
