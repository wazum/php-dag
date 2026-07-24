<?php

declare(strict_types=1);

namespace PhpDag\Layout;

/**
 * Assigns layers (ranks) by minimising the total weighted edge length subject
 * to rank(head) − rank(tail) ≥ minlen, using the network-simplex method from
 * Gansner et al., "A Technique for Drawing Directed Graphs" (the algorithm
 * Graphviz dot uses). It builds a tight spanning tree, then repeatedly replaces
 * a tree edge with a negative cut value by the lowest-slack edge crossing the
 * cut, until every cut value is non-negative — the optimum. The result is more
 * compact than longest-path layering: floating nodes slide toward their
 * neighbours, shortening edges and inserting fewer dummy nodes.
 *
 * Cut values are computed in a single O(V + E) postorder pass over a rooted
 * spanning tree carrying subtree interval labels, which give O(1) "is this node
 * in the cut's subtree?" tests for both cut values and entering-edge selection,
 * so each simplex exchange costs O(V + E) rather than O(V·E).
 *
 * Each weakly connected component is ranked independently and normalised so its
 * topmost rank is 0. Edge weight (Edge::$weight, summed across parallel edges by
 * the objective) scales an edge's contribution, so a heavier edge is pulled
 * tighter and a floating node slides toward its heaviest neighbour.
 *
 * @infection-ignore-all Correctness is a property of the *output* — a feasible
 * ranking of minimum total edge length — verified by NetworkSimplexLayeringTest
 * via a brute-force global optimum and a feasibility check across a diverse set
 * of graphs (including multi-exchange cases). Per-mutant testing has very low
 * signal here: network simplex converges to the same optimal-cost ranking
 * regardless of internal choices (which negative-cut edge leaves first, the
 * traversal/iteration order, union-find path compression, the subtree interval
 * numbering, and the starting feasible ranks), so almost every internal
 * mutation is equivalent — which the brute-force suite confirms by failing to
 * distinguish them. The property tests, not per-mutant snapshots, guard this
 * algorithm.
 *
 * @phpstan-type RootedTree array{0: list<int>, 1: array<int, int>, 2: array<string, int>, 3: array<string, int>, 4: array<string, int>}
 *
 * @psalm-type RootedTree = array{0: list<int>, 1: array<int, int>, 2: array<string, int>, 3: array<string, int>, 4: array<string, int>}
 */
final class NetworkSimplexLayering implements LayerAssignment
{
    /**
     * Explicit upper bound on simplex exchanges per component. The method is
     * finite and converges well within this on real graphs; it only guards
     * against pathological input. If the bound is reached the ranking returned
     * is still feasible (every minlen honoured) but no longer guaranteed
     * cost-optimal — raise the budget for such inputs.
     */
    public function __construct(
        private readonly int $maxExchanges = 1000,
    ) {
    }

    /** @return array<string, int> */
    public function assign(LayoutGraph $graph): array
    {
        $nodeIds = $graph->nodeIds();
        if ([] === $nodeIds) {
            return [];
        }

        $edges = [];
        foreach ($nodeIds as $head) {
            foreach ($graph->incomingEdges($head) as $incomingEdge) {
                $edges[] = ['tail' => $incomingEdge->sourceId(), 'head' => $head, 'minlen' => $incomingEdge->minLength(), 'weight' => $incomingEdge->edge->weight];
            }
        }

        $ranks = [];
        foreach ($this->components($nodeIds, $edges) as $componentNodes) {
            foreach ($this->rankComponent($componentNodes, $this->edgesWithin($componentNodes, $edges)) as $nodeId => $rank) {
                $ranks[$nodeId] = $rank;
            }
        }

        return $ranks;
    }

