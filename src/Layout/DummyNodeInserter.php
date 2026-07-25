<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\LabelPosition;

final readonly class DummyNodeInserter implements Processor
{
    public function process(LayoutGraph $graph): void
    {
        $longEdges = $this->findLongEdges($graph);
        $replacementEdges = [];
        foreach ($longEdges as $edge) {
            foreach ($this->insertDummyChain($graph, $edge) as $replacementEdge) {
                $replacementEdges[] = $replacementEdge;
            }
        }
        if ([] !== $longEdges) {
            $graph->replaceEdges($longEdges, $replacementEdges);
        }

        $graph->buildLayerIndex();
    }

    /** @return list<LayoutEdge> */
    private function findLongEdges(LayoutGraph $graph): array
    {
        $longEdges = [];

        foreach ($graph->edges() as $edge) {
            if ($edge->reversed) {
                continue;
            }

            $sourceLayer = $graph->getLayoutNode($edge->sourceId())->layer;
            $targetLayer = $graph->getLayoutNode($edge->targetId())->layer;

            /** @infection-ignore-all insertDummyChain for span-1 is a no-op (loop doesn't execute), so > vs >= and +/- are equivalent */
            if ($targetLayer - $sourceLayer > 1) {
                $longEdges[] = $edge;
            }
        }

        return $longEdges;
    }

    /** @return list<LayoutEdge> */
    private function insertDummyChain(LayoutGraph $graph, LayoutEdge $edge): array
    {
        $sourceLayer = $graph->getLayoutNode($edge->sourceId())->layer;
        $targetLayer = $graph->getLayoutNode($edge->targetId())->layer;

        $label = $edge->edge->label;
        $labelLayer = null !== $label && LabelPosition::Middle === $label->position
            ? intdiv($sourceLayer + $targetLayer, 2)
            : null;

        $identity = $edge->identityKey();
        $previousId = $edge->sourceId();
        $chainEdges = [];
        for ($layer = $sourceLayer + 1; $layer < $targetLayer; ++$layer) {
            $dummyId = $this->uniqueId($graph, sprintf('__dummy_%s_%d', $identity, $layer));
            $dummy = new DummyLayoutNode($dummyId, $edge->sourceId(), $edge->targetId(), $edge->edge, $edge->reversed, $identity);
            $dummy->layer = $layer;
            if (null !== $label && $layer === $labelLayer) {
                $dummy->corridorWidth = $label->width() + 2;
            }
            $graph->addNode($dummy);
            $chainEdges[] = new LayoutEdge(edge: new Edge($previousId, $dummyId, edgeStrokeStyle: $edge->edge->edgeStrokeStyle));
            $previousId = $dummyId;
        }

        $chainEdges[] = new LayoutEdge(edge: new Edge($previousId, $edge->targetId(), edgeStrokeStyle: $edge->edge->edgeStrokeStyle));

        return $chainEdges;
    }

    /**
     * Returns an id no node currently uses, preferring the readable $base and
     * falling back to a suffixed id from an isolated namespace on the rare clash
     * with a real node. The id is opaque bookkeeping — nothing parses it back.
     *
     * @infection-ignore-all The behavioural contract — the result is absent from
     * the graph, and is exactly $base when $base is free — is pinned by
     * DummyNodeInserterTest (readable base ids) and DummyNodeCollisionTest (a
     * real node keeps its id). The suffixing that reaches a free id is opaque:
     * every mutation still yields a graph-absent id, so none is observable.
     */
    private function uniqueId(LayoutGraph $graph, string $base): string
    {
        $candidate = $base;
        for ($suffix = 0; $graph->hasNode($candidate); ++$suffix) {
            $candidate = $base.'#'.$suffix;
        }

        return $candidate;
    }
}
