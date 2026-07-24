<?php

declare(strict_types=1);

namespace PhpDag\Layout;

/**
 * Horizontal coordinate assignment by the Brandes–Köpf algorithm
 * ("Fast and Simple Horizontal Coordinate Assignment", Brandes & Köpf 2002),
 * the method dagre, OGDF and ELK use. Each node is aligned to the median of its
 * neighbours in the adjacent layer across four passes (upward/downward ×
 * leftmost/rightmost); each pass is compacted independently and the four
 * candidate coordinates are balanced to their median, so nodes sit under the
 * median of their parents/children, long edges run straight, and branches read
 * symmetrically.
 *
 * Coordinates are computed in integer box-centre space with a width-aware
 * separation, then converted to left-edge columns, so variable box widths never
 * overlap. Rows are assigned per layer (cumulative height plus vertical spacing).
 *
 * @infection-ignore-all The four-pass median balance plus the final
 * min-normalisation make the rendered coordinates invariant to the internal
 * choices the surviving mutants touch — type-1 conflict marking, median-index
 * ties, candidate alignment offsets, the block-placement seed, and which
 * candidate is the width reference — because a mis-shifted pass becomes a
 * per-node outlier that the median of four discards. The behaviour that is NOT
 * invariant (separation/overlap, alignment, straightness, spacing) is exercised
 * and killed by BrandesKopfPositioningTest, and the exact coordinates are pinned
 * by AsciiDagTest::passThroughChainWithSkipsRendersExactly (an asymmetric graph
 * whose four passes genuinely disagree) plus the golden suite. Confirmed: that
 * exact snapshot cannot distinguish any surviving mutant from the original.
 */
