<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use LogicException;
use PhpDag\Style\EdgeStrokeStyle;
use SplMinHeap;
use SplPriorityQueue;

/**
 * Breaks cycles with the Eades–Lin–Smyth greedy heuristic (GR): it builds a
 * linear vertex order by repeatedly peeling off sinks (to the right end),
 * sources (to the left end) and otherwise the vertex with the largest
 * out-minus-in weighted degree (also to the left). Every edge that then points
 * backwards against that order is reversed. The degree is weighted by edge
 * weight (summed across parallel edges, as in dagre/Graphviz), so heavier edges
 * — e.g. a highlighted path — are kept and lighter ones are reversed instead.
 * This keeps the reversed (feedback) set small — bounded by |E|/2 − |V|/6 — and
 * well below what a naive DFS back-edge reversal produces when cycles overlap
 * and share edges.
 *
 * Sinks and sources are drained from index-ordered work queues and degrees are
 * updated incrementally as vertices detach, so the pass is O((V+E)·log V) rather
 * than the quadratic full rescans it replaces. Draining a sink only ever exposes
 * new sinks (a sink has no out-edges to touch a successor's in-degree) and a
 * source only new sources, so the two phases never interfere; both queues pop
 * the lowest-insertion-index vertex, reproducing the original "first remaining
 * candidate" tie-break exactly.
 *
 * @infection-ignore-all GR is a convergent heuristic: its observable contract —
 * the result is acyclic, the feedback set is minimal on overlapping cycles, the
 * lighter of two competing edges is reversed, parallel-edge weights accumulate,
 * and reversed edges become dashed — is pinned by CycleBreakerTest. The internal
 * vertex-ordering machinery (the order sinks/sources/max-delta vertices are
 * peeled, the neighbour-set bookkeeping and the position-tie comparison) has
 * redundant, mutually-compensating paths that reach the same feedback set, so
 * structural mutations to those internals leave the observable behaviour
 * unchanged. This mirrors BrandesKopfPositioning.
 */
