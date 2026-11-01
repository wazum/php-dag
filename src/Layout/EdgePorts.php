<?php

declare(strict_types=1);

namespace PhpDag\Layout;

/**
 * Port positions for every box that ends a parallel (multi-) edge group. Each
 * parallel edge gets its own port on the exit side of its source and the entry
 * side of its target; all other edges on that side share one trunk port. Ports
 * are ordered by where the far ends sit, so lanes never cross on leaving the
 * box, and sit one cell apart, centred on the box side. Bending parallel edges
 * nest: the lane nearest the turn bends closest to the target, so no lane
 * crosses another. Positions are columns in top-to-bottom flow and rows in
 * left-to-right flow.
 */
final readonly class EdgePorts
{
    /**
     * @param array<int, int>                          $exitPositions       keyed by spl_object_id of the edge
     * @param array<int, int>                          $entryPositions      keyed by spl_object_id of the edge
     * @param array<int, int>                          $bendDepths          lanes back from the bend nearest the target, keyed by spl_object_id of the edge
     * @param array<string, int>                       $trunkExitPositions  keyed by node id
     * @param array<string, int>                       $trunkEntryPositions keyed by node id
     * @param array<int, int>                          $extraLanesByGap     keyed by the layer before the gap
     * @param list<array{LayoutNode, LayoutNode, int}> $misalignedGroups    source, target and the offset that would make every lane of the group straight
     */
    private function __construct(
        public array $exitPositions,
        public array $entryPositions,
        public array $bendDepths,
        public array $trunkExitPositions,
        public array $trunkEntryPositions,
        public array $extraLanesByGap,
        public array $misalignedGroups,
    ) {
    }

    /**
     * The box extent (width in top-to-bottom flow, height in left-to-right flow)
     * every box needs so its busiest side hosts one port per parallel edge plus
     * one shared trunk for its other edges; zero without a parallel edge.
     *
     * @return array<string, int>
     */
    public static function requiredExtents(LayoutGraph $graph, FlowDirection $flowDirection): array
    {
        $alongRows = FlowDirection::LeftToRight === $flowDirection;
        [$exitSides, $entrySides] = self::sides(self::pairs($graph));
        $extents = [];
        foreach ([...array_keys($exitSides), ...array_keys($entrySides)] as $nodeId) {
            $sideExtents = array_filter(
                [self::sideExtent($exitSides[$nodeId] ?? [], $alongRows), self::sideExtent($entrySides[$nodeId] ?? [], $alongRows)],
                static fn (?int $extent): bool => null !== $extent,
            );
            if ([] !== $sideExtents) {
                $extents[strval($nodeId)] = max($sideExtents);
            }
        }

        return $extents;
    }

    public static function of(LayoutGraph $graph, FlowDirection $flowDirection): self
    {
        $alongRows = FlowDirection::LeftToRight === $flowDirection;
        $pairs = self::pairs($graph);
        [$exitSides, $entrySides] = self::sides($pairs);
        $exitPositions = [];
        $entryPositions = [];
        $trunkExitPositions = [];
        $trunkEntryPositions = [];
        $exitTrunkSpans = [];
        $entryTrunkSpans = [];
        foreach ($exitSides as $sidePairs) {
            self::assign($sidePairs[0][0], $sidePairs, 1, $alongRows, $exitPositions, $trunkExitPositions, $exitTrunkSpans);
        }
        foreach ($entrySides as $sidePairs) {
            self::assign($sidePairs[0][1], $sidePairs, 0, $alongRows, $entryPositions, $trunkEntryPositions, $entryTrunkSpans);
        }

        $bendDepths = [];
        $extraLanesByGap = [];
        $misalignedGroups = [];
        foreach ($pairs as [$source, $target, $edges, $alone]) {
            if (count($edges) < 2) {
                continue;
            }
            if ($alone) {
                foreach ($edges as $index => $edge) {
                    $exitPositions[spl_object_id($edge)] = $entryPositions[spl_object_id($edge)] = self::straightLanePosition($source, $target, $index, count($edges), $alongRows);
                }
            }
            $offsets = array_unique(array_map(static fn (LayoutEdge $edge): int => $entryPositions[spl_object_id($edge)] - $exitPositions[spl_object_id($edge)], $edges));
            if (1 === count($offsets) && 0 !== $offsets[0]) {
                $misalignedGroups[] = [$source, $target, $offsets[0]];
            }
            $towardsStart = [];
            $towardsEnd = [];
            $bendPositions = [];
            foreach ($edges as $edge) {
                $edgeId = spl_object_id($edge);
                if ($entryPositions[$edgeId] === $exitPositions[$edgeId]) {
                    continue;
                }
                array_push($bendPositions, $exitPositions[$edgeId], $entryPositions[$edgeId]);
                /** @infection-ignore-all straight lanes were skipped above, so the two positions are never equal here */
                if ($entryPositions[$edgeId] < $exitPositions[$edgeId]) {
                    $towardsStart[] = $edgeId;
                } else {
                    $towardsEnd[] = $edgeId;
                }
            }
            $lift = self::crossesTrunk($bendPositions, $exitTrunkSpans[$source->id] ?? null) || self::crossesTrunk($bendPositions, $entryTrunkSpans[$target->id] ?? null) ? 1 : 0;
            foreach ($towardsStart as $rank => $edgeId) {
                $bendDepths[$edgeId] = count($towardsStart) - 1 - $rank + $lift;
            }
            foreach ($towardsEnd as $rank => $edgeId) {
                $bendDepths[$edgeId] = $rank + $lift;
            }
            $labelWidths = self::labelWidths($edges);
            if ([] === $bendPositions && [] === $labelWidths) {
                continue;
            }
            $labelLanes = match (true) {
                [] === $labelWidths => 0,
                $alongRows => max($labelWidths) + 1,
                default => 1,
            };
            $bendLanes = [] === $bendPositions ? 0 : max(count($towardsStart), count($towardsEnd)) + $lift;
            $neededLanes = $bendLanes + 1 + $labelLanes;
            $availableLanes = self::flowStart($target, $alongRows) - 1 - self::flowStart($source, $alongRows) - self::flowExtent($source, $alongRows);
            $extraLanesByGap[$source->layer] = max($extraLanesByGap[$source->layer] ?? 0, $neededLanes - $availableLanes);
        }

        return new self($exitPositions, $entryPositions, $bendDepths, $trunkExitPositions, $trunkEntryPositions, $extraLanesByGap, $misalignedGroups);
    }

    /**
     * Forward edges grouped by (source, target), in edge order; "alone" marks a
     * pair that is the only connection on both of its boxes.
     *
     * @return list<array{LayoutNode, LayoutNode, non-empty-list<LayoutEdge>, bool}>
     */
    private static function pairs(LayoutGraph $graph): array
    {
        $edgesBySourceAndTarget = [];
        $edgesByTargetAndSource = [];
        foreach ($graph->edges() as $edge) {
            if (!$edge->reversed) {
                $edgesBySourceAndTarget[$edge->sourceId()][$edge->targetId()][] = $edge;
                $edgesByTargetAndSource[$edge->targetId()][$edge->sourceId()][] = $edge;
            }
        }

        $pairs = [];
        foreach ($edgesBySourceAndTarget as $sourceId => $edgesByTarget) {
            foreach ($edgesByTarget as $targetId => $edges) {
                $pairs[] = [
                    $graph->getLayoutNode(strval($sourceId)),
                    $graph->getLayoutNode(strval($targetId)),
                    $edges,
                    1 === count($edgesByTarget) && 1 === count($edgesByTargetAndSource[$targetId]),
                ];
            }
        }

        return $pairs;
    }

    /**
     * The pairs leaving each box's exit side and entering each box's entry
     * side, keyed by box id.
     *
     * @param list<array{LayoutNode, LayoutNode, non-empty-list<LayoutEdge>, bool}> $pairs
     *
     * @return array{array<string, non-empty-list<array{LayoutNode, LayoutNode, non-empty-list<LayoutEdge>, bool}>>, array<string, non-empty-list<array{LayoutNode, LayoutNode, non-empty-list<LayoutEdge>, bool}>>}
     */
    private static function sides(array $pairs): array
    {
        $exitSides = [];
        $entrySides = [];
        foreach ($pairs as $pair) {
            $exitSides[$pair[0]->id][] = $pair;
            $entrySides[$pair[1]->id][] = $pair;
        }

        return [$exitSides, $entrySides];
    }

    /**
     * @param list<array{LayoutNode, LayoutNode, non-empty-list<LayoutEdge>, bool}> $pairs
     */
    private static function portCount(array $pairs): int
    {
        $parallelPorts = 0;
        $hasTrunk = false;
        foreach ($pairs as [, , $edges]) {
            if (count($edges) > 1) {
                $parallelPorts += count($edges);
            } else {
                $hasTrunk = true;
            }
        }

        return 0 === $parallelPorts ? 0 : $parallelPorts + ($hasTrunk ? 1 : 0);
    }

    /**
     * Ports sit two cells apart; in top-to-bottom flow a side carrying labelled
     * parallel edges spaces them so each label fits on its own lane with two
     * blank cells to the next one. The spacing stays even, so the box keeps an
     * odd width and its content stays centred.
     *
     * @param list<array{LayoutNode, LayoutNode, non-empty-list<LayoutEdge>, bool}> $pairs
     */
    private static function spacing(array $pairs, bool $alongRows): int
    {
        $labelWidths = [];
        foreach ($pairs as [, , $edges]) {
            if (count($edges) > 1 && !$alongRows) {
                array_push($labelWidths, ...self::labelWidths($edges));
            }
        }
        if ([] === $labelWidths) {
            return 2;
        }

        $spacing = max($labelWidths) + 2;

        return $spacing + $spacing % 2;
    }

    /**
     * @param list<LayoutEdge> $edges
     *
     * @return list<int>
     */
    private static function labelWidths(array $edges): array
    {
        $labelWidths = [];
        foreach ($edges as $edge) {
            if (null !== $edge->edge->label) {
                $labelWidths[] = $edge->edge->label->width();
            }
        }

        return $labelWidths;
    }

    /**
     * @param list<array{LayoutNode, LayoutNode, non-empty-list<LayoutEdge>, bool}> $pairs
     */
    private static function sideExtent(array $pairs, bool $alongRows): ?int
    {
        $portCount = self::portCount($pairs);

        return 0 === $portCount ? null : self::spacing($pairs, $alongRows) * ($portCount - 1) + 3;
    }

    /**
     * @param non-empty-list<array{LayoutNode, LayoutNode, non-empty-list<LayoutEdge>, bool}> $pairs
     * @param 0|1                                                                             $farEnd         index of the far end within each pair
     * @param array<int, int>                                                                 $edgePositions
     * @param array<string, int>                                                              $trunkPositions
     * @param array<string, array{int, int}>                                                  $trunkSpans     positions the shared trunk's bar runs across
     */
    private static function assign(LayoutNode $node, array $pairs, int $farEnd, bool $alongRows, array &$edgePositions, array &$trunkPositions, array &$trunkSpans): void
    {
        $portCount = self::portCount($pairs);
        if (0 === $portCount) {
            return;
        }

        $slots = [];
        $trunkFarCenters = [];
        foreach ($pairs as $pair) {
            if (count($pair[2]) > 1) {
                $slots[] = [self::center($pair[$farEnd], $alongRows), $pair[2]];
            } else {
                $trunkFarCenters[] = self::center($pair[$farEnd], $alongRows);
            }
        }
        if ([] !== $trunkFarCenters) {
            $slots[] = [intdiv(array_sum($trunkFarCenters), count($trunkFarCenters)), []];
        }
        usort($slots, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $spacing = self::spacing($pairs, $alongRows);
        $firstPositions = self::firstPortPositions(
            $slots,
            $spacing,
            self::start($node, $alongRows) + 1,
            self::start($node, $alongRows) + self::extent($node, $alongRows) - 2,
        );
        foreach ($slots as $index => [, $edges]) {
            $position = $firstPositions[$index];
            if ([] === $edges) {
                $trunkPositions[$node->id] = $position;
                $trunkSpans[$node->id] = [min($position, ...$trunkFarCenters), max($position, ...$trunkFarCenters)];
            }
            foreach ($edges as $edge) {
                $edgePositions[spl_object_id($edge)] = $position;
                $position += $spacing;
            }
        }
    }

    /**
     * Each slot sits centred across from its far end, pushed apart only as far
     * as the spacing needs and kept inside the box side's inner cells.
     *
     * @param list<array{int, list<LayoutEdge>}> $slots far-end centre and the edges of each slot, in order
     *
     * @return array<int, int> first port position, keyed by slot index
     */
    private static function firstPortPositions(array $slots, int $spacing, int $innerStart, int $innerEnd): array
    {
        $spans = array_map(static fn (array $slot): int => (max(1, count($slot[1])) - 1) * $spacing, $slots);
        $firstPositions = [];
        $nextFree = $innerStart;
        foreach ($slots as $index => [$farCenter]) {
            $firstPositions[$index] = max($farCenter - intdiv($spans[$index], 2), $nextFree);
            $nextFree = $firstPositions[$index] + $spans[$index] + $spacing;
        }

        $limit = $innerEnd;
        for ($index = count($slots) - 1; $index >= 0; --$index) {
            $firstPositions[$index] = min($firstPositions[$index], $limit - $spans[$index]);
            $limit = $firstPositions[$index] - $spacing;
        }

        return $firstPositions;
    }

    private static function start(LayoutNode $node, bool $alongRows): int
    {
        return $alongRows ? $node->row : $node->column;
    }

    private static function extent(LayoutNode $node, bool $alongRows): int
    {
        return $alongRows ? $node->boxHeight() : $node->boxWidth();
    }

    private static function flowStart(LayoutNode $node, bool $alongRows): int
    {
        return $alongRows ? $node->column : $node->row;
    }

    private static function flowExtent(LayoutNode $node, bool $alongRows): int
    {
        return $alongRows ? $node->boxWidth() : $node->boxHeight();
    }

    private static function center(LayoutNode $node, bool $alongRows): int
    {
        return self::start($node, $alongRows) + intdiv(self::extent($node, $alongRows), 2);
    }

    /**
     * @param list<int>       $bendPositions
     * @param array{int, int} $trunkSpan
     */
    private static function crossesTrunk(array $bendPositions, ?array $trunkSpan): bool
    {
        return [] !== $bendPositions && null !== $trunkSpan && min($bendPositions) <= $trunkSpan[1] && max($bendPositions) >= $trunkSpan[0];
    }

    /**
     * A pair that is the only connection on both of its boxes runs as straight
     * lanes spread across the overlap of the two boxes' inner cells.
     */
    private static function straightLanePosition(LayoutNode $source, LayoutNode $target, int $index, int $count, bool $alongRows): int
    {
        $lowerBound = max(self::start($source, $alongRows), self::start($target, $alongRows)) + 1;
        $upperBound = min(self::start($source, $alongRows) + self::extent($source, $alongRows), self::start($target, $alongRows) + self::extent($target, $alongRows)) - 2;

        /** @infection-ignore-all the max(0, ...) floor only guards non-overlapping inner spans, which cannot occur here: a pair that is the only connection of both boxes is aligned onto one centre line by the positioner */
        return $lowerBound + intdiv($index * max(0, $upperBound - $lowerBound), $count - 1);
    }
}
