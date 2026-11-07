<?php

declare(strict_types=1);

namespace PhpDag\Layout;

final readonly class LayerCompactor implements Processor
{
    public function __construct(
        private FlowDirection $direction,
        private int $minimumSpacing = 2,
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        $layerIndex = $graph->layerIndex();
        $layers = array_keys($layerIndex);
        [$clusterFirstLayers, $clusterLastLayers] = LayerTransitions::clusterBoundaryLayers($graph);
        [$widenedBefore, $widenedAfter] = $this->labelWidening($graph);

        $shift = 0;
        for ($layerOffset = 0, $layerCount = count($layers); $layerOffset < $layerCount - 1; ++$layerOffset) {
            /** @infection-ignore-all init value is consumed by max(); every layer ends past 0 */
            $layerEnd = 0;
            foreach ($layerIndex[$layers[$layerOffset]] as $nodeId) {
                $layerEnd = max($layerEnd, $this->end($graph, $nodeId));
            }

            $nextLayer = $layerIndex[$layers[$layerOffset + 1]];
            $nextStart = PHP_INT_MAX;
            foreach ($nextLayer as $nodeId) {
                $nextStart = min($nextStart, $this->start($graph->getLayoutNode($nodeId)) - $shift);
            }

            $requiredSpacing = $this->minimumSpacing
                + (LayerTransitions::hasBendingEdge($graph, $layers[$layerOffset], $this->direction) ? 1 : 0)
                + LayerTransitions::clusterBorderAllowance($layers[$layerOffset], $layers[$layerOffset + 1], $clusterFirstLayers, $clusterLastLayers)
                + ($widenedAfter[$layers[$layerOffset]] ?? 0)
                + ($widenedBefore[$layers[$layerOffset + 1]] ?? 0);
            $excess = $nextStart - $layerEnd - $requiredSpacing;

            /** @infection-ignore-all shifting by a zero excess is a no-op; > 0 and >= 0 are equivalent */
            if ($excess > 0) {
                $shift += $excess;
            }
            foreach ($nextLayer as $nodeId) {
                $this->moveBack($graph->getLayoutNode($nodeId), $shift);
            }
        }
    }

    /**
     * In left-to-right flow a group's side borders cross the layer gaps, so the
     * room GroupSpacer added beyond the ring for a long label must survive.
     *
     * @return array{array<int, int>, array<int, int>} [extra columns before a layer, extra columns after it]
     */
    private function labelWidening(LayoutGraph $graph): array
    {
        if (FlowDirection::TopToBottom === $this->direction) {
            return [[], []];
        }

        $before = [];
        $after = [];
        foreach ($graph->groups() as $group) {
            $layers = [];
            foreach ($group->nodeIds as $nodeId) {
                if ($graph->hasNode($nodeId)) {
                    $layers[] = $graph->getLayoutNode($nodeId)->layer;
                }
            }
            if ([] === $layers) {
                continue;
            }
            $before[min($layers)][] = $graph->groupLeftPadding($group->id) - LayoutGraph::GROUP_RING_WIDTH;
            $after[max($layers)][] = $graph->groupRightPadding($group->id) - LayoutGraph::GROUP_RING_WIDTH;
        }

        return [$this->widest($before), $this->widest($after)];
    }

    /**
     * @param array<int, non-empty-list<int>> $extrasByLayer
     *
     * @return array<int, int>
     */
    private function widest(array $extrasByLayer): array
    {
        return array_map(static fn (array $extras): int => max($extras), $extrasByLayer);
    }

    private function end(LayoutGraph $graph, string $nodeId): int
    {
        $node = $graph->getLayoutNode($nodeId);
        if (FlowDirection::TopToBottom === $this->direction) {
            return $node->row + $node->boxHeight();
        }

        // A self-loop's lane runs right of the box, into the gap before the next layer.
        return $node->column + $node->boxWidth() + ($graph->hasSelfLoop($nodeId) ? 2 : 0);
    }

    private function start(LayoutNode $node): int
    {
        return FlowDirection::TopToBottom === $this->direction ? $node->row : $node->column;
    }

    private function moveBack(LayoutNode $node, int $distance): void
    {
        if (FlowDirection::TopToBottom === $this->direction) {
            $node->row -= $distance;

            return;
        }
        $node->column -= $distance;
    }
}
