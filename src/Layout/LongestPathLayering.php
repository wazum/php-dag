<?php

declare(strict_types=1);

namespace PhpDag\Layout;

final readonly class LongestPathLayering implements LayerAssignment
{
    /** @return array<string, int> */
    public function assign(LayoutGraph $graph): array
    {
        $layers = [];

        foreach ($graph->nodeIds() as $nodeId) {
            if (!isset($layers[$nodeId])) {
                $this->computeLayer($nodeId, $graph, $layers);
            }
        }

        return $layers;
    }

    /** @param array<string, int> $layers */
    private function computeLayer(string $nodeId, LayoutGraph $graph, array &$layers): int
    {
        $incomingEdges = $graph->incomingEdges($nodeId);

        if ([] === $incomingEdges) {
            $layers[$nodeId] = 0;

            return 0;
        }

        /** @infection-ignore-all Any negative initial value works since predecessorLayer + minLength is always >= 1 */
        $maxPredecessorLayer = -1;
        foreach ($incomingEdges as $incomingEdge) {
            $predecessorId = $incomingEdge->sourceId();
            /** @infection-ignore-all Coalesce order is a performance optimization; recomputing gives identical results */
            $predecessorLayer = $layers[$predecessorId] ?? $this->computeLayer($predecessorId, $graph, $layers);
            $maxPredecessorLayer = max($maxPredecessorLayer, $predecessorLayer + $incomingEdge->minLength());
        }

        $layers[$nodeId] = $maxPredecessorLayer;

        return $maxPredecessorLayer;
    }
}