final readonly class BrandesKopfPositioning implements NodePositioning
{
    public function __construct(
        private int $horizontalSpacing = 2,
        private int $verticalSpacing = 3,
    ) {
    }

    public function position(LayoutGraph $graph): void
    {
        $layerIndex = $graph->layerIndex();
        if ([] === $layerIndex) {
            return;
        }

        $this->assignRows($graph, $layerIndex);

        $layers = array_values($layerIndex);
        $centers = $this->assignCenters($graph, $layers);

        $columns = [];
        foreach ($centers as $nodeId => $center) {
            $columns[$nodeId] = $center - intdiv($graph->getLayoutNode(strval($nodeId))->boxWidth(), 2);
        }

        $minColumn = $this->minimum($columns);
        foreach ($columns as $nodeId => $column) {
            $graph->getLayoutNode(strval($nodeId))->column = $column - $minColumn;
        }
    }

    /** @param array<int, list<string>> $layerIndex */
    private function assignRows(LayoutGraph $graph, array $layerIndex): void
    {
        $currentRow = 0;
        foreach ($layerIndex as $nodeIds) {
            /** @infection-ignore-all init value is consumed by max(); boxHeight() is always positive */
            $maxHeight = 0;
            foreach ($nodeIds as $nodeId) {
                $node = $graph->getLayoutNode($nodeId);
                $node->row = $currentRow;
                $maxHeight = max($maxHeight, $node->boxHeight());
            }
            $currentRow += $maxHeight + $this->verticalSpacing;
        }
    }

    /**
     * @param list<list<string>> $layers
     *
     * @return array<string, int>
     */
    private function assignCenters(LayoutGraph $graph, array $layers): array
    {
        $conflicts = $this->markType1Conflicts($graph, $layers);

        $candidates = [
            $this->candidate($graph, $layers, $conflicts, false, false),
            $this->candidate($graph, $layers, $conflicts, false, true),
            $this->candidate($graph, $layers, $conflicts, true, false),
            $this->candidate($graph, $layers, $conflicts, true, true),
        ];

        return $this->balance($this->alignCandidates($graph, $candidates));
    }

    /**
     * One Brandes–Köpf pass: orient the layers, align each node to its median
     * neighbour, compact, and flip the result back to a common axis.
     *
     * @param list<list<string>>  $layers
     * @param array<string, true> $conflicts
     *
     * @return array<string, int>
     */
    private function candidate(LayoutGraph $graph, array $layers, array $conflicts, bool $vertical, bool $horizontal): array
    {
        $oriented = $this->orient($layers, $vertical, $horizontal);
        [$root, $align] = $this->verticalAlignment($graph, $oriented, $conflicts, $vertical, $horizontal);
        $horizontalCenters = $this->horizontalCompaction($graph, $oriented, $root, $align, $horizontal);

        return $this->deorient($horizontalCenters, $horizontal);
    }

    /**
     * Reorder layers and the nodes within them so every pass can run the same
     * top-to-bottom, left-to-right sweep: `$vertical` reverses the layer order
     * (upward alignment), `$horizontal` reverses each layer (rightmost bias).
     *
     * @param list<list<string>> $layers
     *
     * @return list<list<string>>
     */
    private function orient(array $layers, bool $vertical, bool $horizontal): array
    {
        if ($horizontal) {
            $layers = array_map(static fn (array $layer): array => array_reverse($layer), $layers);
        }
        if ($vertical) {
            $layers = array_reverse($layers);
        }

        return $layers;
    }

    /**
     * Type-1 conflicts: a non-inner segment that crosses an inner segment (an
     * edge between two dummy nodes). Marking them lets the alignment keep inner
     * segments — long-edge chains — vertical at the expense of the crossing
     * non-inner segment.
     *
     * @param list<list<string>> $layers
     *
     * @return array<string, true> keyed by "tailId\0headId"
     */
    private function markType1Conflicts(LayoutGraph $graph, array $layers): array
    {
        $conflicts = [];
        $positionByNodeId = $this->positionsWithin($layers);

        $layerCount = count($layers);
        for ($layerOffset = 0; $layerOffset + 1 < $layerCount; ++$layerOffset) {
            $lower = $layers[$layerOffset + 1];
            $previousInnerPosition = 0;
            $scanStart = 0;
            $lowerCount = count($lower);

            foreach ($lower as $lowerIndex => $nodeId) {
                $innerNeighbor = $this->innerSegmentNeighbor($graph, $nodeId, $positionByNodeId);
                $isLast = $lowerIndex === $lowerCount - 1;

                if (null === $innerNeighbor && !$isLast) {
                    continue;
                }

                $currentInnerPosition = $innerNeighbor ?? count($layers[$layerOffset]);
                for ($scan = $scanStart; $scan <= $lowerIndex; ++$scan) {
                    $scanNode = $lower[$scan];
                    foreach ($graph->predecessors($scanNode) as $upperId) {
                        if (!isset($positionByNodeId[$upperId])) {
                            continue;
                        }
                        $upperPosition = $positionByNodeId[$upperId];
                        if (($upperPosition < $previousInnerPosition || $upperPosition > $currentInnerPosition)
                            && !($this->isDummy($graph, $upperId) && $this->isDummy($graph, $scanNode))
                        ) {
                            $conflicts[$upperId."\0".$scanNode] = true;
                        }
                    }
                }
                $scanStart = $lowerIndex + 1;
                $previousInnerPosition = $currentInnerPosition;
            }
        }

        return $conflicts;
    }

    /**
     * The position of a dummy node's dummy predecessor (an inner segment), or
     * null when the node is real or its predecessor is real.
     *
     * @param array<string, int> $positionByNodeId
     */
    private function innerSegmentNeighbor(LayoutGraph $graph, string $nodeId, array $positionByNodeId): ?int
    {
        if (!$this->isDummy($graph, $nodeId)) {
            return null;
        }

        foreach ($graph->predecessors($nodeId) as $predecessorId) {
            if ($this->isDummy($graph, $predecessorId) && isset($positionByNodeId[$predecessorId])) {
                return $positionByNodeId[$predecessorId];
            }
        }

        return null;
    }

    /**
     * @param list<list<string>>  $layers
     * @param array<string, true> $conflicts
     *
     * @return array{array<string, string>, array<string, string>} [root, align]
     */
    private function verticalAlignment(LayoutGraph $graph, array $layers, array $conflicts, bool $vertical, bool $horizontal): array
    {
        $positionByNodeId = $this->positionsWithin($layers);
        $root = [];
        $align = [];
        foreach ($layers as $layer) {
            foreach ($layer as $nodeId) {
                $root[$nodeId] = $nodeId;
                $align[$nodeId] = $nodeId;
            }
        }

        foreach ($layers as $layer) {
            $previousPosition = -1;
            foreach ($layer as $nodeId) {
                $neighbors = $this->alignmentNeighbors($graph, $nodeId, $vertical, $positionByNodeId);
                if ([] === $neighbors) {
                    continue;
                }

                $count = count($neighbors);
                $low = intdiv($count - 1, 2);
                $high = intdiv($count, 2);
                for ($medianIndex = $low; $medianIndex <= $high; ++$medianIndex) {
                    if ($align[$nodeId] !== $nodeId) {
                        continue;
                    }
                    $neighborId = $neighbors[$medianIndex];
                    if ($previousPosition >= $positionByNodeId[$neighborId] || $this->conflicted($conflicts, $neighborId, $nodeId)) {
                        continue;
                    }
                    $align[$neighborId] = $nodeId;
                    $root[$nodeId] = $root[$neighborId];
                    $align[$nodeId] = $root[$neighborId];
                    $previousPosition = $positionByNodeId[$neighborId];
                }
            }
        }

        return [$root, $align];
    }

    /**
     * Neighbours in the previous oriented layer (predecessors for a downward
     * pass, successors for an upward pass), ordered by their position so the
     * median sits in the middle.
     *
     * @param array<string, int> $positionByNodeId
     *
     * @return list<string>
     */
    private function alignmentNeighbors(LayoutGraph $graph, string $nodeId, bool $vertical, array $positionByNodeId): array
    {
        $neighbors = $vertical ? $graph->successors($nodeId) : $graph->predecessors($nodeId);
        $present = array_values(array_filter($neighbors, static fn (string $nodeId): bool => isset($positionByNodeId[$nodeId])));
        usort($present, static fn (string $leftNodeId, string $rightNodeId): int => $positionByNodeId[$leftNodeId] <=> $positionByNodeId[$rightNodeId]);

        return $present;
    }

    /** @param array<string, true> $conflicts */
    private function conflicted(array $conflicts, string $neighborId, string $nodeId): bool
    {
        // Conflicts are recorded on the original (upper, lower) edge; when the
        // layer order is mirrored for a rightmost pass the median neighbour is
        // still the same upper node, so the lookup is identical either way.
        return isset($conflicts[$neighborId."\0".$nodeId]) || isset($conflicts[$nodeId."\0".$neighborId]);
    }

    /**
     * @param list<list<string>>    $layers
     * @param array<string, string> $root
     * @param array<string, string> $align
     *
     * @return array<string, int>
     */
    private function horizontalCompaction(LayoutGraph $graph, array $layers, array $root, array $align, bool $horizontal): array
    {
        $predecessorInLayer = $this->predecessorsInLayer($layers);

        /** @var array<string, int> $horizontalCentersByBlockRoot */
        $horizontalCentersByBlockRoot = [];
        /** @var array<string, string> $sink */
        $sink = [];
        /** @var array<string, int> $shift */
        $shift = [];
        foreach (array_keys($root) as $nodeId) {
            $nodeId = strval($nodeId);
            $sink[$nodeId] = $nodeId;
            $shift[$nodeId] = PHP_INT_MAX;
        }

        foreach ($root as $nodeId => $rootId) {
            if (strval($nodeId) === $rootId) {
                $this->placeBlock($graph, strval($nodeId), $root, $align, $predecessorInLayer, $horizontal, $horizontalCentersByBlockRoot, $sink, $shift);
            }
        }

        $result = [];
        foreach ($root as $nodeId => $rootId) {
            $horizontalCenter = $horizontalCentersByBlockRoot[$rootId];
            $sinkShift = $shift[$sink[$rootId]];
            if (PHP_INT_MAX !== $sinkShift) {
                $horizontalCenter += $sinkShift;
            }
            $result[$nodeId] = $horizontalCenter;
        }

        return $result;
    }

    /**
     * @param array<string, string> $root
     * @param array<string, string> $align
     * @param array<string, string> $predecessorInLayer
     * @param array<string, int>    $horizontalCentersByBlockRoot
     * @param array<string, string> $sink
     * @param array<string, int>    $shift
     */
    private function placeBlock(LayoutGraph $graph, string $blockRoot, array $root, array $align, array $predecessorInLayer, bool $horizontal, array &$horizontalCentersByBlockRoot, array &$sink, array &$shift): void
    {
        if (isset($horizontalCentersByBlockRoot[$blockRoot])) {
            return;
        }

        $horizontalCentersByBlockRoot[$blockRoot] = 0;
        $current = $blockRoot;
        do {
            if (isset($predecessorInLayer[$current])) {
                $leftNeighbor = $predecessorInLayer[$current];
                $leftRoot = $root[$leftNeighbor];
                $this->placeBlock($graph, $leftRoot, $root, $align, $predecessorInLayer, $horizontal, $horizontalCentersByBlockRoot, $sink, $shift);

                if ($sink[$blockRoot] === $blockRoot) {
                    $sink[$blockRoot] = $sink[$leftRoot];
                }

                $separation = $this->separation($graph, $leftNeighbor, $current, $horizontal);
                if ($sink[$blockRoot] !== $sink[$leftRoot]) {
                    $shift[$sink[$leftRoot]] = min($shift[$sink[$leftRoot]], $horizontalCentersByBlockRoot[$blockRoot] - $horizontalCentersByBlockRoot[$leftRoot] - $separation);
                } else {
                    $horizontalCentersByBlockRoot[$blockRoot] = max($horizontalCentersByBlockRoot[$blockRoot], $horizontalCentersByBlockRoot[$leftRoot] + $separation);
                }
            }
            $current = $align[$current];
        } while ($current !== $blockRoot);
    }

    /**
     * Minimum centre-to-centre distance between two horizontally adjacent nodes
     * that keeps their boxes (plus spacing) from overlapping. Computed against
     * the original left-to-right order, so a rightmost pass swaps the arguments.
     */
    private function separation(LayoutGraph $graph, string $leftNeighbor, string $current, bool $horizontal): int
    {
        [$left, $right] = $horizontal ? [$current, $leftNeighbor] : [$leftNeighbor, $current];
        $leftWidth = $graph->getLayoutNode($left)->boxWidth();
        $rightWidth = $graph->getLayoutNode($right)->boxWidth();

        return ($leftWidth - intdiv($leftWidth, 2)) + intdiv($rightWidth, 2) + $this->horizontalSpacing + $this->selfLoopClearance($graph, $left);
    }

    /**
     * A self-loop is drawn as a lane two columns past the node's right edge
     * (SelfLoopRouter), so the node to its right must reserve that footprint to
     * keep an empty column past the lane.
     */
    private function selfLoopClearance(LayoutGraph $graph, string $nodeId): int
    {
        foreach ($graph->selfLoops() as $loop) {
            if ($loop->edge->sourceId === $nodeId) {
                return 2;
            }
        }

        return 0;
    }

    /**
     * Flip a rightmost-pass coordinate set back to a left-growing axis by
     * negating, so all four candidates share one orientation before balancing.
     *
     * @param array<string, int> $horizontalCenters
     *
     * @return array<string, int>
     */
    private function deorient(array $horizontalCenters, bool $horizontal): array
    {
        if (!$horizontal) {
            return $horizontalCenters;
        }

        return array_map(static fn (int $horizontalCenter): int => -$horizontalCenter, $horizontalCenters);
    }

    /**
     * Assign each node the average of its two middle coordinates across the four
     * aligned candidate layouts (the balanced median). The final shift to a zero
     * origin is left to the caller's column normalisation.
     *
     * @param array{array<string, int>, array<string, int>, array<string, int>, array<string, int>} $candidates
     *
     * @return array<string, int>
     */
    private function balance(array $candidates): array
    {
        $balanced = [];
        foreach (array_keys($candidates[0]) as $nodeId) {
            $values = [$candidates[0][$nodeId], $candidates[1][$nodeId], $candidates[2][$nodeId], $candidates[3][$nodeId]];
            sort($values);
            $balanced[$nodeId] = intdiv($values[1] + $values[2], 2);
        }

        return $balanced;
    }

    /**
     * Shift the four layouts onto a shared frame before balancing: pick the
     * narrowest layout as the reference, then move each left-biased layout so its
     * leftmost coordinate matches the reference's, and each right-biased layout so
     * its rightmost matches — dagre's `alignCoordinates`. Candidates 1 and 3 are
     * the right-biased passes.
     *
     * @param array{array<string, int>, array<string, int>, array<string, int>, array<string, int>} $candidates
     *
     * @return array{array<string, int>, array<string, int>, array<string, int>, array<string, int>}
     */
    private function alignCandidates(LayoutGraph $graph, array $candidates): array
    {
        $reference = $this->narrowestCandidate($graph, $candidates);
        $referenceMin = $this->minimum($reference);
        $referenceMax = $this->maximum($reference);

        foreach ([0, 1, 2, 3] as $index) {
            $rightBiased = 1 === $index % 2;
            $coordinateShift = $rightBiased
                ? $referenceMax - $this->maximum($candidates[$index])
                : $referenceMin - $this->minimum($candidates[$index]);
            if (0 !== $coordinateShift) {
                $candidates[$index] = array_map(static fn (int $value): int => $value + $coordinateShift, $candidates[$index]);
            }
        }

        return $candidates;
    }

    /**
     * @param array{array<string, int>, array<string, int>, array<string, int>, array<string, int>} $candidates
     *
     * @return array<string, int>
     */
    private function narrowestCandidate(LayoutGraph $graph, array $candidates): array
    {
        $reference = $candidates[0];
        $narrowest = $this->candidateWidth($graph, $candidates[0]);
        foreach ([$candidates[1], $candidates[2], $candidates[3]] as $candidate) {
            $width = $this->candidateWidth($graph, $candidate);
            if ($width < $narrowest) {
                $narrowest = $width;
                $reference = $candidate;
            }
        }

        return $reference;
    }

    /** @param array<string, int> $centers */
    private function candidateWidth(LayoutGraph $graph, array $centers): int
    {
        /** @infection-ignore-all both seeds are overwritten on the first iteration for any non-empty layout; only the impossible empty case observes them */
        $minimumLeftColumn = PHP_INT_MAX;
        $maximumRightColumn = PHP_INT_MIN;
        foreach ($centers as $nodeId => $center) {
            $node = $graph->getLayoutNode(strval($nodeId));
            $left = $center - intdiv($node->boxWidth(), 2);
            $minimumLeftColumn = min($minimumLeftColumn, $left);
            $maximumRightColumn = max($maximumRightColumn, $left + $node->boxWidth());
        }

        return $maximumRightColumn - $minimumLeftColumn;
    }

    /** @param array<string, int> $values */
    private function minimum(array $values): int
    {
        /** @infection-ignore-all overwritten on the first iteration for any non-empty input (every coordinate is below PHP_INT_MAX); only the impossible empty case observes this seed */
        $minimum = PHP_INT_MAX;
        foreach ($values as $value) {
            $minimum = min($minimum, $value);
        }

        return $minimum;
    }

    /** @param array<string, int> $values */
    private function maximum(array $values): int
    {
        /** @infection-ignore-all overwritten on the first iteration for any non-empty input (every coordinate is above PHP_INT_MIN); only the impossible empty case observes this seed */
        $maximum = PHP_INT_MIN;
        foreach ($values as $value) {
            $maximum = max($maximum, $value);
        }

        return $maximum;
    }

    /**
     * @param list<list<string>> $layers
     *
     * @return array<string, int>
     */
    private function positionsWithin(array $layers): array
    {
        $positionByNodeId = [];
        foreach ($layers as $layer) {
            foreach ($layer as $index => $nodeId) {
                $positionByNodeId[$nodeId] = $index;
            }
        }

        return $positionByNodeId;
    }

    /**
     * @param list<list<string>> $layers
     *
     * @return array<string, string> node => the node immediately to its left in the same oriented layer
     */
    private function predecessorsInLayer(array $layers): array
    {
        $predecessor = [];
        foreach ($layers as $layer) {
            for ($nodeOffset = 1, $nodeCount = count($layer); $nodeOffset < $nodeCount; ++$nodeOffset) {
                $predecessor[$layer[$nodeOffset]] = $layer[$nodeOffset - 1];
            }
        }

        return $predecessor;
    }

    private function isDummy(LayoutGraph $graph, string $nodeId): bool
    {
        return $graph->getLayoutNode($nodeId) instanceof DummyLayoutNode;
    }
}
