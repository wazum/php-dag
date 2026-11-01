<?php

declare(strict_types=1);

namespace PhpDag\Layout;

/**
 * Shifts one end of a parallel group sideways when that makes every lane of
 * the group straight: the source first, otherwise the target, and only when
 * its neighbours in the layer keep their spacing. Group members stay put,
 * since their borders were already sized around them.
 */
final readonly class ParallelPortAligner implements Processor
{
    public function __construct(
        private FlowDirection $flowDirection,
        private int $spacing,
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        $fixedNodeIds = [];
        foreach ($graph->groups() as $group) {
            foreach ($group->nodeIds as $nodeId) {
                $fixedNodeIds[$nodeId] = $nodeId;
            }
        }

        // Each group gets one try and fixes both its ends, so this settles. A
        // moved box changes the ports of its own groups and its neighbours'
        // groups, so those wait for the next round, which recomputes them.
        do {
            $staleNodeIds = [];
            $waiting = false;
            foreach (EdgePorts::of($graph, $this->flowDirection)->misalignedGroups as [$source, $target, $offset]) {
                if (isset($fixedNodeIds[$source->id], $fixedNodeIds[$target->id])) {
                    continue;
                }
                if (isset($staleNodeIds[$source->id]) || isset($staleNodeIds[$target->id])) {
                    $waiting = true;
                    /** @infection-ignore-all ending the round here only hands the remaining groups to the next round, which recomputes them all */
                    continue;
                }
                $moved = $this->tryShift($graph, $source, $offset, $fixedNodeIds) ? $source : ($this->tryShift($graph, $target, -$offset, $fixedNodeIds) ? $target : null);
                $fixedNodeIds[$source->id] = $source->id;
                $fixedNodeIds[$target->id] = $target->id;
                if (null !== $moved) {
                    foreach ([...$graph->successors($moved->id), ...$graph->predecessors($moved->id)] as $nodeId) {
                        $staleNodeIds[$nodeId] = $nodeId;
                    }
                }
            }
        } while ($waiting);
    }

    /**
     * @param array<string, string> $fixedNodeIds
     */
    private function tryShift(LayoutGraph $graph, LayoutNode $node, int $offset, array $fixedNodeIds): bool
    {
        if (isset($fixedNodeIds[$node->id])) {
            return false;
        }

        $layerNodeIds = $graph->layerIndex()[$node->layer] ?? [];
        $index = array_flip($layerNodeIds)[$node->id];
        $start = $this->start($node) + $offset;
        $before = $index > 0 ? $layerNodeIds[$index - 1] : null;
        $after = $layerNodeIds[$index + 1] ?? null;
        $fits = (null === $before || $start - $this->end($graph->getLayoutNode($before)) >= $this->spacing)
            && (null === $after || $this->start($graph->getLayoutNode($after)) - ($start + $this->extent($node)) >= $this->spacing);
        if (!$fits) {
            return false;
        }

        $previousStart = $this->start($node);
        $this->moveTo($node, $start);
        if ($this->chainLanesHitABox($graph, $node)) {
            $this->moveTo($node, $previousStart);

            return false;
        }

        return true;
    }

    private function moveTo(LayoutNode $node, int $start): void
    {
        if (FlowDirection::LeftToRight === $this->flowDirection) {
            $node->row = $start;
        } else {
            $node->column = $start;
        }
    }

    /**
     * A long edge leaves through its own port and runs as a lane through the
     * next layer, so moving its box must not put that lane onto a box there.
     */
    private function chainLanesHitABox(LayoutGraph $graph, LayoutNode $node): bool
    {
        [$exitPositions, $entryPositions] = EdgePorts::around($graph, $this->flowDirection, $node);
        $lanes = [];
        foreach ($graph->outgoingEdges($node->id) as $edge) {
            if (isset($exitPositions[spl_object_id($edge)]) && $graph->getLayoutNode($edge->targetId()) instanceof DummyLayoutNode) {
                $lanes[] = [$exitPositions[spl_object_id($edge)], $node->layer + 1];
            }
        }
        foreach ($graph->incomingEdges($node->id) as $edge) {
            if (isset($entryPositions[spl_object_id($edge)]) && $graph->getLayoutNode($edge->sourceId()) instanceof DummyLayoutNode) {
                $lanes[] = [$entryPositions[spl_object_id($edge)], $node->layer - 1];
            }
        }

        foreach ($lanes as [$position, $layer]) {
            foreach ($graph->layerIndex()[$layer] ?? [] as $nodeId) {
                $box = $graph->getLayoutNode($nodeId);
                if ($box instanceof RealLayoutNode && $position >= $this->start($box) && $position < $this->end($box)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function start(LayoutNode $node): int
    {
        return FlowDirection::LeftToRight === $this->flowDirection ? $node->row : $node->column;
    }

    private function extent(LayoutNode $node): int
    {
        return FlowDirection::LeftToRight === $this->flowDirection ? $node->boxHeight() : $node->boxWidth();
    }

    private function end(LayoutNode $node): int
    {
        return $this->start($node) + $this->extent($node);
    }
}