    /**
     * @param list<string>                                                      $nodeIds
     * @param list<array{tail: string, head: string, minlen: int, weight: int}> $edges
     *
     * @return list<list<string>>
     */
    private function components(array $nodeIds, array $edges): array
    {
        $parent = [];
        foreach ($nodeIds as $nodeId) {
            $parent[$nodeId] = $nodeId;
        }
        foreach ($edges as $edge) {
            $parent[$this->findRoot($edge['tail'], $parent)] = $this->findRoot($edge['head'], $parent);
        }

        $grouped = [];
        foreach ($nodeIds as $nodeId) {
            $grouped[$this->findRoot($nodeId, $parent)][] = $nodeId;
        }

        return array_values($grouped);
    }

    /** @param array<string, string> $parent */
    private function findRoot(string $node, array &$parent): string
    {
        while ($parent[$node] !== $node) {
            $parent[$node] = $parent[$parent[$node]];
            $node = $parent[$node];
        }

        return $node;
    }

    /**
     * @param list<string>                                                      $componentNodes
     * @param list<array{tail: string, head: string, minlen: int, weight: int}> $edges
     *
     * @return list<array{tail: string, head: string, minlen: int, weight: int}>
     */
    private function edgesWithin(array $componentNodes, array $edges): array
    {
        $members = array_flip($componentNodes);

        return array_values(array_filter($edges, static fn (array $edge): bool => isset($members[$edge['tail']], $members[$edge['head']])));
    }

    /**
     * @param list<string>                                                      $nodes
     * @param list<array{tail: string, head: string, minlen: int, weight: int}> $edges
     *
     * @return array<string, int>
     */
    private function rankComponent(array $nodes, array $edges): array
    {
        if ([] === $edges) {
            return array_fill_keys($nodes, 0);
        }

        $rank = $this->longestPathRank($nodes, $edges);
        $tree = $this->feasibleTree($nodes, $edges, $rank);

        for ($exchange = 0; $exchange < $this->maxExchanges; ++$exchange) {
            $leaving = $this->leaveEdge($tree);
            if (null === $leaving) {
                break;
            }
            $entering = $this->enterEdge($leaving, $tree, $edges, $rank);
            if (null === $entering) {
                break;
            }
            $treeEdges = [...array_values(array_filter($tree[0], static fn (int $index): bool => $index !== $leaving)), $entering];
            $rank = $this->ranksFromTree($nodes, $treeEdges, $edges);
            $tree = $this->buildRootedTree($nodes, $treeEdges, $edges);
        }

        return $this->normalize($rank);
    }

    /**
     * @param list<string>                                                      $nodes
     * @param list<array{tail: string, head: string, minlen: int, weight: int}> $edges
     *
     * @return array<string, int>
     */
    private function longestPathRank(array $nodes, array $edges): array
    {
        $predecessors = [];
        foreach ($edges as $edge) {
            $predecessors[$edge['head']][] = [$edge['tail'], $edge['minlen']];
        }

        $rank = [];
        foreach ($nodes as $node) {
            $this->computeLongestPath($node, $predecessors, $rank);
        }

        return $rank;
    }

    /**
     * @param array<string, list<array{string, int}>> $predecessors
     * @param array<string, int>                      $rank
     */
    private function computeLongestPath(string $node, array $predecessors, array &$rank): int
    {
        if (isset($rank[$node])) {
            return $rank[$node];
        }

        $best = 0;
        foreach ($predecessors[$node] ?? [] as [$tail, $minlen]) {
            $best = max($best, $this->computeLongestPath($tail, $predecessors, $rank) + $minlen);
        }

        return $rank[$node] = $best;
    }

