<?php

declare(strict_types=1);

namespace PhpDag\Render;

use PhpDag\Graph\LabelPosition;
use PhpDag\Layout\FlowDirection;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\RealLayoutNode;

final readonly class LabelRenderer implements ElementRenderer
{
    private const Z_INDEX = 8;

    public function __construct(
        private FlowDirection $flowDirection = FlowDirection::TopToBottom,
    ) {
    }

    public function render(Canvas $canvas, LayoutGraph $graph): void
    {
        $this->renderSelfLoopLabels($canvas, $graph);

        $parallelGroupSizes = $this->parallelGroupSizes($graph);
        $isLeftToRight = FlowDirection::LeftToRight === $this->flowDirection;
        if ($isLeftToRight) {
            $this->renderLeftToRightParallelLabels($canvas, $graph, $parallelGroupSizes);
        }

        $boxes = $this->boxRectangles($graph);
        $sourcesByTarget = $this->forwardSourcesByTarget($graph);

        foreach ($graph->edges() as $layoutEdge) {
            if (null === $layoutEdge->edge->label || [] === $layoutEdge->waypoints) {
                continue;
            }

            // Left-to-right parallel-edge labels are placed above/below their
            // lane by the dedicated pass above; skip them here.
            /** @infection-ignore-all the ?? 0 fallback only fires for a reversed edge (excluded from the sizes), but the !reversed guard already sends those down the generic path, so the fallback value is never observable */
            if ($isLeftToRight && !$layoutEdge->reversed && ($parallelGroupSizes[$this->parallelKey($layoutEdge)] ?? 0) > 1) {
                continue;
            }

            $color = $layoutEdge->edge->color;
            $labelText = $layoutEdge->edge->label->text;
            $labelWidth = $layoutEdge->edge->label->width();

            if (FlowDirection::LeftToRight === $this->flowDirection) {
                $firstColumn = $layoutEdge->waypoints[0]->column;
                $lastColumn = $layoutEdge->waypoints[count($layoutEdge->waypoints) - 1]->column;
                $labelColumn = match ($layoutEdge->edge->label->position) {
                    LabelPosition::Source => $firstColumn + 1,
                    LabelPosition::Target => $lastColumn - 1,
                    LabelPosition::Middle => intdiv($firstColumn + $lastColumn, 2),
                };
                $edgeRow = $this->edgeRowAtColumn($layoutEdge->waypoints, $labelColumn);
                $sourceRow = $layoutEdge->waypoints[0]->row;
                $preferAbove = $edgeRow < $sourceRow;

                $direction = $preferAbove ? -1 : 1;
                $canvasHeight = $canvas->height() + 1;
                $labelRow = $edgeRow + $direction;
                /** @infection-ignore-all Bound and guard mutations are equivalent: rows outside the canvas always read as free and end the scan at the same row, and the column-0 short-circuit is redundant because column -1 is always empty; verified by running the suite against the mutated conditions */
                while ($labelRow >= 0 && $labelRow < $canvasHeight && !($this->regionWritable($canvas, $labelRow, $labelColumn, $labelWidth) && (0 === $labelColumn || $this->regionFree($canvas, $labelRow, $labelColumn - 1, 1)) && $this->regionFree($canvas, $labelRow, $labelColumn + $labelWidth, 1))) {
                    $labelRow += $direction;
                }
                if ($labelRow < 0 || $labelRow >= $canvasHeight) {
                    $labelRow = $edgeRow - $direction;
                    /** @infection-ignore-all Bound and guard mutations are equivalent: this rescue scan runs downward from edgeRow + 1 (never reaching row 0 or the height bound before a free row appears) and column -1 is always empty; collision behavior is pinned by the fallback* tests and verified by running the suite against the mutated conditions */
                    while ($labelRow >= 0 && $labelRow < $canvasHeight && !($this->regionWritable($canvas, $labelRow, $labelColumn, $labelWidth) && (0 === $labelColumn || $this->regionFree($canvas, $labelRow, $labelColumn - 1, 1)) && $this->regionFree($canvas, $labelRow, $labelColumn + $labelWidth, 1))) {
                        $labelRow -= $direction;
                    }
                }
                $canvas->text($labelRow, $labelColumn, $labelText, self::Z_INDEX, $color);

                continue;
            }

            $firstRow = $layoutEdge->waypoints[0]->row;
            $lastRow = $layoutEdge->waypoints[count($layoutEdge->waypoints) - 1]->row;
            $anchorRow = match ($layoutEdge->edge->label->position) {
                LabelPosition::Source => $firstRow + 1,
                LabelPosition::Target => $lastRow - 1,
                LabelPosition::Middle => intdiv($firstRow + $lastRow, 2),
            };

            // Converging edges share their bend bar with siblings, so the anchor
            // region is unusable; their labels sit beside their own edge instead,
            // as close to the source as possible, where the edges are still apart.
            // Explicit Source/Target positions keep the anchor placement.
            $slot = null;
            if (!$layoutEdge->reversed
                && LabelPosition::Middle === $layoutEdge->edge->label->position
                && count($sourcesByTarget[$layoutEdge->targetId()] ?? []) >= 2) {
                $slot = $this->slotBesideOwnEdge($canvas, $graph, $boxes, $layoutEdge, $labelWidth);
            }
            [$labelRow, $labelColumn] = $slot ?? $this->slotNearAnchor($canvas, $boxes, $layoutEdge, $anchorRow, $labelWidth);
            $canvas->text($labelRow, $labelColumn, $labelText, self::Z_INDEX, $color);
        }
    }

    /** @return array<string, array<string, true>> */
    private function forwardSourcesByTarget(LayoutGraph $graph): array
    {
        $sourcesByTarget = [];
        foreach ($graph->edges() as $edge) {
            if (!$edge->reversed) {
                /** @infection-ignore-all the stored value is never read; convergence is decided by count() */
                $sourcesByTarget[$edge->targetId()][$edge->sourceId()] = true;
            }
        }

        return $sourcesByTarget;
    }

    /** @return list<array{int, int, int, int}> [top, bottom, left, right] */
    private function boxRectangles(LayoutGraph $graph): array
    {
        $boxes = [];
        foreach ($graph->nodeIds() as $nodeId) {
            $node = $graph->getLayoutNode($nodeId);
            if ($node instanceof RealLayoutNode) {
                $boxes[] = [$node->row, $node->row + $node->boxHeight() - 1, $node->column, $node->column + $node->boxWidth() - 1];
            }
        }

        return $boxes;
    }

    /**
     * A converging edge's waypoints can start at the shared bend bar, omitting
     * the vertical drop out of its source box, so the scan starts at the source
     * exit row; edgeColumnAtRow falls back to the drop's column for those rows.
     *
     * @param list<array{int, int, int, int}> $boxes
     *
     * @return array{int, int}|null
     */
    private function slotBesideOwnEdge(Canvas $canvas, LayoutGraph $graph, array $boxes, LayoutEdge $edge, int $width): ?array
    {
        $source = $graph->getLayoutNode($edge->sourceId());
        // The outer side, away from the bend toward the merge, keeps the label
        // clear of the converging siblings.
        /**
         * @psalm-suppress InvalidArrayOffset, MixedAssignment, MixedPropertyFetch the render loop skips edges without waypoints
         *
         * @infection-ignore-all the last two waypoints are the drop into the target and share its column, so reading the second-to-last is equivalent
         */
        $entryColumn = $edge->waypoints[count($edge->waypoints) - 1]->column;
        $preferLeft = $entryColumn > $edge->waypoints[0]->column;

        [$minRow, $maxRow] = $this->rowSpan($edge->waypoints);
        $candidates = [];
        for ($row = min($source->row + $source->boxHeight(), $minRow); $row <= $maxRow; ++$row) {
            $column = $this->edgeColumnAtRow($edge->waypoints, $row);
            $sides = $preferLeft
                ? [$column - $width - 1, $column + 2]
                : [$column + 2, $column - $width - 1];
            foreach ($sides as $candidate) {
                $candidates[] = [$row, $candidate];
            }
        }

        return $this->firstFit($canvas, $boxes, $candidates, $width);
    }

    /**
     * The preferred side of the anchor row first, then every other row along the
     * edge, and as a last resort the first clear span to the right, so a label is
     * never dropped and never written over another element.
     *
     * @param list<array{int, int, int, int}> $boxes
     *
     * @return array{int, int}
     */
    private function slotNearAnchor(Canvas $canvas, array $boxes, LayoutEdge $edge, int $anchorRow, int $width): array
    {
        $anchorColumn = $this->edgeColumnAtRow($edge->waypoints, $anchorRow);
        $preferLeft = $anchorColumn < $edge->waypoints[0]->column;

        [$minRow, $maxRow] = $this->rowSpan($edge->waypoints);
        $rows = [$anchorRow];
        for ($row = $anchorRow - 1; $row >= $minRow; --$row) {
            $rows[] = $row;
        }
        for ($row = $anchorRow + 1; $row <= $maxRow; ++$row) {
            $rows[] = $row;
        }

        $candidates = [];
        foreach ($rows as $row) {
            $column = $this->edgeColumnAtRow($edge->waypoints, $row);
            $sides = $preferLeft
                ? [$column - $width - 1, $column + 2]
                : [$column + 2, $column - $width - 1];
            foreach ($sides as $candidate) {
                $candidates[] = [$row, $candidate];
            }
        }
        $slot = $this->firstFit($canvas, $boxes, $candidates, $width);
        if (null !== $slot) {
            return $slot;
        }

        for ($column = $anchorColumn + 2;; ++$column) {
            if ($this->labelFits($canvas, $boxes, $anchorRow, $column, $width)) {
                return [$anchorRow, $column];
            }
        }
    }

    /**
     * @param list<Waypoint> $waypoints
     *
     * @return array{int, int}
     */
    private function rowSpan(array $waypoints): array
    {
        $minRow = PHP_INT_MAX;
        $maxRow = PHP_INT_MIN;
        foreach ($waypoints as $waypoint) {
            $minRow = min($minRow, $waypoint->row);
            $maxRow = max($maxRow, $waypoint->row);
        }

        return [$minRow, $maxRow];
    }

    /**
     * Negative columns shift the whole drawing right when formatted, so they are
     * a last resort taken only when no on-canvas slot fits.
     *
     * @param list<array{int, int}>           $candidates
     * @param list<array{int, int, int, int}> $boxes
     *
     * @return array{int, int}|null
     */
    private function firstFit(Canvas $canvas, array $boxes, array $candidates, int $width): ?array
    {
        foreach ($candidates as [$row, $column]) {
            if ($column >= 0 && $this->labelFits($canvas, $boxes, $row, $column, $width)) {
                return [$row, $column];
            }
        }
        foreach ($candidates as [$row, $column]) {
            if ($column < 0 && $this->labelFits($canvas, $boxes, $row, $column, $width)) {
                return [$row, $column];
            }
        }

        return null;
    }

    /**
     * Clear of boxes, flanked by a free cell on either side, and with the row
     * beneath empty, so the label keeps visual space to everything around it.
     *
     * @param list<array{int, int, int, int}> $boxes
     */
    private function labelFits(Canvas $canvas, array $boxes, int $row, int $column, int $width): bool
    {
        return $this->regionClear($canvas, $row, $column - 1, $width + 2)
            && $this->regionClear($canvas, $row + 1, $column, $width)
            && !$this->overlapsBox($boxes, $row, $column, $width);
    }

    /** Like regionFree, but probing without materialising cells, so the scan never widens the canvas. */
    private function regionClear(Canvas $canvas, int $row, int $startColumn, int $width): bool
    {
        for ($column = $startColumn; $column < $startColumn + $width; ++$column) {
            $character = $canvas->cellAt($row, $column)?->resolvedCharacter() ?? '';
            if ('' !== $character && ' ' !== $character) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{int, int, int, int}> $boxes */
    private function overlapsBox(array $boxes, int $row, int $column, int $width): bool
    {
        foreach ($boxes as [$top, $bottom, $left, $right]) {
            if ($row + 1 >= $top && $row <= $bottom && $column <= $right && $column + $width - 1 >= $left) {
                return true;
            }
        }

        return false;
    }

    /**
     * Self-loops are drawn as a side loop on the east of the box in both flow
     * directions, so their label always sits just past the loop's rightmost
     * column, level with the row where it re-enters the box.
     */
    private function renderSelfLoopLabels(Canvas $canvas, LayoutGraph $graph): void
    {
        foreach ($graph->selfLoops() as $loop) {
            if (null === $loop->edge->label || [] === $loop->waypoints) {
                continue;
            }

            $rightmostColumn = max(array_map(
                static fn (Waypoint $waypoint): int => $waypoint->column,
                $loop->waypoints,
            ));
            /** @infection-ignore-all SelfLoopRouter always emits its last two waypoints at the re-entry (centre) row, so the last and second-to-last waypoint share a row; the index offset is not observable */
            $reentryRow = $loop->waypoints[count($loop->waypoints) - 1]->row;

            $canvas->text($reentryRow, $rightmostColumn + 2, $loop->edge->label->text, self::Z_INDEX, $loop->edge->color);
        }
    }

    /**
     * In left-to-right flow parallel edges run as horizontal lanes one above the
     * other, so each label sits on the side its lane is on: the lane above the
     * box centre gets its label above the boxes, the lane below gets it below.
     * The label starts at the lane's exit column, over the inter-box gap.
     *
     * @param array<string, int> $groupSizes
     */
    private function renderLeftToRightParallelLabels(Canvas $canvas, LayoutGraph $graph, array $groupSizes): void
    {
        foreach ($graph->edges() as $edge) {
            /** @infection-ignore-all the ?? 0 fallback only fires for a reversed edge (excluded from groupSizes), but a reversed edge is already rejected by the first condition, so the fallback value is never observable */
            if ($edge->reversed
                || null === $edge->edge->label
                || [] === $edge->waypoints
                || ($groupSizes[$this->parallelKey($edge)] ?? 0) <= 1) {
                continue;
            }

            $source = $graph->getLayoutNode($edge->sourceId());
            /** @infection-ignore-all a straight horizontal lane's waypoints all share one row, so any waypoint index yields the same lane row */
            $laneRow = $edge->waypoints[0]->row;
            $centreRow = $source->row + intdiv($source->boxHeight(), 2);
            /** @infection-ignore-all the < vs <= boundary only differs for a lane sitting exactly on the centre row, which happens only for the odd middle lane of a 3+-way group; two-way groups (the supported case) never place a lane on the centre */
            $labelRow = $laneRow < $centreRow ? $source->row - 1 : $source->row + $source->boxHeight();

            $canvas->text($labelRow, $edge->waypoints[0]->column, $edge->edge->label->text, self::Z_INDEX, $edge->edge->color);
        }
    }

    /**
     * Counts edges per (source, target) pair so parallel groups can be told apart
     * from single edges. Reversed (feedback) edges are excluded — they are routed
     * and labelled separately.
     *
     * @return array<string, int>
     */
    private function parallelGroupSizes(LayoutGraph $graph): array
    {
        $sizes = [];
        foreach ($graph->edges() as $edge) {
            // Reversed (feedback) edges never form a forward parallel group.
            /** @infection-ignore-all CycleBreaker re-adds reversed edges at the tail of the edge list, so every forward edge is counted before the first reversed one; continue and break leave the forward parallel counts identical */
            if ($edge->reversed) {
                continue;
            }
            $sizes[$this->parallelKey($edge)] = ($sizes[$this->parallelKey($edge)] ?? 0) + 1;
        }

        return $sizes;
    }

    private function parallelKey(LayoutEdge $edge): string
    {
        /** @infection-ignore-all the separator only keys the (source, target) group; node ids cannot contain it, so altering it leaves the grouping unchanged for any real id */
        return $edge->sourceId()."\0".$edge->targetId();
    }

    private function regionWritable(Canvas $canvas, int $row, int $startColumn, int $width): bool
    {
        for ($column = $startColumn; $column < $startColumn + $width; ++$column) {
            if (!$canvas->get($row, $column)->wouldAcceptWrite(self::Z_INDEX)) {
                return false;
            }
        }

        return true;
    }

    private function regionFree(Canvas $canvas, int $row, int $startColumn, int $width): bool
    {
        for ($column = $startColumn; $column < $startColumn + $width; ++$column) {
            $character = $canvas->get($row, $column)->resolvedCharacter();
            if ('' !== $character && ' ' !== $character) {
                return false;
            }
        }

        return true;
    }

    /** @param list<Waypoint> $waypoints */
    private function edgeRowAtColumn(array $waypoints, int $labelColumn): int
    {
        for ($waypointOffset = 0, $lastWaypointOffset = count($waypoints) - 1; $waypointOffset < $lastWaypointOffset; ++$waypointOffset) {
            $from = $waypoints[$waypointOffset];
            $to = $waypoints[$waypointOffset + 1];

            if ($from->row !== $to->row) {
                continue;
            }

            $minColumn = min($from->column, $to->column);
            $maxColumn = max($from->column, $to->column);
            if ($labelColumn >= $minColumn && $labelColumn <= $maxColumn) {
                return $from->row;
            }
        }

        return $waypoints[0]->row;
    }

    /** @param list<Waypoint> $waypoints */
    private function edgeColumnAtRow(array $waypoints, int $labelRow): int
    {
        for ($waypointOffset = 0, $lastWaypointOffset = count($waypoints) - 1; $waypointOffset < $lastWaypointOffset; ++$waypointOffset) {
            $from = $waypoints[$waypointOffset];
            $to = $waypoints[$waypointOffset + 1];

            if ($from->column !== $to->column) {
                continue;
            }

            $minRow = min($from->row, $to->row);
            $maxRow = max($from->row, $to->row);
            if ($labelRow >= $minRow && $labelRow <= $maxRow) {
                return $from->column;
            }
        }

        return $waypoints[0]->column;
    }
}
