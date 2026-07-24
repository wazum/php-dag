<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Render\Waypoint;

final readonly class DummyNodeRemover implements Processor
{
    public function process(LayoutGraph $graph): void
    {
        $dummyIds = $this->findDummyNodeIds($graph);
        $chainEdgesByOriginal = $this->collectChainEdges($graph, $dummyIds);

        $graph->removeNodes($dummyIds);

        foreach ($chainEdgesByOriginal as $chain) {
            $chainEdges = $chain['edges'];
            $restoredEdge = new LayoutEdge(edge: $chain['edge'], reversed: $chain['reversed']);
            $restoredEdge->waypoints = $this->mergeWaypoints($chainEdges);
            $graph->addEdge($restoredEdge);
        }
    }

    /** @return list<string> */
    private function findDummyNodeIds(LayoutGraph $graph): array
    {
        $dummyIds = [];
        foreach ($graph->nodeIds() as $nodeId) {
            if ($graph->getLayoutNode($nodeId) instanceof DummyLayoutNode) {
                $dummyIds[] = $nodeId;
            }
        }

        return $dummyIds;
    }

    /**
     * @param list<string> $dummyIds
     *
     * @return array<string, array{edges: list<LayoutEdge>, edge: Edge, reversed: bool}>
     */
    private function collectChainEdges(LayoutGraph $graph, array $dummyIds): array
    {
        /** @var array<string, array{edges: list<LayoutEdge>, edge: Edge, reversed: bool}> */
        $chainEdgesByOriginal = [];

        foreach ($dummyIds as $dummyId) {
            $node = $graph->getLayoutNode($dummyId);
            assert($node instanceof DummyLayoutNode);
            $edgeKey = $node->identityKey();

            if (!isset($chainEdgesByOriginal[$edgeKey])) {
                $chainEdgesByOriginal[$edgeKey] = [
                    'edges' => [],
                    'edge' => $node->originalEdge,
                    'reversed' => $node->originalEdgeReversed,
                ];
            }
        }

        foreach ($graph->edges() as $edge) {
            $sourceNode = $graph->getLayoutNode($edge->sourceId());
            $targetNode = $graph->getLayoutNode($edge->targetId());
            $dummyNode = $sourceNode instanceof DummyLayoutNode ? $sourceNode : $targetNode;
            if (!$dummyNode instanceof DummyLayoutNode) {
                continue;
            }

            $identityKey = $dummyNode->identityKey();
            $chain = $chainEdgesByOriginal[$identityKey];
            $chain['edges'][] = $edge;
            $chainEdgesByOriginal[$identityKey] = $chain;
        }

        return $chainEdgesByOriginal;
    }

    /**
     * @param list<LayoutEdge> $chainEdges
     *
     * @return list<Waypoint>
     */
    private function mergeWaypoints(array $chainEdges): array
    {
        $waypoints = [];

        foreach ($chainEdges as $chainEdge) {
            foreach ($chainEdge->waypoints as $waypoint) {
                if ([] === $waypoints || $waypoint != end($waypoints)) {
                    $waypoints[] = $waypoint;
                }
            }
        }

        return $waypoints;
    }
}
