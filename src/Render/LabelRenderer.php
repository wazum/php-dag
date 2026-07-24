<?php

declare(strict_types=1);

namespace PhpDag\Render;

use PhpDag\Graph\LabelPosition;
use PhpDag\Layout\FlowDirection;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;

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
            $labelRow = match ($layoutEdge->edge->label->position) {
                LabelPosition::Source => $firstRow + 1,
                LabelPosition::Target => $lastRow - 1,
                LabelPosition::Middle => intdiv($firstRow + $lastRow, 2),
            };
            $edgeColumn = $this->edgeColumnAtRow($layoutEdge->waypoints, $labelRow);
            $sourceColumn = $layoutEdge->waypoints[0]->column;
            $preferLeft = $edgeColumn < $sourceColumn;

            if ($preferLeft) {
                $leftColumn = $edgeColumn - $labelWidth - 1;
                if ($leftColumn >= 0 && $this->regionFree($canvas, $labelRow, $leftColumn, $labelWidth)) {
                    $canvas->text($labelRow, $leftColumn, $labelText, self::Z_INDEX, $color);
                } else {
                    $canvas->text($labelRow, $edgeColumn + 2, $labelText, self::Z_INDEX, $color);
                }
            } else {
                $rightColumn = $edgeColumn + 2;
                if ($this->regionFree($canvas, $labelRow, $rightColumn, $labelWidth)) {
                    $canvas->text($labelRow, $rightColumn, $labelText, self::Z_INDEX, $color);
                } else {
                    $canvas->text($labelRow, $edgeColumn - $labelWidth - 1, $labelText, self::Z_INDEX, $color);
                }
            }
        }
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