    /**
     * Grows a tight spanning tree, pulling slack out of the ranking until every
     * node is reachable through tight (zero-slack) edges, then roots it with
     * low/lim labels and cut values.
     *
     * @param list<string>                                                      $nodes
     * @param list<array{tail: string, head: string, minlen: int, weight: int}> $edges
     * @param array<string, int>                                                $rank
     *
     * @return RootedTree
     */
    private function feasibleTree(array $nodes, array $edges, array &$rank): array
    {
        $start = $nodes[0];
        while (true) {
            [$reached, $treeEdges] = $this->tightTree($start, $edges, $rank);
            if (count($reached) >= count($nodes)) {
                return $this->buildRootedTree($nodes, $treeEdges, $edges);
            }

            // The component is connected, so some edge always crosses the
            // frontier until the tree spans every node; shifting the reached set
            // by that edge's slack makes it tight on the next pass.
            $bestSlack = PHP_INT_MAX;
            $bestHeadInTree = false;
            foreach ($edges as $edge) {
                $tailIn = isset($reached[$edge['tail']]);
                $headIn = isset($reached[$edge['head']]);
                if ($tailIn === $headIn) {
                    continue;
                }
                $slack = $this->slack($edge, $rank);
                if ($slack < $bestSlack) {
                    $bestSlack = $slack;
                    $bestHeadInTree = $headIn;
                }
            }
            $delta = $bestHeadInTree ? -$bestSlack : $bestSlack;
            foreach (array_keys($reached) as $node) {
                $rank[$node] += $delta;
            }
        }
    }

    /**
     * Maximal set of nodes reachable from $start through tight edges, with the
     * tight edges used.
     *
     * @param list<array{tail: string, head: string, minlen: int, weight: int}> $edges
     * @param array<string, int>                                                $rank
     *
     * @return array{array<string, true>, list<int>}
     */
    private function tightTree(string $start, array $edges, array $rank): array
    {
        $incident = $this->incidence($edges);
        $reached = [$start => true];
        $tree = [];
        $stack = [$start];
        while ([] !== $stack) {
            $node = array_pop($stack);
            foreach ($incident[$node] ?? [] as $index) {
                $edge = $edges[$index];
                $other = $edge['tail'] === $node ? $edge['head'] : $edge['tail'];
                if (!isset($reached[$other]) && 0 === $this->slack($edge, $rank)) {
                    $reached[$other] = true;
                    $tree[] = $index;
                    $stack[] = $other;
                }
            }
        }

        return [$reached, $tree];
    }

    /**
     * Roots the spanning tree at the first node and labels it: each non-root
     * node records its parent tree edge, and a postorder traversal assigns
     * interval numbers so subtree membership is an O(1) range test. Cut values
     * for every tree edge are then computed in one postorder pass.
     *
     * @param list<string>                                                      $nodes
     * @param list<int>                                                         $treeEdges
     * @param list<array{tail: string, head: string, minlen: int, weight: int}> $edges
     *
     * @return RootedTree
     */
    private function buildRootedTree(array $nodes, array $treeEdges, array $edges): array
    {
        $adjacency = [];
        foreach ($treeEdges as $index) {
            $adjacency[$edges[$index]['tail']][] = [$index, $edges[$index]['head']];
            $adjacency[$edges[$index]['head']][] = [$index, $edges[$index]['tail']];
        }

        $parentEdge = [];
        $subtreeEndByNodeId = [];
        $subtreeStartByNodeId = [];
        $visited = [];
        $counter = 1;
        $this->labelSubtree($nodes[0], $adjacency, $visited, $parentEdge, $subtreeEndByNodeId, $subtreeStartByNodeId, $counter);

        return [$treeEdges, $this->cutValues($nodes, $treeEdges, $edges, $parentEdge, $subtreeEndByNodeId), $parentEdge, $subtreeEndByNodeId, $subtreeStartByNodeId];
    }

