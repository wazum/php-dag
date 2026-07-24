<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Graph\Group;

/**
 * Reserves an empty ring around each group so a border can be drawn without
 * touching member boxes, colliding with edge channels, or running off the
 * canvas. Runs after positioning and before routing, so edge waypoints are
 * computed against the final coordinates.
 *
 * This slice handles non-nested groups whose members occupy a contiguous
 * block (the common case, guaranteed for single groups and disjoint
 * per-layer/per-column groups). Overlapping or interleaved groups are a
 * documented follow-up.
 */
final readonly class GroupSpacer implements Processor
{
    /** Rows/columns of clearance between a group's members and its border line. */
    private const MARGIN = 2;

    public function __construct(
        private FlowDirection $direction = FlowDirection::TopToBottom,
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        $groups = $graph->groups();
        if ([] === $groups) {
            return;
        }

        $this->reserveBorderRows($graph, $groups);

        foreach ($groups as $group) {
            $bounds = $this->memberBounds($graph, $group->nodeIds);
            if (null === $bounds) {
                continue;
            }
            [$top, , $left, $right] = $bounds;

            // The label sits on the top border and is only crossed by entry
            // edges in top-to-bottom flow; in left-to-right flow edges cross the
            // side borders, so the label never needs the border widened.
            $leftPadding = FlowDirection::TopToBottom === $this->direction
                ? $this->labelLeftPadding($graph, $group, $top, $left, $right)
                : self::MARGIN;
            $graph->setGroupLeftPadding($group->id, $leftPadding);

            // Reserve the right ring before widening the left, so its threshold
            // still refers to the members' pre-shift columns.
            /** @infection-ignore-all the +1 only changes the threshold for columns inside the member band, which the contiguous-block precondition excludes; the following left shift already separates every outside column identically */
            $this->shiftColumnsAtOrRightOf($graph, $right + 1, self::MARGIN);
            $this->shiftColumnsAtOrRightOf($graph, $left, $leftPadding);
        }

        $this->normalizeToNonNegative($graph);
    }

    /**
     * Opens a MARGIN-row gap above each group's top member and below its bottom
     * member to hold the horizontal borders. Each distinct boundary is reserved
     * once, so groups that span the same rows (side-by-side clusters) share one
     * ring instead of stacking a separate gap per group.
     *
     * @param list<Group> $groups
     */
    private function reserveBorderRows(LayoutGraph $graph, array $groups): void
    {
        $boundaries = [];
        foreach ($groups as $group) {
            $bounds = $this->memberBounds($graph, $group->nodeIds);
            if (null === $bounds) {
                continue;
            }
            [$top, $bottom] = $bounds;
            /** @infection-ignore-all the map is read through array_keys(), so the stored true/false value is never observed */
            $boundaries[$top] = true;
            /** @infection-ignore-all true vs false is unobserved (keys only); the +1 only moves the boundary within the member band (bottom..bottom+1), which holds no node — the next layer sits a rank gap below — so every offset shifts the same nodes */
            $boundaries[$bottom + 1] = true;
        }

        foreach ($graph->nodeIds() as $nodeId) {
            $node = $graph->getLayoutNode($nodeId);
            $shift = 0;
            foreach (array_keys($boundaries) as $boundary) {
                if ($node->row >= $boundary) {
                    $shift += self::MARGIN;
                }
            }
            $node->row += $shift;
        }
    }

    /**
     * Left padding wide enough for the group label to sit on the top border
     * clear of every edge crossing. Defaults to the ring; only widens when the
     * label fits in no crossing-free gap, expanding leftward so it lands before
     * the leftmost crossing. Edges enter top members at their centers, so those
     * centers predict the crossings the EdgeRouter will draw.
     */
    private function labelLeftPadding(LayoutGraph $graph, Group $group, int $top, int $left, int $right): int
    {
        if ('' === $group->label) {
            return self::MARGIN;
        }
        $labelWidth = mb_strlen($group->label) + 2;

        $crossings = [];
        foreach ($group->nodeIds as $nodeId) {
            if (!$graph->hasNode($nodeId)) {
                continue;
            }
            $node = $graph->getLayoutNode($nodeId);
            if ($node->row === $top) {
                $crossings[] = $node->column + intdiv($node->boxWidth(), 2);
            }
        }
        if ([] === $crossings) {
            return self::MARGIN;
        }

        $interiorLeft = $left - self::MARGIN + 2;
        $interiorRight = $right + self::MARGIN - 1;
        if ($this->widestGap($interiorLeft, $interiorRight, $crossings) >= $labelWidth) {
            return self::MARGIN;
        }

        $leftGap = min($crossings) - $interiorLeft;

        /** @infection-ignore-all the max(0, …) only guards against a negative widening, but this branch runs only when the widest gap (>= leftGap) is below the label width, so labelWidth - leftGap is always positive and the guard never clamps */
        return self::MARGIN + max(0, $labelWidth - $leftGap);
    }

    /**
     * Widest run of columns in [$low, $high] containing no crossing.
     *
     * @param list<int> $crossings
     */
    private function widestGap(int $low, int $high, array $crossings): int
    {
        sort($crossings);
        /** @infection-ignore-all the loop only ever raises $widest with non-negative gaps and the trailing max() covers the empty case, so the 0 vs -1 starting value is recovered before return */
        $widest = 0;
        $cursor = $low;
        foreach ($crossings as $crossing) {
            /** @infection-ignore-all defensive: crossings are member centres, always within [low, high], so this skip never fires */
            if ($crossing < $low || $crossing > $high) {
                continue;
            }
            $widest = max($widest, $crossing - $cursor);
            $cursor = $crossing + 1;
        }

        return max($widest, $high - $cursor + 1);
    }

    /**
     * @param list<string> $nodeIds
     *
     * @return array{int, int, int, int}|null [top, bottom, left, right]
     */
    private function memberBounds(LayoutGraph $graph, array $nodeIds): ?array
    {
        $top = PHP_INT_MAX;
        $bottom = PHP_INT_MIN;
        $left = PHP_INT_MAX;
        $right = PHP_INT_MIN;
        $found = false;

        foreach ($nodeIds as $nodeId) {
            if (!$graph->hasNode($nodeId)) {
                continue;
            }
            $node = $graph->getLayoutNode($nodeId);
            $found = true;
            $top = min($top, $node->row);
            /** @infection-ignore-all the -1 (box extent) only feeds the bottom+1 shift threshold; under the contiguous-block precondition no outside row falls in the affected band, so any extent offset shifts the same nodes */
            $bottom = max($bottom, $node->row + $node->boxHeight() - 1);
            $left = min($left, $node->column);
            /** @infection-ignore-all the -1 (box extent) only feeds the right+1 shift threshold; under the contiguous-block precondition no outside column falls in the affected band, so any extent offset shifts the same nodes */
            $right = max($right, $node->column + $node->boxWidth() - 1);
        }

        return $found ? [$top, $bottom, $left, $right] : null;
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

    private function normalizeToNonNegative(LayoutGraph $graph): void
    {
        $minRow = PHP_INT_MAX;
        $minColumn = PHP_INT_MAX;
        foreach ($graph->nodeIds() as $nodeId) {
            $node = $graph->getLayoutNode($nodeId);
            $minRow = min($minRow, $node->row);
            $minColumn = min($minColumn, $node->column);
        }

        $rowShift = max(0, self::MARGIN - $minRow);
        $columnShift = max(0, self::MARGIN - $minColumn);
        /** @infection-ignore-all pure short-circuit optimization: both shifts are max(0, …) so they are never negative; skipping the loop when both are 0 is identical to running it and adding 0 to every coordinate */
        if (0 === $rowShift && 0 === $columnShift) {
            return;
        }

        foreach ($graph->nodeIds() as $nodeId) {
            $node = $graph->getLayoutNode($nodeId);
            $node->row += $rowShift;
            $node->column += $columnShift;
        }
    }
}
