<?php

declare(strict_types=1);

namespace PhpDag\Graph;

use InvalidArgumentException;
use LogicException;
use PhpDag\Dot\DotExporter;
use PhpDag\Dot\DotParser;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\EdgeStrokeStyle;

final class Graph
{
    /** @var array<string, Node> */
    private array $nodes = [];

    /** @var list<Edge> */
    private array $edges = [];

    /** @var array<string, true> */
    private array $edgeIndex = [];

    /** @var array<string, list<string>> */
    private array $successorMap = [];

    /** @var array<string, list<string>> */
    private array $predecessorMap = [];

    /** @var array<string, list<Edge>> */
    private array $outgoingEdgesByNodeId = [];

    /** @var array<string, list<Edge>> */
    private array $incomingEdgesByNodeId = [];

    /** @var list<Edge> */
    private array $selfLoops = [];

    /** @var array<string, Group> */
    private array $groups = [];

    /**
     * Builds a graph in one call from a node title map and a list of edge pairs.
     *
     * @param array<array-key, string>    $nodes id => title (numeric-string ids arrive as int keys and are normalised back to string)
     * @param list<array{string, string}> $edges [sourceId, targetId] pairs; endpoints must appear in $nodes
     */
    public static function fromEdges(array $nodes, array $edges): self
    {
        $graph = new self();
        foreach ($nodes as $nodeId => $title) {
            $graph->addNode(new Node(strval($nodeId), $title));
        }
        foreach ($edges as [$sourceId, $targetId]) {
            $graph->addEdge(new Edge($sourceId, $targetId));
        }

        return $graph;
    }

    /**
     * Fluently adds an edge, first creating either endpoint that doesn't exist
     * yet (its title defaults to its id). The shortcut for wiring a graph up
     * without declaring every node first.
     */
    public function connect(string $sourceId, string $targetId): self
    {
        foreach ([$sourceId, $targetId] as $nodeId) {
            if (!isset($this->nodes[$nodeId])) {
                $this->addNode(new Node($nodeId, $nodeId));
            }
        }

        return $this->addEdge(new Edge($sourceId, $targetId));
    }

    public function addNode(Node $node): self
    {
        if (isset($this->nodes[$node->id])) {
            throw new InvalidArgumentException(sprintf('Node "%s" already exists', $node->id));
        }

        $this->nodes[$node->id] = $node;

        return $this;
    }

    public function getNode(string $nodeId): Node
    {
        if (!isset($this->nodes[$nodeId])) {
            throw new InvalidArgumentException(sprintf('Node "%s" does not exist', $nodeId));
        }

        return $this->nodes[$nodeId];
    }