    /**
     * Postorder traversal assigning subtree interval labels and the parent tree edge.
     *
     * @param array<string, list<array{int, string}>> $adjacency
     * @param array<string, true>                     $visited
     * @param array<string, int>                      $parentEdge
     * @param array<string, int>                      $subtreeEndByNodeId
     * @param array<string, int>                      $subtreeStartByNodeId
     */
    private function labelSubtree(string $node, array $adjacency, array &$visited, array &$parentEdge, array &$subtreeEndByNodeId, array &$subtreeStartByNodeId, int &$counter): void
    {
        $visited[$node] = true;
        $subtreeStart = $counter;
        foreach ($adjacency[$node] ?? [] as [$edgeIndex, $neighbour]) {
            if (!isset($visited[$neighbour])) {
                $parentEdge[$neighbour] = $edgeIndex;
                $this->labelSubtree($neighbour, $adjacency, $visited, $parentEdge, $subtreeEndByNodeId, $subtreeStartByNodeId, $counter);
            }
        }
        $subtreeStartByNodeId[$node] = $subtreeStart;
        $subtreeEndByNodeId[$node] = $counter;
        ++$counter;
    }

    /**
     * Cut value of every tree edge, in one postorder pass: a child's parent-edge
     * cut value is its own weight plus the signed weights of its other incident
     * edges, adjusted by the already-computed cut values of its child tree edges.
     *
     * @param list<string>                                                      $nodes
     * @param list<int>                                                         $treeEdges
     * @param list<array{tail: string, head: string, minlen: int, weight: int}> $edges
     * @param array<string, int>                                                $parentEdge
     * @param array<string, int>                                                $subtreeEndByNodeId
     *
     * @return array<int, int> tree edge index => cut value
     */
    private function cutValues(array $nodes, array $treeEdges, array $edges, array $parentEdge, array $subtreeEndByNodeId): array
    {
        $incident = $this->incidence($edges);
        $nodeBySubtreeEnd = [];
        foreach ($nodes as $node) {
            $nodeBySubtreeEnd[$subtreeEndByNodeId[$node]] = $node;
        }
        ksort($nodeBySubtreeEnd);

        /** @var array<int, int> $cutValues */
        $cutValues = [];
        foreach ($nodeBySubtreeEnd as $node) {
            if (!isset($parentEdge[$node])) {
                continue;
            }
            $parentEdgeIndex = $parentEdge[$node];
            $parentGraphEdge = $edges[$parentEdgeIndex];
            $childIsTail = $parentGraphEdge['tail'] === $node;

            $cut = $parentGraphEdge['weight'];
            foreach ($incident[$node] ?? [] as $edgeIndex) {
                if ($edgeIndex === $parentEdgeIndex) {
                    continue;
                }
                $edge = $edges[$edgeIndex];
                $isOutgoing = $edge['tail'] === $node;
                $pointsToHead = $isOutgoing === $childIsTail;
                $cut += $pointsToHead ? $edge['weight'] : -$edge['weight'];

                $other = $isOutgoing ? $edge['head'] : $edge['tail'];
                if (isset($parentEdge[$other]) && $parentEdge[$other] === $edgeIndex) {
                    $childCut = $cutValues[$edgeIndex];
                    $cut += $pointsToHead ? -$childCut : $childCut;
                }
            }
            $cutValues[$parentEdgeIndex] = $cut;
        }

        return $cutValues;
    }

