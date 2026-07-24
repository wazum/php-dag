<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use InvalidArgumentException;
use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Group;

final class LayoutGraph
{
    /** @var array<string, LayoutNode> */
    private array $nodes = [];

    /** @var list<Group> */
    private array $groups = [];

    /** @var array<string, int> */
    private array $groupLeftPadding = [];

    /** @var array<int, list<array{int, int}>> column spans reserved for edge labels, keyed by the gap's source layer */
    private array $reservedLabelSpans = [];

    /** @var array<string, list<string>> */
    private array $successorMap = [];

    /** @var array<string, list<string>> */
    private array $predecessorMap = [];

    /** @var array<string, list<LayoutEdge>> */
    private array $incomingEdgesByNodeId = [];

    /** @var array<string, list<LayoutEdge>> */
    private array $outgoingEdgesByNodeId = [];

    /** @var list<LayoutEdge> */
    private array $edges = [];

    /** @var list<LayoutEdge> */
    private array $selfLoops = [];

    /** @var array<int, list<string>> */
    private array $layerIndex = [];

    /** @var list<Edge> */
    private array $originalEdges = [];

    public function storeOriginalEdge(Edge $edge): void
    {
        $this->originalEdges[] = $edge;
    }

    /** @return list<Edge> */
    public function originalEdges(): array
    {
        return $this->originalEdges;
    }

    public static function fromGraph(Graph $graph): self
    {
        $layoutGraph = new self();

        foreach ($graph->nodes() as $node) {
            $layoutGraph->nodes[$node->id] = new RealLayoutNode(id: $node->id, node: $node);
        }

        $pairCount = self::countEndpointPairs($graph);

        foreach ($graph->edges() as $index => $edge) {
            // Only edges sharing endpoints with a sibling need an explicit id to
            // stay distinct through dummy expansion; the unique enumeration index
            // guarantees that. Unique pairs fall back to their readable
            // "source_target" identity so single-edge dummy ids stay stable.
            $originalEdgeId = $pairCount[$edge->sourceId."\0".$edge->targetId] > 1 ? (string) $index : null;
            $layoutEdge = new LayoutEdge(edge: $edge, originalEdgeId: $originalEdgeId);
            $layoutGraph->edges[] = $layoutEdge;
            $layoutGraph->successorMap[$edge->sourceId][] = $edge->targetId;
            $layoutGraph->predecessorMap[$edge->targetId][] = $edge->sourceId;
            $layoutGraph->outgoingEdgesByNodeId[$edge->sourceId][] = $layoutEdge;
            $layoutGraph->incomingEdgesByNodeId[$edge->targetId][] = $layoutEdge;
            $layoutGraph->storeOriginalEdge($edge);
        }

        foreach ($graph->selfLoops() as $edge) {
            $layoutGraph->selfLoops[] = new LayoutEdge(edge: $edge);
        }

        $layoutGraph->groups = $graph->groups();

        return $layoutGraph;
    }

    /** @return array<string, int> endpoint pair (keyed by a NUL-joined "source\0target") mapped to how many edges share it */
    private static function countEndpointPairs(Graph $graph): array
    {
        $pairCount = [];
        foreach ($graph->edges() as $edge) {
            /** @infection-ignore-all the NUL separator only has to make the (source, target) pair key unique; node ids cannot contain it, so mutating it leaves the counts unchanged for any real id */
            $pair = $edge->sourceId."\0".$edge->targetId;
            $pairCount[$pair] = ($pairCount[$pair] ?? 0) + 1;
        }

        return $pairCount;
    }

    /** @return list<Group> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** Columns reserved between a group's border and its leftmost member, widened by GroupSpacer when the label needs the room. */
    public function setGroupLeftPadding(string $groupId, int $padding): void
    {
        $this->groupLeftPadding[$groupId] = $padding;
    }

    public function groupLeftPadding(string $groupId): int
    {
        return $this->groupLeftPadding[$groupId] ?? 2;
    }

    /** Marks a column span in a layer gap as taken by an edge label, so routing keeps lanes out of it. */
    public function reserveLabelSpan(int $gapLayer, int $fromColumn, int $toColumn): void
    {
        $this->reservedLabelSpans[$gapLayer][] = [$fromColumn, $toColumn];
    }

    /** @return list<array{int, int}> */
    public function reservedLabelSpans(int $gapLayer): array
    {
        return $this->reservedLabelSpans[$gapLayer] ?? [];
    }

    public function getLayoutNode(string $nodeId): LayoutNode
    {
        if (!isset($this->nodes[$nodeId])) {
            throw new InvalidArgumentException(sprintf('Layout node "%s" does not exist', $nodeId));
        }

        return $this->nodes[$nodeId];
    }

    /** @return list<string> */
    public function successors(string $nodeId): array
    {
        return $this->successorMap[$nodeId] ?? [];
    }

    /** @return list<string> */
    public function predecessors(string $nodeId): array
    {
        return $this->predecessorMap[$nodeId] ?? [];
    }

    /** @return list<string> */
    public function roots(): array
    {
        $roots = [];
        foreach (array_keys($this->nodes) as $nodeId) {
            if (!isset($this->predecessorMap[$nodeId])) {
                $roots[] = strval($nodeId);
            }
        }

        return $roots;
    }

