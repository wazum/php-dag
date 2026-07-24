<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Graph\Group;

/**
 * Re-centres a cluster's members within their own band after foreign nodes have
 * been evicted. Positioning runs before eviction, so a member can be pushed off
 * to one side by a foreign sibling that is later removed; this pass slides each
 * member layer back to the band centre, so the cluster's interior reads like a
 * standalone DAG of just its members. Runs after ForeignNodeEvictor, before
 * GroupSpacer.
 */
final readonly class ClusterMemberCentering implements Processor
{
    public function __construct(
        private FlowDirection $direction = FlowDirection::TopToBottom,
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        foreach ($graph->groups() as $group) {
            $this->centerMembers($graph, $group);
        }
    }

    private function centerMembers(LayoutGraph $graph, Group $group): void
    {
        $bandStart = PHP_INT_MAX;
        $bandEnd = PHP_INT_MIN;
        /** @var array<int, list<LayoutNode>> */
        $byLayer = [];
        foreach ($group->nodeIds as $nodeId) {
            if (!$graph->hasNode($nodeId)) {
                continue;
            }
            $node = $graph->getLayoutNode($nodeId);
            $bandStart = min($bandStart, $this->crossStart($node));
            $bandEnd = max($bandEnd, $this->crossStart($node) + $this->crossSize($node) - 1);
            $byLayer[$node->layer][] = $node;
        }
        if (PHP_INT_MAX === $bandStart) {
            return;
        }
        $bandCenter = intdiv($bandStart + $bandEnd, 2);

        foreach ($byLayer as $nodes) {
            $start = PHP_INT_MAX;
            $end = PHP_INT_MIN;
            foreach ($nodes as $node) {
                $start = min($start, $this->crossStart($node));
                $end = max($end, $this->crossStart($node) + $this->crossSize($node) - 1);
            }
            $shift = $bandCenter - intdiv($start + $end, 2);
            foreach ($nodes as $node) {
                $this->shiftCross($node, $shift);
            }
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
}