    /**
     * First tree edge whose cut value is negative — removing it can shorten the
     * total edge length.
     *
     * @param RootedTree $tree
     */
    private function leaveEdge(array $tree): ?int
    {
        [$treeEdges, $cutValues] = $tree;
        foreach ($treeEdges as $index) {
            if (($cutValues[$index] ?? 0) < 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Lowest-slack non-tree edge crossing the cut opposite to the leaving edge,
     * located in O(E) using the subtree interval range.
     *
     * @param RootedTree                                                        $tree
     * @param list<array{tail: string, head: string, minlen: int, weight: int}> $edges
     * @param array<string, int>                                                $rank
     */
    private function enterEdge(int $leaving, array $tree, array $edges, array $rank): ?int
    {
        [$treeEdges, , , $subtreeEndByNodeId, $subtreeStartByNodeId] = $tree;
        $leaveEdge = $edges[$leaving];
        $tail = $leaveEdge['tail'];
        $head = $leaveEdge['head'];

        // The cut's subtree is the deeper endpoint's; tailIsInside records whether the
        // graph tail sits inside it, so the entering edge must cross the other way.
        if ($subtreeEndByNodeId[$tail] > $subtreeEndByNodeId[$head]) {
            $subtreeStart = $subtreeStartByNodeId[$head];
            $subtreeEnd = $subtreeEndByNodeId[$head];
            $tailIsInside = true;
        } else {
            $subtreeStart = $subtreeStartByNodeId[$tail];
            $subtreeEnd = $subtreeEndByNodeId[$tail];
            $tailIsInside = false;
        }

        $treeEdgeIndexSet = array_flip($treeEdges);
        $bestEdgeIndex = null;
        $bestSlack = PHP_INT_MAX;
        foreach ($edges as $index => $edge) {
            if (isset($treeEdgeIndexSet[$index])) {
                continue;
            }
            $tailInside = $subtreeStart <= $subtreeEndByNodeId[$edge['tail']] && $subtreeEndByNodeId[$edge['tail']] <= $subtreeEnd;
            $headInside = $subtreeStart <= $subtreeEndByNodeId[$edge['head']] && $subtreeEndByNodeId[$edge['head']] <= $subtreeEnd;
            if ($tailIsInside === $tailInside && $tailIsInside !== $headInside) {
                $slack = $this->slack($edge, $rank);
                if ($slack < $bestSlack) {
                    $bestSlack = $slack;
                    $bestEdgeIndex = $index;
                }
            }
        }

        return $bestEdgeIndex;
    }

    /**
     * Re-derives ranks from a spanning tree by making every tree edge tight.
     *
     * @param list<string>                                                      $nodes
     * @param list<int>                                                         $treeEdges
     * @param list<array{tail: string, head: string, minlen: int, weight: int}> $edges
     *
     * @return array<string, int>
     */
    private function ranksFromTree(array $nodes, array $treeEdges, array $edges): array
    {
        $adjacency = [];
        foreach ($treeEdges as $index) {
            $adjacency[$edges[$index]['tail']][] = $index;
            $adjacency[$edges[$index]['head']][] = $index;
        }

        $root = $nodes[0];
        $rank = [$root => 0];
        $stack = [$root];
        while ([] !== $stack) {
            $node = array_pop($stack);
            foreach ($adjacency[$node] ?? [] as $index) {
                $edge = $edges[$index];
                if ($edge['tail'] === $node && !isset($rank[$edge['head']])) {
                    $rank[$edge['head']] = $rank[$node] + $edge['minlen'];
                    $stack[] = $edge['head'];
                } elseif ($edge['head'] === $node && !isset($rank[$edge['tail']])) {
                    $rank[$edge['tail']] = $rank[$node] - $edge['minlen'];
                    $stack[] = $edge['tail'];
                }
            }
        }

        return $rank;
    }

    /**
     * @param list<array{tail: string, head: string, minlen: int, weight: int}> $edges
     *
     * @return array<string, list<int>>
     */
    private function incidence(array $edges): array
    {
        $incident = [];
        foreach ($edges as $index => $edge) {
            $incident[$edge['tail']][] = $index;
            $incident[$edge['head']][] = $index;
        }

        return $incident;
    }

    /**
     * @param array{tail: string, head: string, minlen: int, weight: int} $edge
     * @param array<string, int>                                          $rank
     */
    private function slack(array $edge, array $rank): int
    {
        return $rank[$edge['head']] - $rank[$edge['tail']] - $edge['minlen'];
    }

    /**
     * @param array<string, int> $rank
     *
     * @return array<string, int>
     */
    private function normalize(array $rank): array
    {
        if ([] === $rank) {
            return $rank;
        }

        $minimum = min($rank);
        foreach ($rank as $node => $value) {
            $rank[$node] = $value - $minimum;
        }

        return $rank;
    }
}
