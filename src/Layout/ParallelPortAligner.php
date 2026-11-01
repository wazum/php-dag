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

        // Each group gets one try and fixes both its ends, so this settles.
        // A moved box changes the offsets of its other groups, so the groups
        // are recomputed after every move.
        $groups = EdgePorts::of($graph, $this->flowDirection)->misalignedGroups;
        while (null !== $group = array_shift($groups)) {
            [$source, $target, $offset] = $group;
            if (isset($fixedNodeIds[$source->id], $fixedNodeIds[$target->id])) {
                continue;
            }
            $moved = $this->tryShift($graph, $source, $offset, $fixedNodeIds) || $this->tryShift($graph, $target, -$offset, $fixedNodeIds);
            $fixedNodeIds[$source->id] = $source->id;
            $fixedNodeIds[$target->id] = $target->id;
            if ($moved) {
                $groups = EdgePorts::of($graph, $this->flowDirection)->misalignedGroups;
            }
        }
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

        if (FlowDirection::LeftToRight === $this->flowDirection) {
            $node->row = $start;
        } else {
            $node->column = $start;
        }

        return true;
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
