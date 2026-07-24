<?php

declare(strict_types=1);

namespace PhpDag\Layout;

/**
 * Seeds the initial within-layer order with a depth-first traversal before the
 * crossing minimiser runs (Graphviz dot's `init_order`, dagre's `initOrder`).
 * Visiting descendants immediately after their ancestor groups nodes that share
 * a path next to each other, giving the barycentre/median sweeps a starting
 * point far closer to a crossing-free layout than raw insertion order.
 */
final readonly class DepthFirstOrdering implements Processor
{
    public function process(LayoutGraph $graph): void
    {
        // layerIndex() is keyed by layer in ascending order (buildLayerIndex
        // ksorts it), so iterating it visits roots before their descendants.
        $layerIndex = $graph->layerIndex();

        $layerOf = [];
        foreach ($layerIndex as $layer => $nodeIds) {
            foreach ($nodeIds as $nodeId) {
                $layerOf[$nodeId] = $layer;
            }
        }

        $visited = [];
        /** @var array<int, list<string>> $ordered */
        $ordered = [];
        foreach ($layerIndex as $nodeIds) {
            foreach ($nodeIds as $nodeId) {
                $this->visit($nodeId, $graph, $layerOf, $visited, $ordered);
            }
        }

        foreach ($ordered as $layer => $nodeIds) {
            $graph->setLayerOrder($layer, $nodeIds);
        }
    }

    /**
     * @param array<string, int>       $layerOf
     * @param array<string, true>      $visited
     * @param array<int, list<string>> $ordered
     */
    private function visit(string $nodeId, LayoutGraph $graph, array $layerOf, array &$visited, array &$ordered): void
    {
        if (isset($visited[$nodeId])) {
            return;
        }

        /** @infection-ignore-all the marker value is never read — membership is tested via isset(), so true and false behave identically */
        $visited[$nodeId] = true;
        $ordered[$layerOf[$nodeId]][] = $nodeId;

        foreach ($graph->successors($nodeId) as $successorId) {
            $this->visit($successorId, $graph, $layerOf, $visited, $ordered);
        }
    }
}
