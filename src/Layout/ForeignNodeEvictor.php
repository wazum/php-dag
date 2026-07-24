<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Graph\Group;

/**
 * Pushes non-member nodes out of a group's column band when they are trapped
 * inside its bounding box. A node is trapped when its dependencies interleave
 * with the group's (so layer assignment drops it on a layer the group spans)
 * and it lands within the members' horizontal extent — a single rectangular
 * border cannot exclude such a node, so the EdgeRouter would draw it inside the
 * cluster. Evicting it to the nearer side reserves a clean lane (shifting any
 * content already there) so members keep a contiguous band and the foreign
 * node's edges simply cross the border. Runs after positioning, before
 * GroupSpacer reserves the ring.
 */
final readonly class ForeignNodeEvictor implements Processor
{
    /** Empty rows/columns kept between an evicted node and the member band. */
    private const GAP = 2;

    public function __construct(
        private FlowDirection $direction = FlowDirection::TopToBottom,
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        foreach ($graph->groups() as $group) {
            $this->evictForeignNodes($graph, $group);
        }
    }

    private function evictForeignNodes(LayoutGraph $graph, Group $group): void
    {
        $bounds = $this->memberBounds($graph, $group->nodeIds);
        if (null === $bounds) {
            return;
        }
        [$top, $bottom, $left, $right] = $bounds;
        // The cross axis is perpendicular to the flow: members are evicted
        // sideways in top-to-bottom flow (columns) and up/down in left-to-right
        // flow (rows).
        [$bandStart, $bandEnd] = FlowDirection::TopToBottom === $this->direction ? [$left, $right] : [$top, $bottom];

        $members = array_flip($group->nodeIds);
        foreach ($graph->nodeIds() as $nodeId) {
            if (isset($members[$nodeId])) {
                continue;
            }
            $node = $graph->getLayoutNode($nodeId);
            /** @infection-ignore-all dummies are zero-width edge waypoints, not boxes to exclude; the EdgeRouter routes them, so skipping them is required and break/continue differ only with dummies present */
            if ($node instanceof DummyLayoutNode) {
                continue;
            }
            if (!$this->overlapsBox($node, $top, $bottom, $left, $right)) {
                continue;
            }

            /** @infection-ignore-all the box centre only chooses which side a trapped node leaves by; both sides produce a valid layout that excludes it, so the divisor boundary is not a correctness property */
            $center = $this->crossStart($node) + intdiv($this->crossSize($node), 2);
            /** @infection-ignore-all the band centre only picks the nearer eviction side; either side yields a valid exclusion, so the comparison/divisor boundary is not a correctness property */
            if ($center <= intdiv($bandStart + $bandEnd, 2)) {
                $this->evictTowardStart($graph, $node, $bandStart);
                continue;
            }
            $this->evictTowardEnd($graph, $node, $bandEnd);
        }
    }

    private function crossStart(LayoutNode $node): int
    {
        return FlowDirection::TopToBottom === $this->direction ? $node->column : $node->row;
    }

    private function crossSize(LayoutNode $node): int
    {
        return FlowDirection::TopToBottom === $this->direction ? $node->boxWidth() : $node->boxHeight();
    }

    private function shiftCross(LayoutNode $node, int $delta): void
    {
        if (FlowDirection::TopToBottom === $this->direction) {
            $node->column += $delta;

            return;
        }
        $node->row += $delta;
    }

    private function setCross(LayoutNode $node, int $value): void
    {
        if (FlowDirection::TopToBottom === $this->direction) {
            $node->column = $value;

            return;
        }
        $node->row = $value;
    }

    private function overlapsBox(LayoutNode $node, int $top, int $bottom, int $left, int $right): bool
    {
        $nodeBottom = $node->row + $node->boxHeight() - 1;
        $nodeRight = $node->column + $node->boxWidth() - 1;

        return $node->row <= $bottom
            && $nodeBottom >= $top
            && $node->column <= $right
            && $nodeRight >= $left;
    }

    private function evictTowardStart(LayoutGraph $graph, LayoutNode $node, int $bandStart): void
    {
        $lane = $this->crossSize($node) + self::GAP;
        foreach ($graph->nodeIds() as $otherId) {
            if ($otherId === $node->id) {
                continue;
            }
            $other = $graph->getLayoutNode($otherId);
            if ($this->crossStart($other) < $bandStart) {
                $this->shiftCross($other, -$lane);
            }
        }
        $this->setCross($node, $bandStart - $lane);
    }

    private function evictTowardEnd(LayoutGraph $graph, LayoutNode $node, int $bandEnd): void
    {
        $lane = $this->crossSize($node) + self::GAP;
        foreach ($graph->nodeIds() as $otherId) {
            if ($otherId === $node->id) {
                continue;
            }
            $other = $graph->getLayoutNode($otherId);
            if ($this->crossStart($other) > $bandEnd) {
                $this->shiftCross($other, $lane);
            }
        }
        $this->setCross($node, $bandEnd + 1 + self::GAP);
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
        /** @infection-ignore-all with no present members a true seed returns the inverted sentinels, against which overlapsBox matches nothing — identical to the null/early-return path */
        $found = false;

        foreach ($nodeIds as $nodeId) {
            if (!$graph->hasNode($nodeId)) {
                continue;
            }
            $node = $graph->getLayoutNode($nodeId);
            $found = true;
            $top = min($top, $node->row);
            $bottom = max($bottom, $node->row + $node->boxHeight() - 1);
            $left = min($left, $node->column);
            $right = max($right, $node->column + $node->boxWidth() - 1);
        }

        return $found ? [$top, $bottom, $left, $right] : null;
    }
}