    /** @return list<string> */
    public function leaves(): array
    {
        $leaves = [];
        foreach (array_keys($this->nodes) as $nodeId) {
            if (!isset($this->successorMap[$nodeId])) {
                $leaves[] = strval($nodeId);
            }
        }

        return $leaves;
    }

    /** @return list<LayoutEdge> */
    public function incomingEdges(string $nodeId): array
    {
        return $this->incomingEdgesByNodeId[$nodeId] ?? [];
    }

    /** @return list<LayoutEdge> */
    public function outgoingEdges(string $nodeId): array
    {
        return $this->outgoingEdgesByNodeId[$nodeId] ?? [];
    }

    /** @return list<string> */
    public function nodeIds(): array
    {
        return array_map(strval(...), array_keys($this->nodes));
    }

    /** @return list<LayoutEdge> */
    public function edges(): array
    {
        return $this->edges;
    }

    /** @return list<LayoutEdge> */
    public function selfLoops(): array
    {
        return $this->selfLoops;
    }

    public function buildLayerIndex(): void
    {
        $this->layerIndex = [];
        foreach ($this->nodes as $nodeId => $node) {
            $this->layerIndex[$node->layer][] = strval($nodeId);
        }
        ksort($this->layerIndex);
    }

    /** @return array<int, list<string>> */
    public function layerIndex(): array
    {
        return $this->layerIndex;
    }

    public function layerCount(): int
    {
        return count($this->layerIndex);
    }

    public function hasNode(string $nodeId): bool
    {
        return isset($this->nodes[$nodeId]);
    }

    public function nodeCount(): int
    {
        return count($this->nodes);
    }

    public function addNode(LayoutNode $node): void
    {
        $this->nodes[$node->id] = $node;
    }

    public function addEdge(LayoutEdge $edge): void
    {
        $this->edges[] = $edge;
        $sourceId = $edge->sourceId();
        $targetId = $edge->targetId();
        $this->successorMap[$sourceId][] = $targetId;
        $this->predecessorMap[$targetId][] = $sourceId;
        $this->outgoingEdgesByNodeId[$sourceId][] = $edge;
        $this->incomingEdgesByNodeId[$targetId][] = $edge;
    }

    /** @param list<string> $nodeIds */
    public function setLayerOrder(int $layer, array $nodeIds): void
    {
        $existing = $this->layerIndex[$layer] ?? [];
        $sortedExisting = $existing;
        $sortedNew = $nodeIds;
        sort($sortedExisting);
        sort($sortedNew);

        if ($sortedNew !== $sortedExisting) {
            throw new InvalidArgumentException(sprintf('Node IDs for layer %d do not match existing membership', $layer));
        }

        $this->layerIndex[$layer] = $nodeIds;
    }

    /**
     * Replaces a set of edges and rebuilds all adjacency indexes in one pass.
     *
     * @param list<LayoutEdge> $removedEdges
     * @param list<LayoutEdge> $replacementEdges
     */
    public function replaceEdges(array $removedEdges, array $replacementEdges): void
    {
        $removedIds = [];
        foreach ($removedEdges as $edge) {
            /** @infection-ignore-all Only probed with isset(); the stored value is never read, so true and false behave identically. */
            $removedIds[spl_object_id($edge)] = true;
        }

        $remainingEdges = [];
        foreach ($this->edges as $edge) {
            if (!isset($removedIds[spl_object_id($edge)])) {
                $remainingEdges[] = $edge;
            }
        }

        $this->edges = [...$remainingEdges, ...$replacementEdges];
        $this->rebuildEdgeIndexes();
    }

    private function rebuildEdgeIndexes(): void
    {
        $this->successorMap = [];
        $this->predecessorMap = [];
        $this->outgoingEdgesByNodeId = [];
        $this->incomingEdgesByNodeId = [];

        foreach ($this->edges as $edge) {
            $sourceId = $edge->sourceId();
            $targetId = $edge->targetId();
            $this->successorMap[$sourceId][] = $targetId;
            $this->predecessorMap[$targetId][] = $sourceId;
            $this->outgoingEdgesByNodeId[$sourceId][] = $edge;
            $this->incomingEdgesByNodeId[$targetId][] = $edge;
        }
    }

    /** @param list<string> $nodeIds */
    public function removeNodes(array $nodeIds): void
    {
        $removedNodeIds = array_fill_keys($nodeIds, true);
        foreach ($nodeIds as $nodeId) {
            unset($this->nodes[$nodeId]);
        }

        foreach ($this->layerIndex as $layer => $layerNodeIds) {
            $this->layerIndex[$layer] = array_values(array_filter(
                $layerNodeIds,
                static fn (string $nodeId): bool => !isset($removedNodeIds[$nodeId]),
            ));
        }

        $this->edges = array_values(array_filter(
            $this->edges,
            static fn (LayoutEdge $edge): bool => !isset($removedNodeIds[$edge->sourceId()]) && !isset($removedNodeIds[$edge->targetId()]),
        ));
        $this->rebuildEdgeIndexes();
    }
}