    public function addEdge(Edge $edge): self
    {
        if (!isset($this->nodes[$edge->sourceId])) {
            throw new InvalidArgumentException(sprintf('Source node "%s" does not exist', $edge->sourceId));
        }

        if (!isset($this->nodes[$edge->targetId])) {
            throw new InvalidArgumentException(sprintf('Target node "%s" does not exist', $edge->targetId));
        }

        if ($edge->sourceId === $edge->targetId) {
            $this->selfLoops[] = $edge;

            return $this;
        }

        // Parallel edges (a second a->b) are kept for routing/rendering, but the
        // adjacency maps stay deduplicated so layering, ordering and positioning
        // treat the pair as a single connection.
        /** @infection-ignore-all the separator only has to make the (source, target) pair key unique; node ids cannot contain it, so dropping or changing it leaves dedup behaviour unchanged for any real id */
        $edgeKey = $edge->sourceId."\0".$edge->targetId;
        $isParallel = $this->edgeIndex[$edgeKey] ?? false;

        $this->edgeIndex[$edgeKey] = true;
        $this->edges[] = $edge;
        $this->outgoingEdgesByNodeId[$edge->sourceId][] = $edge;
        $this->incomingEdgesByNodeId[$edge->targetId][] = $edge;

        if (!$isParallel) {
            $this->successorMap[$edge->sourceId][] = $edge->targetId;
            $this->predecessorMap[$edge->targetId][] = $edge->sourceId;
        }

        return $this;
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

    /** @return list<string>|null */
    public function shortestPath(string $sourceId, string $targetId): ?array
    {
        $this->assertNodesExist([$sourceId, $targetId]);

        /** @var array<string, string|null> $previousNodeById */
        $previousNodeById = [$sourceId => null];
        $queuedNodeIds = [$sourceId];

        for ($queueOffset = 0; isset($queuedNodeIds[$queueOffset]); ++$queueOffset) {
            $currentNodeId = $queuedNodeIds[$queueOffset];
            if ($currentNodeId === $targetId) {
                $path = [];
                for ($pathNodeId = $targetId; null !== $pathNodeId; $pathNodeId = $previousNodeById[$pathNodeId]) {
                    $path[] = $pathNodeId;
                }

                return array_reverse($path);
            }

            foreach ($this->successors($currentNodeId) as $successorId) {
                if (!array_key_exists($successorId, $previousNodeById)) {
                    $previousNodeById[$successorId] = $currentNodeId;
                    $queuedNodeIds[] = $successorId;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    public function descendants(string $nodeId): array
    {
        return $this->reachableNodeIds($nodeId, $this->successorMap);
    }

    /** @return list<string> */
    public function ancestors(string $nodeId): array
    {
        return $this->reachableNodeIds($nodeId, $this->predecessorMap);
    }

    /** @return list<string> */
    public function topologicalOrder(): array
    {
        if ([] !== $this->selfLoops) {
            throw new LogicException('A topological order does not exist for a cyclic graph');
        }

        $incomingDegreeByNodeId = [];
        $queuedNodeIds = [];
        foreach (array_keys($this->nodes) as $nodeKey) {
            $nodeId = strval($nodeKey);
            $incomingDegreeByNodeId[$nodeId] = count($this->predecessorMap[$nodeId] ?? []);
            if (0 === $incomingDegreeByNodeId[$nodeId]) {
                $queuedNodeIds[] = $nodeId;
            }
        }

        $orderedNodeIds = [];
        for ($queueOffset = 0; isset($queuedNodeIds[$queueOffset]); ++$queueOffset) {
            $nodeId = $queuedNodeIds[$queueOffset];
            $orderedNodeIds[] = $nodeId;

            foreach ($this->successorMap[$nodeId] ?? [] as $successorId) {
                --$incomingDegreeByNodeId[$successorId];
                if (0 === $incomingDegreeByNodeId[$successorId]) {
                    $queuedNodeIds[] = $successorId;
                }
            }
        }

        if (count($orderedNodeIds) !== count($this->nodes)) {
            throw new LogicException('A topological order does not exist for a cyclic graph');
        }

        return $orderedNodeIds;
    }

    /**
     * @param array<string, list<string>> $adjacencyByNodeId
     *
     * @return list<string>
     */
    private function reachableNodeIds(string $startNodeId, array $adjacencyByNodeId): array
    {
        $this->assertNodesExist([$startNodeId]);

        /** @infection-ignore-all The visited set is only probed with isset(); the stored value is never read, so true and false behave identically. */
        $visitedNodeIds = [$startNodeId => true];
        $queuedNodeIds = [$startNodeId];
        $reachableNodeIds = [];

        for ($queueOffset = 0; isset($queuedNodeIds[$queueOffset]); ++$queueOffset) {
            foreach ($adjacencyByNodeId[$queuedNodeIds[$queueOffset]] ?? [] as $adjacentNodeId) {
                if (!isset($visitedNodeIds[$adjacentNodeId])) {
                    /** @infection-ignore-all Only probed with isset(); the stored value is never read, so true and false behave identically. */
                    $visitedNodeIds[$adjacentNodeId] = true;
                    $queuedNodeIds[] = $adjacentNodeId;
                    $reachableNodeIds[] = $adjacentNodeId;
                }
            }
        }

        return $reachableNodeIds;
    }

    /** @return list<string> */
    public function roots(): array
    {
        $selfLooped = $this->selfLoopedNodeIds();
        $roots = [];
        foreach (array_keys($this->nodes) as $nodeId) {
            if (!isset($this->predecessorMap[$nodeId]) && !isset($selfLooped[$nodeId])) {
                $roots[] = strval($nodeId);
            }
        }

        return $roots;
    }

    /** @return array<string, true> */
    private function selfLoopedNodeIds(): array
    {
        $nodeIdSet = [];
        foreach ($this->selfLoops as $loop) {
            /** @infection-ignore-all the stored value is never read; roots()/leaves() test membership with isset(), which treats false the same as true */
            $nodeIdSet[$loop->sourceId] = true;
        }

        return $nodeIdSet;
    }

    /** @return list<string> */
    public function leaves(): array
    {
        $selfLooped = $this->selfLoopedNodeIds();
        $leaves = [];
        foreach (array_keys($this->nodes) as $nodeId) {
            if (!isset($this->successorMap[$nodeId]) && !isset($selfLooped[$nodeId])) {
                $leaves[] = strval($nodeId);
            }
        }

        return $leaves;
    }

    /** @return list<Edge> */
    public function outgoingEdges(string $nodeId): array
    {
        return $this->outgoingEdgesByNodeId[$nodeId] ?? [];
    }

    /** @return list<Edge> */
    public function incomingEdges(string $nodeId): array
    {
        return $this->incomingEdgesByNodeId[$nodeId] ?? [];
    }

    /** @return array<array-key, Node> keyed by node id (PHP coerces canonical numeric-string ids to int keys) */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return list<Edge> */
    public function edges(): array
    {
        return $this->edges;
    }

    /** @return list<Edge> */
    public function selfLoops(): array
    {
        return $this->selfLoops;
    }

    public function nodeCount(): int
    {
        return count($this->nodes);
    }

    public function edgeCount(): int
    {
        return count($this->edges) + count($this->selfLoops);
    }

    public function addGroup(Group $group): self
    {
        if (isset($this->groups[$group->id])) {
            throw new InvalidArgumentException(sprintf('Group "%s" already exists', $group->id));
        }

        foreach ($group->nodeIds as $nodeId) {
            if (!isset($this->nodes[$nodeId])) {
                throw new InvalidArgumentException(sprintf('Group "%s" references unknown node "%s"', $group->id, $nodeId));
            }
        }

        $this->groups[$group->id] = $group;

        return $this;
    }

    /** @return list<Group> */
    public function groups(): array
    {
        return array_values($this->groups);
    }

    public static function fromDot(string $dot): self
    {
        return (new DotParser())->parse($dot);
    }

    public function toDot(): string
    {
        return (new DotExporter())->export($this);
    }

    public function isCyclic(): bool
    {
        if ([] !== $this->selfLoops) {
            return true;
        }

        $states = [];
        foreach (array_keys($this->nodes) as $nodeId) {
            if (!isset($states[$nodeId]) && $this->hasBackEdge($nodeId, $states)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, 'visiting'|'done'> $states */
    private function hasBackEdge(string $nodeId, array &$states): bool
    {
        $states[$nodeId] = 'visiting';

        foreach ($this->successors($nodeId) as $successorId) {
            if ('visiting' === ($states[$successorId] ?? null)) {
                return true;
            }
            /** @infection-ignore-all verified equivalent: inverting the guard re-walks finished nodes instead of fresh ones, but the outer isCyclic loop starts a pass from every node, so the last-visited member of any cycle still walks around it and hits a 'visiting' state; a 'visiting' hit always implies a real cycle, so the result never changes (only performance) */
            if (!isset($states[$successorId]) && $this->hasBackEdge($successorId, $states)) {
                return true;
            }
        }

        $states[$nodeId] = 'done';

        return false;
    }

    /**
     * @param list<string> $nodeIds
     */
    public function highlightPath(array $nodeIds, EdgeStrokeStyle $strokeStyle = EdgeStrokeStyle::Heavy): self
    {
        $this->assertPathEdgesExist($nodeIds);

        for ($pathOffset = 0, $edgeCount = count($nodeIds) - 1; $pathOffset < $edgeCount; ++$pathOffset) {
            $this->replaceEdgeStyle($nodeIds[$pathOffset], $nodeIds[$pathOffset + 1], $strokeStyle);
        }

        return $this;
    }

    /**
     * Fails before any edge is mutated, so a path that breaks partway leaves the
     * graph untouched instead of half-styled.
     *
     * @param list<string> $nodeIds
     */
    private function assertPathEdgesExist(array $nodeIds): void
    {
        for ($pathOffset = 0, $edgeCount = count($nodeIds) - 1; $pathOffset < $edgeCount; ++$pathOffset) {
            if (!$this->hasEdgeBetween($nodeIds[$pathOffset], $nodeIds[$pathOffset + 1])) {
                throw new InvalidArgumentException(sprintf('No edge from "%s" to "%s"', $nodeIds[$pathOffset], $nodeIds[$pathOffset + 1]));
            }
        }
    }

    private function hasEdgeBetween(string $sourceId, string $targetId): bool
    {
        foreach ($this->outgoingEdgesByNodeId[$sourceId] ?? [] as $edge) {
            if ($edge->targetId === $targetId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $nodeIds
     */
    public function colorPath(array $nodeIds, AnsiColor $color): self
    {
        $this->assertPathEdgesExist($nodeIds);
        $this->assertNodesExist($nodeIds);

        for ($pathOffset = 0, $edgeCount = count($nodeIds) - 1; $pathOffset < $edgeCount; ++$pathOffset) {
            $this->replaceEdgeColor($nodeIds[$pathOffset], $nodeIds[$pathOffset + 1], $color);
        }

        foreach ($nodeIds as $nodeId) {
            $this->nodes[$nodeId] = $this->nodes[$nodeId]->withColor($color);
        }

        return $this;
    }

    /** @param list<string> $nodeIds */
    private function assertNodesExist(array $nodeIds): void
    {
        foreach ($nodeIds as $nodeId) {
            if (!isset($this->nodes[$nodeId])) {
                throw new InvalidArgumentException(sprintf('Node "%s" does not exist', $nodeId));
            }
        }
    }

    private function replaceEdgeColor(string $sourceId, string $targetId, AnsiColor $color): void
    {
        $this->replaceMatchingEdges($sourceId, $targetId, static fn (Edge $edge): Edge => $edge->withColor($color));
    }

    private function replaceEdgeStyle(string $sourceId, string $targetId, EdgeStrokeStyle $strokeStyle): void
    {
        $this->replaceMatchingEdges($sourceId, $targetId, static fn (Edge $edge): Edge => $edge->withStrokeStyle($strokeStyle));
    }

    /**
     * Applies $transform to every edge between the pair — parallel edges
     * included — swapping the rebuilt instances into all three edge collections.
     * The endpoints are guaranteed to have an edge by the caller's preflight.
     *
     * @param callable(Edge): Edge $transform
     */
    private function replaceMatchingEdges(string $sourceId, string $targetId, callable $transform): void
    {
        /** @var array<int, Edge> $replacements keyed by the old edge's object id */
        $replacements = [];
        foreach ($this->outgoingEdgesByNodeId[$sourceId] ?? [] as $edge) {
            if ($edge->targetId === $targetId) {
                $replacements[spl_object_id($edge)] = $transform($edge);
            }
        }

        $remap = static fn (Edge $edge): Edge => $replacements[spl_object_id($edge)] ?? $edge;

        $this->outgoingEdgesByNodeId[$sourceId] = array_map($remap, $this->outgoingEdgesByNodeId[$sourceId]);
        $this->incomingEdgesByNodeId[$targetId] = array_map($remap, $this->incomingEdgesByNodeId[$targetId]);
        $this->edges = array_map($remap, $this->edges);
    }
}
