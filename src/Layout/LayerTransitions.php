<?php

declare(strict_types=1);

namespace PhpDag\Layout;

final readonly class LayerTransitions
{
    /**
     * The first and last layers each group spans (its leading and trailing
     * borders along the flow axis). Shared by the vertical and horizontal
     * compactors to reserve room for cluster borders.
     *
     * @return array{array<int, true>, array<int, true>} [first layers, last layers]
     */
    public static function clusterBoundaryLayers(LayoutGraph $graph): array
    {
        $firstLayers = [];
        $lastLayers = [];
        foreach ($graph->groups() as $group) {
            $minLayer = PHP_INT_MAX;
            $maxLayer = PHP_INT_MIN;
            foreach ($group->nodeIds as $nodeId) {
                if (!$graph->hasNode($nodeId)) {
                    continue;
                }
                $layer = $graph->getLayoutNode($nodeId)->layer;
                $minLayer = min($minLayer, $layer);
                $maxLayer = max($maxLayer, $layer);
            }
            if (PHP_INT_MAX !== $minLayer) {
                /** @infection-ignore-all the maps are read through isset(), so the stored true/false value is never observed */
                $firstLayers[$minLayer] = true;
                /** @infection-ignore-all the maps are read through isset(), so the stored true/false value is never observed */
                $lastLayers[$maxLayer] = true;
            }
        }

        return [$firstLayers, $lastLayers];
    }

    /**
     * Extra rows (top-to-bottom) or columns (left-to-right) a transition must
     * reserve for the cluster borders crossing it: one for a cluster ending at
     * the source layer (the edge crosses the trailing border, then fans out
     * beyond it), and one more when the next layer also begins a cluster, so the
     * two stacked borders get their own adjacent lines.
     *
     * @param array<int, true> $firstLayers
     * @param array<int, true> $lastLayers
     */
    public static function clusterBorderAllowance(int $fromLayer, int $toLayer, array $firstLayers, array $lastLayers): int
    {
        $ends = isset($lastLayers[$fromLayer]);
        $starts = isset($firstLayers[$toLayer]);

        return ($ends ? 1 : 0) + ($ends && $starts ? 1 : 0);
    }

    /**
     * A bending edge leaves its source and target centers in different
     * columns, so the transition needs a horizontal channel row plus a
     * connector row; straight-only transitions stay compact. Mirrors the
     * off-by-one tolerance of ChainAwareRouting::alignedTargetColumn(),
     * which routes such edges straight into the target.
     */
    public static function hasBendingEdge(LayoutGraph $graph, int $layer, FlowDirection $direction = FlowDirection::TopToBottom): bool
    {
        foreach ($graph->layerIndex()[$layer] ?? [] as $nodeId) {
            $sourceCenter = self::crossCenter($graph->getLayoutNode($nodeId), $direction);

            foreach ($graph->outgoingEdges($nodeId) as $edge) {
                /** @infection-ignore-all skipping vs. processing reversed edges: feedback edges are lane-routed and never bend a transition */
                if ($edge->reversed) {
                    continue;
                }
                if (!self::routesStraight($graph, $sourceCenter, $graph->getLayoutNode($edge->targetId()), $direction)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function routesStraight(LayoutGraph $graph, int $sourceCenter, LayoutNode $targetNode, FlowDirection $direction): bool
    {
        $targetCenter = self::crossCenter($targetNode, $direction);
        if ($sourceCenter === $targetCenter) {
            return true;
        }

        [$targetStart, $targetSize] = self::crossExtent($targetNode, $direction);

        return 1 === abs($sourceCenter - $targetCenter)
            && $sourceCenter > $targetStart
            && $sourceCenter < $targetStart + $targetSize - 1
            && count($graph->incomingEdges($targetNode->id)) <= 1;
    }

    private static function crossCenter(LayoutNode $node, FlowDirection $direction): int
    {
        [$start, $size] = self::crossExtent($node, $direction);

        return $start + intdiv($size, 2);
    }

    /** @return array{int, int} start and size across the flow */
    private static function crossExtent(LayoutNode $node, FlowDirection $direction): array
    {
        return FlowDirection::TopToBottom === $direction
            ? [$node->column, $node->boxWidth()]
            : [$node->row, $node->boxHeight()];
    }
}
