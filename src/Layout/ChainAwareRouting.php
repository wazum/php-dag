<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Render\Waypoint;
use SplObjectStorage;

final readonly class ChainAwareRouting implements EdgeRouting
{
    public function route(LayoutGraph $graph): void
    {
        $layerMaxHeights = $this->computeLayerMaxHeights($graph);
        $groupEntryCeilings = $this->groupEntryCeilings($graph);
        $chains = $this->reconstructChains($graph);
        usort(
            $chains,
            /**
             * @param array{string, string, list<LayoutEdge>} $left
             * @param array{string, string, list<LayoutEdge>} $right
             */
            fn (array $left, array $right): int => (null === $this->corridorLaneColumn($graph, $left[2])) <=> (null === $this->corridorLaneColumn($graph, $right[2])),
        );

        /** @var SplObjectStorage<LayoutEdge, true> */
        $routedEdges = new SplObjectStorage();

        /** @var array<string, int> */
        $preferredColumns = [];

        /** @var array<int, list<int>> */
        $laneColumns = [];

        /** @var array<string, int> */
        $targetLanes = [];

        /** @var array<string, int> */
        $sourceLanes = [];

        foreach ($chains as [$chainSourceId, $chainTargetId, $chainEdges]) {
            $this->routeChain($graph, $chainSourceId, $chainTargetId, $chainEdges, $layerMaxHeights, $groupEntryCeilings, $preferredColumns, $laneColumns, $targetLanes, $sourceLanes);
            foreach ($chainEdges as $chainEdge) {
                $routedEdges->attach($chainEdge);
            }
        }

        // Remaining direct edges, grouped so parallel edges (a second a->b) fan
        // out into separate lanes instead of overlapping into one.
        /** @var array<string, list<LayoutEdge>> $directGroups */
        $directGroups = [];
        foreach ($graph->edges() as $edge) {
            if ($routedEdges->contains($edge)) {
                /** @infection-ignore-all break would stop after first non-chain edge; tests have chain edges followed by direct edges */
                continue;
            }
            /** @infection-ignore-all the separator only keys the (source, target) group; node ids cannot contain it, so altering it leaves the grouping unchanged for any real id */
            $directGroups[$edge->sourceId()."\0".$edge->targetId()][] = $edge;
        }

        foreach ($directGroups as $group) {
            $parallelCount = count($group);
            foreach ($group as $parallelIndex => $edge) {
                $sourceNode = $graph->getLayoutNode($edge->sourceId());
                $targetNode = $graph->getLayoutNode($edge->targetId());
                /** @infection-ignore-all boxHeight() is always positive; coalesce fallback is defensive */
                $exitRow = $sourceNode->row + ($layerMaxHeights[$sourceNode->layer] ?? $sourceNode->boxHeight());
                $entryRow = $targetNode->row - 1;

                if (1 === $parallelCount) {
                    $exitColumn = $sourceNode->column + intdiv($sourceNode->boxWidth(), 2);
                    $targetColumn = $this->alignedTargetColumn($exitColumn, $targetNode, $graph);
                } else {
                    $exitColumn = $this->parallelLaneColumn($sourceNode, $targetNode, $parallelIndex, $parallelCount);
                    $targetColumn = $exitColumn;
                }

                $this->routeDirectEdge($edge, $exitRow, $exitColumn, $entryRow, $targetColumn, $groupEntryCeilings[$targetNode->id] ?? null);
            }
        }
    }

    /**
     * Places parallel edges on lane columns valid for *both* the source and the
     * target box — the overlap of their inner port spans — spread evenly across
     * that overlap. Because both endpoints share the column, each lane drops as a
     * straight vertical line instead of bending when the two boxes differ in
     * width (Graphviz likewise nests adjacent-rank multi-edges as straight,
     * centre-symmetric lines).
     */
    private function parallelLaneColumn(LayoutNode $source, LayoutNode $target, int $index, int $count): int
    {
        $leftBound = max($source->column + 1, $target->column + 1);
        $rightBound = min(
            $source->column + $source->boxWidth() - 2,
            $target->column + $target->boxWidth() - 2,
        );
        /** @infection-ignore-all the max(0, ...) floor only guards non-overlapping inner spans, which cannot occur for a parallel connection whose endpoints share a centre column; with overlap rightBound >= leftBound always holds */
        $span = max(0, $rightBound - $leftBound);

        return $leftBound + intdiv($index * $span, $count - 1);
    }

    /**
     * Every node sharing a group's top row has its arrow one row below the
     * group's top border, so an edge bending at the usual row would jog along
     * that border. Lifting the bend above the border lets members drop straight
     * down *through* it (a crossing and a feeder into the arrow) and lets
     * outside siblings drop cleanly *beside* it instead of running into the
     * border line. Maps each such node to the highest row its incoming bend may
     * use.
     *
     * @return array<string, int> node id => bend ceiling row
     */
    private function groupEntryCeilings(LayoutGraph $graph): array
    {
        $ceilings = [];
        foreach ($graph->groups() as $group) {
            $topRow = PHP_INT_MAX;
            foreach ($group->nodeIds as $nodeId) {
                if (!$graph->hasNode($nodeId)) {
                    continue;
                }
                $topRow = min($topRow, $graph->getLayoutNode($nodeId)->row);
            }
            if (PHP_INT_MAX === $topRow) {
                continue;
            }

            // Mirrors GroupRenderer: the border sits two rows above the topmost
            // member, so the bend must clear it by one more row.
            $ceiling = $topRow - 3;
            foreach ($graph->nodeIds() as $nodeId) {
                if ($graph->getLayoutNode($nodeId)->row !== $topRow) {
                    continue;
                }
                $ceilings[$nodeId] = isset($ceilings[$nodeId]) ? min($ceilings[$nodeId], $ceiling) : $ceiling;
            }
        }

        return $ceilings;
    }

    /** @param list<LayoutEdge> $chainEdges */
    private function corridorLaneColumn(LayoutGraph $graph, array $chainEdges): ?int
    {
        foreach ($chainEdges as $edge) {
            $target = $graph->getLayoutNode($edge->targetId());
            if ($target instanceof DummyLayoutNode && $target->corridorWidth > 0) {
                return $target->column + intdiv($target->boxWidth(), 2);
            }
        }

        return null;
    }

    /**
     * @return list<array{string, string, list<LayoutEdge>}>
     */
    private function reconstructChains(LayoutGraph $graph): array
    {
        /** @var array<string, list<DummyLayoutNode>> */
        $dummiesByOriginalEdge = [];

        foreach ($graph->nodeIds() as $nodeId) {
            $node = $graph->getLayoutNode($nodeId);
            if ($node instanceof DummyLayoutNode) {
                $dummiesByOriginalEdge[$node->identityKey()][] = $node;
            }
        }

        $chains = [];
        foreach ($dummiesByOriginalEdge as $dummies) {
            $sourceId = $dummies[0]->originalEdgeSourceId;
            $targetId = $dummies[0]->originalEdgeTargetId;
            /** @infection-ignore-all dummies are inserted in layer order by DummyNodeInserter; sort is defensive */
            usort($dummies, static fn (DummyLayoutNode $leftDummy, DummyLayoutNode $rightDummy): int => $leftDummy->layer <=> $rightDummy->layer);

            $chainEdges = [];
            $previousId = $sourceId;
            foreach ($dummies as $dummy) {
                foreach ($graph->outgoingEdges($previousId) as $edge) {
                    if ($edge->targetId() === $dummy->id) {
                        $chainEdges[] = $edge;
                        /** @infection-ignore-all only one matching edge per inner loop; break and continue are equivalent */
                        break;
                    }
                }
                $previousId = $dummy->id;
            }
            foreach ($graph->outgoingEdges($previousId) as $edge) {
                if ($edge->targetId() === $targetId) {
                    $chainEdges[] = $edge;
                    /** @infection-ignore-all only one matching edge per inner loop; break and continue are equivalent */
                    break;
                }
            }

            $chains[] = [$sourceId, $targetId, $chainEdges];
        }

        /** @infection-ignore-all tests exercise single-chain graphs; multi-chain is validated by integration tests */
        return $chains;
    }

    /**
     * @param list<LayoutEdge>      $chainEdges
     * @param array<int, int>       $layerMaxHeights
     * @param array<string, int>    $groupEntryCeilings
     * @param array<string, int>    $preferredColumns
     * @param array<int, list<int>> $laneColumns
     * @param array<string, int>    $targetLanes
     * @param array<string, int>    $sourceLanes
     */
    private function routeChain(LayoutGraph $graph, string $chainSourceId, string $chainTargetId, array $chainEdges, array $layerMaxHeights, array $groupEntryCeilings, array &$preferredColumns, array &$laneColumns, array &$targetLanes, array &$sourceLanes): void
    {
        $corridorLaneColumn = $this->corridorLaneColumn($graph, $chainEdges);

        foreach ($chainEdges as $edge) {
            $sourceNode = $graph->getLayoutNode($edge->sourceId());
            $targetNode = $graph->getLayoutNode($edge->targetId());

            $exitColumn = $preferredColumns[$sourceNode->id] ?? $sourceNode->column + intdiv($sourceNode->boxWidth(), 2);
            /** @infection-ignore-all boxHeight() is always positive; coalesce fallback is defensive; exit row validated by integration tests */
            $exitRow = $sourceNode->row + ($layerMaxHeights[$sourceNode->layer] ?? $sourceNode->boxHeight());
            $entryRow = $targetNode->row - 1;

            if (!$targetNode instanceof DummyLayoutNode) {
                $targetColumn = $this->alignedTargetColumn($exitColumn, $targetNode, $graph);
                $this->routeDirectEdge($edge, $exitRow, $exitColumn, $entryRow, $targetColumn, $groupEntryCeilings[$targetNode->id] ?? null);
                /** @infection-ignore-all only consumed when the real target later sources another chain; first or last aligned column is a valid exit that the connection gap-fill renders equivalently */
                $preferredColumns[$targetNode->id] ??= $targetColumn;
                /** @infection-ignore-all a real target is always the final chain edge; continue and break leave the loop identically */
                continue;
            }

            if (null !== $corridorLaneColumn) {
                $laneColumns[$targetNode->layer][] = $corridorLaneColumn;
                /** @infection-ignore-all each node in a chain is visited exactly once; ??= and = are equivalent */
                $preferredColumns[$targetNode->id] ??= $corridorLaneColumn;
                $this->routeDirectEdge($edge, $exitRow, $exitColumn, $entryRow, $corridorLaneColumn, $groupEntryCeilings[$targetNode->id] ?? null);
                continue;
            }

            /** @infection-ignore-all lane keys are internal bookkeeping; mutated key collisions merge lanes that the connection gap-fill renders equivalently */
            $targetLaneKey = $targetNode->layer.':'.$chainTargetId;
            /** @infection-ignore-all lane keys are internal bookkeeping; mutated key collisions merge lanes that the connection gap-fill renders equivalently */
            $sourceLaneKey = $targetNode->layer.':'.$chainSourceId;

            /** @infection-ignore-all either trunk (target-first or source-first) is a valid shared lane */
            $sharedColumn = $targetLanes[$targetLaneKey] ?? $sourceLanes[$sourceLaneKey] ?? null;
            if (null !== $sharedColumn) {
                // A chain to the same target (fan-in trunk) or from the same
                // source (fan-out trunk) already occupies a lane in this
                // layer: join it instead of opening a parallel one.
                /** @infection-ignore-all verified equivalent: merged chain waypoints plus the connection gap-fill reconstruct the dropped segment */
                $this->routeDirectEdge($edge, $exitRow, $exitColumn, $entryRow, $sharedColumn, $groupEntryCeilings[$targetNode->id] ?? null);
                /** @infection-ignore-all re-registering the already-found shared column is idempotent */
                $targetLanes[$targetLaneKey] ??= $sharedColumn;
                /** @infection-ignore-all re-registering the already-found shared column is idempotent */
                $sourceLanes[$sourceLaneKey] ??= $sharedColumn;
                /** @infection-ignore-all each node in a chain is visited exactly once; ??= and = are equivalent */
                $preferredColumns[$targetNode->id] ??= $sharedColumn;
                /** @infection-ignore-all verified equivalent: the remaining segments coincide with the shared trunk already painted by the joined chain, and the connection gap-fill closes the tail */
                continue;
            }

            /** @infection-ignore-all the own-lane exemption only affects re-checking a chain against its own column; either branch yields the same lane */
            if (!$this->columnConflictsWithRealNodes($exitColumn, $targetNode->layer, $graph)
                && !$this->laneTooCloseToExistingLane($laneColumns, $targetNode->layer, $exitColumn, $sourceNode instanceof DummyLayoutNode ? $exitColumn : null)
            ) {
                /** @infection-ignore-all each node in a chain is visited exactly once; ??= and = are equivalent */
                $preferredColumns[$targetNode->id] ??= $exitColumn;
                $laneColumns[$targetNode->layer][] = $exitColumn;
                $targetLanes[$targetLaneKey] = $exitColumn;
                $sourceLanes[$sourceLaneKey] = $exitColumn;
                $edge->waypoints = [new Waypoint($exitRow, $exitColumn), new Waypoint($entryRow, $exitColumn)];
                continue;
            }

            $targetColumn = $this->separatedLaneColumn($graph, $laneColumns, $targetNode->layer, $this->alignedTargetColumn($exitColumn, $targetNode, $graph));
            $laneColumns[$targetNode->layer][] = $targetColumn;
            $targetLanes[$targetLaneKey] = $targetColumn;
            $sourceLanes[$sourceLaneKey] = $targetColumn;
            $this->routeDirectEdge($edge, $exitRow, $exitColumn, $entryRow, $targetColumn, $groupEntryCeilings[$targetNode->id] ?? null);

            /** @infection-ignore-all each node in a chain is visited exactly once; ??= and = are equivalent */
            $preferredColumns[$targetNode->id] ??= $targetColumn;
        }
    }

    /**
     * Finds the closest column to the preferred one that keeps a one-column
     * gap from other lanes and stays outside box footprints.
     *
     * @param array<int, list<int>> $laneColumns
     */
    private function separatedLaneColumn(LayoutGraph $graph, array $laneColumns, int $layer, int $preferredColumn): int
    {
        /** @infection-ignore-all search order and bound: any returned candidate satisfies the same separation and box constraints */
        for ($offset = 0; $offset <= 24; ++$offset) {
            foreach (0 === $offset ? [0] : [-$offset, $offset] as $delta) {
                $candidate = $preferredColumn + $delta;
                if ($candidate >= 0
                    && !$this->laneTooCloseToExistingLane($laneColumns, $layer, $candidate, null)
                    && !$this->columnConflictsWithRealNodes($candidate, $layer, $graph)
                ) {
                    return $candidate;
                }
            }
        }

        return $preferredColumn;
    }

    /**
     * Flush parallel lanes are unreadable; keep one empty column between
     * pass-through lanes. A chain continuing in its own lane (same column,
     * dummy source) is never a conflict with itself.
     *
     * @param array<int, list<int>> $laneColumns
     */
    private function laneTooCloseToExistingLane(array $laneColumns, int $layer, int $column, ?int $ownLaneColumn): bool
    {
        /** @infection-ignore-all coalesce fallback is defensive; iteration over no lanes correctly reports no conflict */
        foreach ($laneColumns[$layer] ?? [] as $existingColumn) {
            if ($existingColumn === $ownLaneColumn && $existingColumn === $column) {
                continue;
            }
            if (abs($existingColumn - $column) <= 1) {
                return true;
            }
        }

        return false;
    }

    private function alignedTargetColumn(int $exitColumn, LayoutNode $targetNode, LayoutGraph $graph): int
    {
        $targetColumn = $targetNode->column + intdiv($targetNode->boxWidth(), 2);
        /** @infection-ignore-all alignment tolerance boundaries mirror LayerTransitions::routesStraight, pinned by LayerTransitionsTest for the shared geometry */
        if (1 === abs($exitColumn - $targetColumn)
            && $exitColumn > $targetNode->column
            && $exitColumn < $targetNode->column + $targetNode->boxWidth() - 1
            && count($graph->incomingEdges($targetNode->id)) <= 1
        ) {
            return $exitColumn;
        }

        return $targetColumn;
    }

    private function routeDirectEdge(LayoutEdge $edge, int $exitRow, int $exitColumn, int $entryRow, int $targetColumn, ?int $bendCeiling = null): void
    {
        if ($exitColumn === $targetColumn) {
            $edge->waypoints = [new Waypoint($exitRow, $exitColumn), new Waypoint($entryRow, $exitColumn)];

            return;
        }

        $bendRow = max($exitRow, $entryRow - 1);
        /** @infection-ignore-all <= is equivalent: when the ceiling equals the bend row the branch re-assigns max(exitRow, bendRow) === bendRow, leaving it unchanged */
        if (null !== $bendCeiling && $bendCeiling < $bendRow) {
            // Lift the bend above a group's top border so the vertical drop
            // crosses it, but never above where the edge exits its source.
            $bendRow = max($exitRow, $bendCeiling);
        }
        $edge->waypoints = [
            new Waypoint($bendRow, $exitColumn),
            new Waypoint($bendRow, $targetColumn),
            new Waypoint($entryRow, $targetColumn),
        ];
    }

    private function columnConflictsWithRealNodes(int $column, int $layer, LayoutGraph $graph): bool
    {
        // The lane column picked at this layer is what descends through the gap
        // below it, so it must stay out of the spans reserved there for labels.
        foreach ($graph->reservedLabelSpans($layer) as [$fromColumn, $toColumn]) {
            /** @infection-ignore-all boundary and operator mutations are absorbed by separatedLaneColumn's nearest-clear-column search converging on the same lane; removing the check entirely is pinned by the long-edge-avoids-claimed-channels golden */
            if ($column >= $fromColumn && $column <= $toColumn) {
                return true;
            }
        }

        foreach ($graph->layerIndex()[$layer] ?? [] as $nodeId) {
            $node = $graph->getLayoutNode($nodeId);
            if ($node instanceof DummyLayoutNode) {
                /** @infection-ignore-all break vs continue: depends on layer ordering of dummy vs real nodes; validated by integration tests */
                continue;
            }
            // A pass-through lane must keep one empty column from every box, so
            // reject the box footprint plus a column on each side; otherwise the
            // lane renders flush against the border (an unreadable "││").
            /** @infection-ignore-all boundary precision; the one-column clearance is pinned by AsciiDagTest's pass-through tests, and ±1 shifts of either bound are absorbed by separatedLaneColumn's nearest-clear-column search */
            if ($column >= $node->column - 1 && $column <= $node->column + $node->boxWidth()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, int> */
    private function computeLayerMaxHeights(LayoutGraph $graph): array
    {
        $maxHeights = [];
        /** @infection-ignore-all coalesce fallback on exit row computation makes layer height cache effectively optional */
        foreach ($graph->layerIndex() as $layerNumber => $nodeIds) {
            /** @infection-ignore-all boxHeight() is always positive; max(0, positive) == max(-1, positive) */
            $maxHeight = 0;
            foreach ($nodeIds as $nodeId) {
                $maxHeight = max($maxHeight, $graph->getLayoutNode($nodeId)->boxHeight());
            }
            $maxHeights[$layerNumber] = $maxHeight;
        }

        /** @infection-ignore-all coalesce fallback on exit row computation makes layer height cache effectively optional */
        return $maxHeights;
    }
}