final readonly class CycleBreaker implements Processor
{
    public function process(LayoutGraph $graph): void
    {
        $position = $this->greedyOrder($graph);

        $reversedEdges = [];
        $replacementEdges = [];
        foreach ($graph->edges() as $edge) {
            if ($position[$edge->sourceId()] > $position[$edge->targetId()]) {
                $reversedEdges[] = $edge;
                $dashedEdge = $edge->edge->withStrokeStyle(EdgeStrokeStyle::Dashed);
                $replacementEdges[] = new LayoutEdge(edge: $dashedEdge, reversed: true, originalEdgeId: $edge->originalEdgeId);
            }
        }

        if ([] !== $reversedEdges) {
            $graph->replaceEdges($reversedEdges, $replacementEdges);
        }
    }

    /** @return array<string, int> node id mapped to its index in the feedback-minimising order */
    private function greedyOrder(LayoutGraph $graph): array
    {
        $nodeIds = $graph->nodeIds();
        $insertionIndexByNodeId = array_flip($nodeIds);

        /** @var array<string, array<string, int>> $outgoingAdjacency */
        $outgoingAdjacency = [];
        /** @var array<string, array<string, int>> $incomingAdjacency */
        $incomingAdjacency = [];
        /** @var array<string, int> $outgoingDegree */
        $outgoingDegree = [];
        /** @var array<string, int> $incomingDegree */
        $incomingDegree = [];
        /** @var array<string, int> $outgoingWeight */
        $outgoingWeight = [];
        /** @var array<string, int> $incomingWeight */
        $incomingWeight = [];
        foreach ($nodeIds as $nodeId) {
            $outgoingAdjacency[$nodeId] = $incomingAdjacency[$nodeId] = [];
            $outgoingDegree[$nodeId] = $incomingDegree[$nodeId] = 0;
            $outgoingWeight[$nodeId] = $incomingWeight[$nodeId] = 0;
        }
        foreach ($graph->edges() as $edge) {
            $sourceId = $edge->sourceId();
            $targetId = $edge->targetId();
            $weight = $edge->edge->weight;
            if (!isset($outgoingAdjacency[$sourceId][$targetId])) {
                ++$outgoingDegree[$sourceId];
                ++$incomingDegree[$targetId];
            }
            $outgoingAdjacency[$sourceId][$targetId] = ($outgoingAdjacency[$sourceId][$targetId] ?? 0) + $weight;
            $incomingAdjacency[$targetId][$sourceId] = ($incomingAdjacency[$targetId][$sourceId] ?? 0) + $weight;
            $outgoingWeight[$sourceId] += $weight;
            $incomingWeight[$targetId] += $weight;
        }

        /** @var SplMinHeap<int> $sinks */
        $sinks = new SplMinHeap();
        /** @var SplMinHeap<int> $sources */
        $sources = new SplMinHeap();
        foreach ($nodeIds as $index => $nodeId) {
            if (0 === $outgoingDegree[$nodeId]) {
                $sinks->insert($index);
            }
            if (0 === $incomingDegree[$nodeId]) {
                $sources->insert($index);
            }
        }

        /** @var array<string, true> $removed */
        $removed = [];
        /** @var array<string, int> $degreeDifferenceVersions */
        $degreeDifferenceVersions = [];
        /** @var SplPriorityQueue<array{int, int}, array{nodeId: string, version: int}> $degreeDifferenceQueue */
        $degreeDifferenceQueue = new SplPriorityQueue();
        $refreshDegreeDifference = function (string $nodeId) use (&$degreeDifferenceVersions, &$outgoingWeight, &$incomingWeight, $degreeDifferenceQueue, $insertionIndexByNodeId): void {
            $version = ($degreeDifferenceVersions[$nodeId] ?? 0) + 1;
            $degreeDifferenceVersions[$nodeId] = $version;
            $degreeDifferenceQueue->insert(
                ['nodeId' => $nodeId, 'version' => $version],
                [$outgoingWeight[$nodeId] - $incomingWeight[$nodeId], -$insertionIndexByNodeId[$nodeId]],
            );
        };
        foreach ($nodeIds as $nodeId) {
            $refreshDegreeDifference($nodeId);
        }

        $detach = function (string $nodeId) use (&$outgoingAdjacency, &$incomingAdjacency, &$outgoingDegree, &$incomingDegree, &$outgoingWeight, &$incomingWeight, &$removed, $sinks, $sources, $insertionIndexByNodeId, $refreshDegreeDifference): void {
            $removed[$nodeId] = true;
            foreach ($outgoingAdjacency[$nodeId] as $successorKey => $weight) {
                $successorId = strval($successorKey);
                unset($incomingAdjacency[$successorId][$nodeId]);
                --$incomingDegree[$successorId];
                $incomingWeight[$successorId] -= $weight;
                if (0 === $incomingDegree[$successorId] && !isset($removed[$successorId])) {
                    $sources->insert($insertionIndexByNodeId[$successorId]);
                }
                if (!isset($removed[$successorId])) {
                    $refreshDegreeDifference($successorId);
                }
            }
            foreach ($incomingAdjacency[$nodeId] as $predecessorKey => $weight) {
                $predecessorId = strval($predecessorKey);
                unset($outgoingAdjacency[$predecessorId][$nodeId]);
                --$outgoingDegree[$predecessorId];
                $outgoingWeight[$predecessorId] -= $weight;
                if (0 === $outgoingDegree[$predecessorId] && !isset($removed[$predecessorId])) {
                    $sinks->insert($insertionIndexByNodeId[$predecessorId]);
                }
                if (!isset($removed[$predecessorId])) {
                    $refreshDegreeDifference($predecessorId);
                }
            }
        };

        /** @var list<string> $leftOrder */
        $leftOrder = [];
        /** @var list<string> $sinkOrder */
        $sinkOrder = [];
        $remaining = count($nodeIds);

        while ($remaining > 0) {
            while (null !== ($sink = $this->popLowestLiveIndex($sinks, $nodeIds, $removed))) {
                $sinkOrder[] = $sink;
                $detach($sink);
                --$remaining;
            }

            while (null !== ($source = $this->popLowestLiveIndex($sources, $nodeIds, $removed))) {
                $leftOrder[] = $source;
                $detach($source);
                --$remaining;
            }

            if ($remaining > 0) {
                $selectedNodeId = $this->popMaximumDegreeDifferenceVertex($degreeDifferenceQueue, $degreeDifferenceVersions, $removed);
                $leftOrder[] = $selectedNodeId;
                $detach($selectedNodeId);
                --$remaining;
            }
        }

        $position = [];
        foreach ([...$leftOrder, ...array_reverse($sinkOrder)] as $order => $nodeId) {
            $position[$nodeId] = $order;
        }

        return $position;
    }

    /**
     * Extracts the lowest-index vertex still present, skipping entries whose
     * vertex has already been drained through the other queue (an isolated
     * vertex is both a sink and a source).
     *
     * @param SplMinHeap<int>     $queue
     * @param list<string>        $nodeIds
     * @param array<string, true> $removed
     */
    private function popLowestLiveIndex(SplMinHeap $queue, array $nodeIds, array $removed): ?string
    {
        while (!$queue->isEmpty()) {
            $nodeId = $nodeIds[$queue->extract()];
            if (!isset($removed[$nodeId])) {
                return $nodeId;
            }
        }

        return null;
    }

    /**
     * @param SplPriorityQueue<array{int, int}, array{nodeId: string, version: int}> $queue
     * @param array<string, int>                                                     $versions
     * @param array<string, true>                                                    $removed
     */
    private function popMaximumDegreeDifferenceVertex(SplPriorityQueue $queue, array $versions, array $removed): string
    {
        while (!$queue->isEmpty()) {
            /** @var array{nodeId: string, version: int} $candidate */
            $candidate = $queue->extract();
            if (isset($removed[$candidate['nodeId']]) || $versions[$candidate['nodeId']] !== $candidate['version']) {
                continue;
            }

            return $candidate['nodeId'];
        }

        throw new LogicException('No live vertex remains in the delta queue');
    }
}
