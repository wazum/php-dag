<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\LayerAssignment;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\NetworkSimplexLayering;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NetworkSimplexLayeringTest extends TestCase
{
    #[Test]
    public function implementsLayerAssignment(): void
    {
        self::assertInstanceOf(LayerAssignment::class, new NetworkSimplexLayering());
    }

    #[Test]
    public function ranksALinearChainTightly(): void
    {
        $ranks = $this->rank(
            ['a', 'b', 'c'],
            [['a', 'b'], ['b', 'c']],
        );

        self::assertSame(['a' => 0, 'b' => 1, 'c' => 2], $this->sorted($ranks));
    }

    #[Test]
    public function heavierEdgePullsAFloatingNodeTowardIt(): void
    {
        // D is pinned to rank 3 by the long A→C→E→D path, so B floats in [1, 2].
        // With equal weights it sits at rank 1; a heavy B→D edge pulls it to 2 to
        // shorten that edge.
        $graph = new Graph();
        foreach (['A', 'B', 'C', 'E', 'D'] as $id) {
            $graph->addNode(new Node($id, $id));
        }
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'D', weight: 9));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('C', 'E'));
        $graph->addEdge(new Edge('E', 'D'));

        $ranks = (new NetworkSimplexLayering())->assign(LayoutGraph::fromGraph($graph));

        self::assertSame(3, $ranks['D']);
        self::assertSame(2, $ranks['B'], 'The heavy B→D edge should pull B down to rank 2');
    }

    #[Test]
    public function keepsEveryEdgeFeasible(): void
    {
        [$graph, $ranks] = $this->rankWithGraph(
            ['s', 'x', 'p', 'q', 'a', 'b', 'c', 'd'],
            [['s', 'x'], ['x', 'p'], ['x', 'q'], ['s', 'a'], ['a', 'b'], ['b', 'p'], ['s', 'c'], ['c', 'd'], ['d', 'q']],
        );

        foreach ($graph->edges() as $edge) {
            $span = $ranks[$edge->targetId()] - $ranks[$edge->sourceId()];
            self::assertGreaterThanOrEqual($edge->minLength(), $span, "Edge {$edge->sourceId()}->{$edge->targetId()} violates minlen");
        }
    }

    #[Test]
    public function pullsAFloatingNodeDownToMinimiseTotalEdgeLength(): void
    {
        // 's' fans to 'x' and to two length-3 chains ending at 'p' and 'q'.
        // Longest-path pins x at rank 1 (its two edges to p/q then span 2 each);
        // network simplex slides x down to rank 2, shortening both to 1 — total
        // edge length 10 instead of longest-path's 11.
        $ranks = $this->rank(
            ['s', 'x', 'p', 'q', 'a', 'b', 'c', 'd'],
            [['s', 'x'], ['x', 'p'], ['x', 'q'], ['s', 'a'], ['a', 'b'], ['b', 'p'], ['s', 'c'], ['c', 'd'], ['d', 'q']],
        );

        self::assertSame(0, $ranks['s']);
        self::assertSame(2, $ranks['x']);
        self::assertSame(3, $ranks['p']);
        self::assertSame(3, $ranks['q']);
    }

    #[Test]
    public function ranksEachDisconnectedComponentFromZero(): void
    {
        $ranks = $this->rank(
            ['a', 'b', 'c', 'd'],
            [['a', 'b'], ['c', 'd']],
        );

        self::assertSame(['a' => 0, 'b' => 1, 'c' => 0, 'd' => 1], $this->sorted($ranks));
    }

    #[Test]
    public function ranksADiamondLikeLongestPath(): void
    {
        $ranks = $this->rank(
            ['a', 'b', 'c', 'd'],
            [['a', 'b'], ['a', 'c'], ['b', 'd'], ['c', 'd']],
        );

        self::assertSame(['a' => 0, 'b' => 1, 'c' => 1, 'd' => 2], $this->sorted($ranks));
    }

    #[Test]
    public function achievesTheGlobalMinimumTotalEdgeLength(): void
    {
        // Each graph is small enough to brute-force the optimum; network simplex
        // must match it. Any mutation that breaks feasibility or optimality
        // produces a different total and fails here.
        $cases = [
            [['a', 'b', 'c'], [['a', 'b'], ['b', 'c']]],
            [['a', 'b', 'c', 'd'], [['a', 'b'], ['a', 'c'], ['b', 'd'], ['c', 'd']]],
            [['s', 'x', 'p', 'q', 'a', 'b', 'c', 'd'], [['s', 'x'], ['x', 'p'], ['x', 'q'], ['s', 'a'], ['a', 'b'], ['b', 'p'], ['s', 'c'], ['c', 'd'], ['d', 'q']]],
            [['r', 'u', 'v', 'w', 't'], [['r', 'u'], ['r', 'v'], ['u', 'w'], ['v', 'w'], ['w', 't'], ['r', 't']]],
            [['a', 'b', 'c', 'd', 'e', 'f'], [['a', 'b'], ['a', 'f'], ['b', 'c'], ['c', 'd'], ['d', 'e'], ['e', 'f']]],
            // Two floating nodes pulled down toward a shared deep sink.
            [['s', 'm', 'n', 'p', 'a', 'b'], [['s', 'm'], ['m', 'p'], ['s', 'n'], ['n', 'p'], ['s', 'a'], ['a', 'b'], ['b', 'p']]],
            // Asymmetric float plus a transitive long edge.
            [['r', 'x', 'y', 'z', 't'], [['r', 'x'], ['x', 't'], ['r', 'y'], ['y', 'z'], ['z', 't'], ['r', 't']]],
            // Transitive edge skipping a four-layer chain with a side branch.
            [['p', 'q', 'r', 's', 't', 'u'], [['p', 'q'], ['q', 'r'], ['r', 's'], ['s', 't'], ['t', 'u'], ['p', 'u'], ['q', 'u']]],
            // Two diamonds sharing a middle, exercising several exchanges.
            [['a', 'b', 'c', 'd', 'e', 'g'], [['a', 'b'], ['a', 'c'], ['b', 'd'], ['c', 'd'], ['d', 'e'], ['d', 'g'], ['e', 'g']]],
        ];

        foreach ($cases as [$nodes, $edges]) {
            $ranks = $this->rank($nodes, $edges);
            self::assertSame(
                $this->bruteForceMinimumTotal($nodes, $edges),
                $this->totalLength($edges, $ranks),
                'Total edge length is not the global minimum for '.implode(',', $nodes),
            );
        }
    }

    /**
     * @param list<array{string, string}> $edges
     * @param array<string, int>          $ranks
     */
    private function totalLength(array $edges, array $ranks): int
    {
        $total = 0;
        foreach ($edges as [$source, $target]) {
            $total += $ranks[$target] - $ranks[$source];
        }

        return $total;
    }

    /**
     * @param list<string>                $nodes
     * @param list<array{string, string}> $edges
     */
    private function bruteForceMinimumTotal(array $nodes, array $edges): int
    {
        if ([] === $nodes) {
            return 0;
        }

        // The optimum never exceeds the longest-path depth, so bound the search
        // there to keep the enumeration small.
        $longest = array_fill_keys($nodes, 0);
        for ($pass = 0, $count = count($nodes); $pass < $count; ++$pass) {
            foreach ($edges as [$source, $target]) {
                $longest[$target] = max($longest[$target], $longest[$source] + 1);
            }
        }
        $maxRank = max($longest);
        $best = PHP_INT_MAX;
        /** @var array<string, int> $assignment */
        $assignment = [];

        $explore = function (int $index) use (&$explore, &$assignment, &$best, $nodes, $edges, $maxRank): void {
            if ($index === count($nodes)) {
                $total = 0;
                foreach ($edges as [$source, $target]) {
                    $span = (int) $assignment[$target] - (int) $assignment[$source];
                    if ($span < 1) {
                        return; // infeasible (minlen 1)
                    }
                    $total += $span;
                }
                $best = min($best, $total);

                return;
            }
            for ($rank = 0; $rank <= $maxRank; ++$rank) {
                $assignment[$nodes[$index]] = $rank;
                $explore($index + 1);
            }
        };
        $explore(0);

        return $best;
    }

    /**
     * @param list<string>                $nodes
     * @param list<array{string, string}> $edges
     *
     * @return array<string, int>
     */
    private function rank(array $nodes, array $edges): array
    {
        return $this->rankWithGraph($nodes, $edges)[1];
    }

    /**
     * @param list<string>                $nodes
     * @param list<array{string, string}> $edges
     *
     * @return array{LayoutGraph, array<string, int>}
     */
    private function rankWithGraph(array $nodes, array $edges): array
    {
        $graph = new Graph();
        foreach ($nodes as $nodeId) {
            $graph->addNode(new Node($nodeId, $nodeId));
        }
        foreach ($edges as [$source, $target]) {
            $graph->addEdge(new Edge($source, $target));
        }

        $layoutGraph = LayoutGraph::fromGraph($graph);

        return [$layoutGraph, (new NetworkSimplexLayering())->assign($layoutGraph)];
    }

    /**
     * @param array<string, int> $ranks
     *
     * @return array<string, int>
     */
    private function sorted(array $ranks): array
    {
        ksort($ranks);

        return $ranks;
    }
}
