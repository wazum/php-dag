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
     * @param array<int, int>    $exitPositions       keyed by spl_object_id of the edge
     * @param array<int, int>    $entryPositions      keyed by spl_object_id of the edge
     * @param array<int, int>    $bendDepths          lanes back from the bend nearest the target, keyed by spl_object_id of the edge
     * @param array<string, int> $trunkExitPositions  keyed by node id
     * @param array<string, int> $trunkEntryPositions keyed by node id
     * @param array<int, int>    $extraLanesByGap     keyed by the layer before the gap
     */
    private function __construct(
        public array $exitPositions,
        public array $entryPositions,
        public array $bendDepths,
        public array $trunkExitPositions,
        public array $trunkEntryPositions,
        public array $extraLanesByGap,
    ) {
    }

    /**
     * Ports needed on the busiest side of every box: one per parallel edge plus
     * one shared trunk for its other edges, or none without a parallel edge.
     *
     * @return array<string, int>
     */
    public static function portCounts(LayoutGraph $graph): array
    {
        [$exitSides, $entrySides] = self::sides(self::pairs($graph));
        $portCounts = [];
        foreach ([...array_keys($exitSides), ...array_keys($entrySides)] as $nodeId) {
            $portCounts[strval($nodeId)] = max(self::portCount($exitSides[$nodeId] ?? []), self::portCount($entrySides[$nodeId] ?? []));
        }

        return $portCounts;
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
        foreach ($pairs as [$source, $target, $edges, $alone]) {
            if (count($edges) < 2) {
                continue;
            }
            if ($alone) {
                foreach ($edges as $index => $edge) {
                    $exitPositions[spl_object_id($edge)] = $entryPositions[spl_object_id($edge)] = self::straightLanePosition($source, $target, $index, count($edges), $alongRows);
                }
            }
            $towardsStart = [];
            $towardsEnd = [];
            $bendPositions = [];
            foreach ($edges as $edge) {
                $edgeId = spl_object_id($edge);
                if ($entryPositions[$edgeId] < $exitPositions[$edgeId]) {
                    $towardsStart[] = $edgeId;
                } elseif ($entryPositions[$edgeId] > $exitPositions[$edgeId]) {
                    $towardsEnd[] = $edgeId;
                }
                if ($entryPositions[$edgeId] !== $exitPositions[$edgeId]) {
                    array_push($bendPositions, $exitPositions[$edgeId], $entryPositions[$edgeId]);
                }
            }
            $lift = self::crossesTrunk($bendPositions, $exitTrunkSpans[$source->id] ?? null) || self::crossesTrunk($bendPositions, $entryTrunkSpans[$target->id] ?? null) ? 1 : 0;
            foreach ($towardsStart as $rank => $edgeId) {
                $bendDepths[$edgeId] = count($towardsStart) - 1 - $rank + $lift;
            }
            foreach ($towardsEnd as $rank => $edgeId) {
                $bendDepths[$edgeId] = $rank + $lift;
            }
            $extraLanesByGap[$source->layer] = max($extraLanesByGap[$source->layer] ?? 0, count($towardsStart) - 1 + $lift, count($towardsEnd) - 1 + $lift);
        }

        return new self($exitPositions, $entryPositions, $bendDepths, $trunkExitPositions, $trunkEntryPositions, $extraLanesByGap);
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

        $position = self::start($node, $alongRows) + intdiv(self::extent($node, $alongRows) - (2 * $portCount - 1), 2);
        foreach ($slots as [, $edges]) {
            if ([] === $edges) {
                $trunkPositions[$node->id] = $position;
                $trunkSpans[$node->id] = [min($position, ...$trunkFarCenters), max($position, ...$trunkFarCenters)];
                $position += 2;
            }
            foreach ($edges as $edge) {
                $edgePositions[spl_object_id($edge)] = $position;
                $position += 2;
            }
        }
    }

    private static function start(LayoutNode $node, bool $alongRows): int
    {
        return $alongRows ? $node->row : $node->column;
    }

    private static function extent(LayoutNode $node, bool $alongRows): int
    {
        return $alongRows ? $node->boxHeight() : $node->boxWidth();
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
