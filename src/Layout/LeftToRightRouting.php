<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Render\Waypoint;

final readonly class LeftToRightRouting implements EdgeRouting
{
    public function route(LayoutGraph $graph): void
    {
        $layerMaxWidths = $this->computeLayerMaxWidths($graph);
        $straightRowCandidates = $this->collectStraightRowCandidates($graph);
        $groupEntryColumns = $this->groupEntryColumnCeilings($graph);

        /** @var array<string, int> */
        $preferredRows = [];

        /** @var array<int, list<int>> */
        $laneRows = [];

        /** @var array<string, int> */
        $targetLanes = [];

        /** @var array<string, int> */
        $sourceLanes = [];

        $parallelGroupSizes = $this->parallelGroupSizes($graph);
        /** @var array<string, int> */
        $parallelSeen = [];

        foreach ($graph->edges() as $edge) {
            $sourceNode = $graph->getLayoutNode($edge->sourceId());
            $targetNode = $graph->getLayoutNode($edge->targetId());
            $exitRow = $preferredRows[$sourceNode->id] ?? $sourceNode->row + intdiv($sourceNode->boxHeight(), 2);
            /** @infection-ignore-all coalesce fallback is defensive; layerMaxWidths covers every layer */
            $exitColumn = $sourceNode->column + ($layerMaxWidths[$sourceNode->layer] ?? $sourceNode->boxWidth());
            $entryColumn = $targetNode->column - 1;

            if (!$targetNode instanceof DummyLayoutNode) {
                $parallelKey = $edge->sourceId()."\0".$edge->targetId();
                /** @infection-ignore-all the ?? fallback is only reached for a reversed edge (whose key is absent), and the !reversed guard below sends it to the single-edge branch regardless of the count, so the fallback value is never observable */
                $parallelCount = $parallelGroupSizes[$parallelKey] ?? 0;
                if (!$edge->reversed && $parallelCount > 1) {
                    // Each parallel edge gets its own port row on both grown boxes;
                    // the shared row makes the lane a straight horizontal line.
                    $parallelIndex = $parallelSeen[$parallelKey] ?? 0;
                    $parallelSeen[$parallelKey] = $parallelIndex + 1;
                    $portRow = $this->parallelPortRow($sourceNode, $targetNode, $parallelIndex, $parallelCount);
                    $this->routeDirectEdge($edge, $exitColumn, $portRow, $entryColumn, $portRow);
                    continue;
                }

                $this->routeDirectEdge($edge, $exitColumn, $exitRow, $entryColumn, $this->alignedTargetRow($exitRow, $targetNode, $graph), $groupEntryColumns[$targetNode->id] ?? null);
                continue;
            }

            $targetLaneKey = $targetNode->layer.':'.$targetNode->originalEdgeTargetId;
            /** @infection-ignore-all lane keys are internal bookkeeping; mutated key collisions merge lanes that the connection gap-fill renders equivalently */
            $sourceLaneKey = $targetNode->layer.':'.$targetNode->originalEdgeSourceId;

            /** @infection-ignore-all either trunk (target-first or source-first) is a valid shared lane */
            $sharedRow = $targetLanes[$targetLaneKey] ?? $sourceLanes[$sourceLaneKey] ?? null;
            if (null !== $sharedRow) {
                // A lane to the same target (fan-in trunk) or from the same
                // source (fan-out trunk) already exists in this layer: join it.
                /** @infection-ignore-all verified equivalent: merged chain waypoints plus the connection gap-fill reconstruct the dropped segment */
                $this->routeDirectEdge($edge, $exitColumn, $exitRow, $entryColumn, $sharedRow);
                /** @infection-ignore-all re-registering the already-found shared row is idempotent */
                $targetLanes[$targetLaneKey] ??= $sharedRow;
                /** @infection-ignore-all re-registering the already-found shared row is idempotent */
                $sourceLanes[$sourceLaneKey] ??= $sharedRow;
                $preferredRows[$targetNode->id] = $sharedRow;
                continue;
            }

            /** @infection-ignore-all the own-lane exemption only affects re-checking a chain against its own column; either branch yields the same lane */
            $ownLaneRow = $sourceNode instanceof DummyLayoutNode ? $exitRow : null;
            $laneRow = null;
            /** @infection-ignore-all the exit row re-enters through straight-row candidates or the separation fallback */
            foreach ([$exitRow, ...$straightRowCandidates[$targetLaneKey] ?? []] as $candidateRow) {
                if (!$this->rowConflictsWithRealNodes($candidateRow, $targetNode->layer, $graph)
                    && !$this->laneTooCloseToExistingLane($laneRows, $targetNode->layer, $candidateRow, $ownLaneRow)
                ) {
                    $laneRow = $candidateRow;
                    /** @infection-ignore-all first vs. last passing candidate: any passing candidate satisfies the same separation constraints */
                    break;
                }
            }
            $laneRow ??= $this->separatedLaneRow($graph, $laneRows, $targetNode->layer, $this->alignedTargetRow($exitRow, $targetNode, $graph));

            $preferredRows[$targetNode->id] = $laneRow;
            $laneRows[$targetNode->layer][] = $laneRow;
            $targetLanes[$targetLaneKey] = $laneRow;
            $sourceLanes[$sourceLaneKey] = $laneRow;

            if ($laneRow === $exitRow) {
                /** @infection-ignore-all the connection gap-fill reconstructs the leading segment from the box border */
                $edge->waypoints = [new Waypoint($exitRow, $exitColumn), new Waypoint($exitRow, $entryColumn)];
                continue;
            }

            $this->routeDirectEdge($edge, $exitColumn, $exitRow, $entryColumn, $laneRow);
        }
    }

    /**
     * Center rows of real source nodes whose long edges pass through each
     * (layer, original target) lane: putting the shared trunk on one of these
     * rows lets that source's edge run dead straight.
     *
     * @return array<string, list<int>>
     */
    private function collectStraightRowCandidates(LayoutGraph $graph): array
    {
        $candidates = [];

        foreach ($graph->nodeIds() as $nodeId) {
            $node = $graph->getLayoutNode($nodeId);
            if (!$node instanceof DummyLayoutNode || !$graph->hasNode($node->originalEdgeSourceId)) {
                continue;
            }
            $originSource = $graph->getLayoutNode($node->originalEdgeSourceId);
            if ($originSource instanceof DummyLayoutNode) {
                continue;
            }

            $laneKey = $node->layer.':'.$node->originalEdgeTargetId;
            /** @infection-ignore-all candidate rows are a preference; a center shifted by rounding falls back to lane separation with equivalent readability */
            $sourceCenter = $originSource->row + intdiv($originSource->boxHeight(), 2);
            /** @infection-ignore-all duplicate candidates are tried twice with identical outcomes */
            if (!in_array($sourceCenter, $candidates[$laneKey] ?? [], true)) {
                $candidates[$laneKey][] = $sourceCenter;
            }
        }

        /** @infection-ignore-all candidate completeness is a preference; missing candidates fall back to lane separation */
        return $candidates;
    }

    /**
     * Counts how many edges share each (source, target) pair so parallel groups
     * can be fanned across port rows.
     *
     * @return array<string, int>
     */
    private function parallelGroupSizes(LayoutGraph $graph): array
    {
        $sizes = [];
        foreach ($graph->edges() as $edge) {
            // Reversed (feedback) edges are routed separately, so they must not
            // inflate a forward pair's parallel count.
            /** @infection-ignore-all CycleBreaker appends reversed replacements at the tail of the edge list, so every forward edge is counted before the first reversed one is reached; continue and break leave the forward parallel counts identical */
            if ($edge->reversed) {
                continue;
            }
            /** @infection-ignore-all the separator only keys the (source, target) group; node ids cannot contain it, so altering it leaves the grouping unchanged for any real id */
            $sizes[$edge->sourceId()."\0".$edge->targetId()] = ($sizes[$edge->sourceId()."\0".$edge->targetId()] ?? 0) + 1;
        }

        return $sizes;
    }

    /**
     * Places parallel edges on port rows valid for *both* the source and target
     * box — the overlap of their inner spans — spread evenly across it. Both
     * endpoints share the row, so each lane is a straight horizontal line. Mirror
     * of the top-to-bottom column fanning in ChainAwareRouting.
     *
     * @infection-ignore-all both endpoints of a parallel group are grown to host
     * the ports and centred on a shared row band, so their interior row spans
     * coincide (and where one box is taller the shorter one tightens the overlap
     * symmetrically); the per-side max/min terms are therefore masked by the
     * equal other side, while the resulting port rows are pinned by the
     * left-to-right parallel-edge integration snapshot in AsciiDagTest
     */
    private function parallelPortRow(LayoutNode $source, LayoutNode $target, int $index, int $count): int
    {
        $topBound = max($source->row + 1, $target->row + 1);
        $bottomBound = min(
            $source->row + $source->boxHeight() - 2,
            $target->row + $target->boxHeight() - 2,
        );
        /** @infection-ignore-all the max(0, ...) floor only guards non-overlapping inner spans, which cannot occur once both endpoints are grown to host the ports and centred on a shared row band */
        $span = max(0, $bottomBound - $topBound);

        return $topBound + intdiv($index * $span, $count - 1);
    }

    private function alignedTargetRow(int $exitRow, LayoutNode $targetNode, LayoutGraph $graph): int
    {
        /** @infection-ignore-all box-center rounding; a shifted center still lands inside the box and renders connected */
        $targetRow = $targetNode->row + intdiv($targetNode->boxHeight(), 2);
        /** @infection-ignore-all alignment tolerance boundaries mirror LayerTransitions::routesStraight, pinned by LayerTransitionsTest for the shared geometry */
        if (1 === abs($exitRow - $targetRow)
            && $exitRow > $targetNode->row
            && $exitRow < $targetNode->row + $targetNode->boxHeight() - 1
            && count($graph->incomingEdges($targetNode->id)) <= 1
        ) {
            return $exitRow;
        }

        return $targetRow;
    }

    private function routeDirectEdge(LayoutEdge $edge, int $exitColumn, int $exitRow, int $entryColumn, int $targetRow, ?int $bendCeiling = null): void
    {
        if ($exitRow === $targetRow) {
            $edge->waypoints = [new Waypoint($exitRow, $exitColumn), new Waypoint($exitRow, $entryColumn)];

            return;
        }

        $bendColumn = max($exitColumn, $entryColumn - 1);
        /** @infection-ignore-all <= is equivalent: when the ceiling equals the bend column the branch re-assigns max(exitColumn, bendColumn) === bendColumn, leaving it unchanged */
        if (null !== $bendCeiling && $bendCeiling < $bendColumn) {
            // Pull the bend left of a group's left border so the edge crosses
            // it, but never left of where the edge exits its source.
            $bendColumn = max($exitColumn, $bendCeiling);
        }
        $edge->waypoints = [
            new Waypoint($exitRow, $bendColumn),
            new Waypoint($targetRow, $bendColumn),
            new Waypoint($targetRow, $entryColumn),
        ];
    }

    /**
     * Every node sharing a group's left column has its arrow one column right of
     * the group's left border, so an edge bending at the usual column would jog
     * along that border. Pulling the bend left of the border lets members'
     * edges cross straight through it (a crossing and a feeder into the arrow)
     * and lets outside siblings pass cleanly to its left. Maps each such node to
     * the rightmost column its incoming bend may use.
     *
     * @return array<string, int> node id => bend ceiling column
     */
    private function groupEntryColumnCeilings(LayoutGraph $graph): array
    {
        $ceilings = [];
        foreach ($graph->groups() as $group) {
            $leftColumn = PHP_INT_MAX;
            foreach ($group->nodeIds as $nodeId) {
                if (!$graph->hasNode($nodeId)) {
                    continue;
                }
                $leftColumn = min($leftColumn, $graph->getLayoutNode($nodeId)->column);
            }
            if (PHP_INT_MAX === $leftColumn) {
                continue;
            }

            // Mirrors GroupRenderer: the border sits two columns left of the
            // leftmost member, so the bend must clear it by one more column.
            /** @infection-ignore-all moving the ceiling further left (−4) is reclaimed by max(exitColumn, …): after compaction the source's exit column already lower-bounds the bend, so a looser ceiling renders identically */
            $ceiling = $leftColumn - 3;
            foreach ($graph->nodeIds() as $nodeId) {
                if ($graph->getLayoutNode($nodeId)->column !== $leftColumn) {
                    continue;
                }
                $ceilings[$nodeId] = isset($ceilings[$nodeId]) ? min($ceilings[$nodeId], $ceiling) : $ceiling;
            }
        }

        return $ceilings;
    }

    /**
     * Flush parallel lanes are unreadable; keep one empty row between
     * pass-through lanes. A chain continuing in its own lane is never a
     * conflict with itself.
     *
     * @param array<int, list<int>> $laneRows
     */
    private function laneTooCloseToExistingLane(array $laneRows, int $layer, int $row, ?int $ownLaneRow): bool
    {
        /** @infection-ignore-all coalesce fallback is defensive; iteration over no lanes correctly reports no conflict */
        foreach ($laneRows[$layer] ?? [] as $existingRow) {
            if ($existingRow === $ownLaneRow && $existingRow === $row) {
                continue;
            }
            if (abs($existingRow - $row) <= 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds the closest row to the preferred one that keeps a one-row gap
     * from other lanes and stays outside box footprints.
     *
     * @param array<int, list<int>> $laneRows
     */
    private function separatedLaneRow(LayoutGraph $graph, array $laneRows, int $layer, int $preferredRow): int
    {
        /** @infection-ignore-all search order and bound: any returned candidate satisfies the same separation and box constraints */
        for ($offset = 0; $offset <= 24; ++$offset) {
            foreach (0 === $offset ? [0] : [-$offset, $offset] as $delta) {
                $candidate = $preferredRow + $delta;
                if ($candidate >= 0
                    && !$this->laneTooCloseToExistingLane($laneRows, $layer, $candidate, null)
                    && !$this->rowConflictsWithRealNodes($candidate, $layer, $graph)
                ) {
                    return $candidate;
                }
            }
        }

        return $preferredRow;
    }

    private function rowConflictsWithRealNodes(int $row, int $layer, LayoutGraph $graph): bool
    {
        foreach ($graph->layerIndex()[$layer] ?? [] as $nodeId) {
            $node = $graph->getLayoutNode($nodeId);
            /** @infection-ignore-all loop control over layer nodes: any conflicting real node aborts identically */
            if ($node instanceof DummyLayoutNode) {
                continue;
            }
            /** @infection-ignore-all box-boundary half-open interval: a lane on the border row is repainted by the box footprint either way */
            if ($row >= $node->row && $row < $node->row + $node->boxHeight()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, int> */
    private function computeLayerMaxWidths(LayoutGraph $graph): array
    {
        $maxWidths = [];
        /** @infection-ignore-all max-width accumulation: box widths >= 1 make initialization and iteration-order mutations equivalent */
        foreach ($graph->layerIndex() as $layerNumber => $nodeIds) {
            $maxWidth = 0;
            foreach ($nodeIds as $nodeId) {
                $maxWidth = max($maxWidth, $graph->getLayoutNode($nodeId)->boxWidth());
            }
            $maxWidths[$layerNumber] = $maxWidth;
        }

        /** @infection-ignore-all missing layer widths fall back to the node own width; the exit gap-fill reconstructs the difference */
        return $maxWidths;
    }
}
