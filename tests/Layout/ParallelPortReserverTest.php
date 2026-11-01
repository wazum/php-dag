<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Layout\FlowDirection;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\ParallelPortReserver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParallelPortReserverTest extends TestCase
{
    /**
     * @param array<array-key, int> $expectedWidths
     */
    #[Test]
    #[DataProvider('topToBottomGraphs')]
    public function widensATopToBottomBoxToOnePortPerParallelEdgePlusOneSharedTrunkOnItsBusiestSide(string $dot, array $expectedWidths): void
    {
        $graph = LayoutGraph::fromGraph(Graph::fromDot($dot));

        (new ParallelPortReserver(FlowDirection::TopToBottom))->process($graph);

        foreach ($expectedWidths as $nodeId => $width) {
            self::assertSame($width, $graph->getLayoutNode(strval($nodeId))->boxWidth(), strval($nodeId));
        }
    }

    #[Test]
    public function growsALeftToRightBoxTallerInsteadOfWider(): void
    {
        $graph = LayoutGraph::fromGraph(Graph::fromDot('digraph { a -> b; a -> b; a -> c; a -> c }'));

        (new ParallelPortReserver(FlowDirection::LeftToRight))->process($graph);

        self::assertSame([9, 5], [$graph->getLayoutNode('a')->boxHeight(), $graph->getLayoutNode('a')->boxWidth()]);
        self::assertSame(5, $graph->getLayoutNode('b')->boxHeight());
    }

    #[Test]
    public function ignoresAFeedbackEdgeThatSharesItsPairWithAForwardEdge(): void
    {
        $graph = LayoutGraph::fromGraph(Graph::fromDot('digraph { a; b }'));
        $graph->addEdge(new LayoutEdge(new Edge('b', 'a'), reversed: true));
        $graph->addEdge(new LayoutEdge(new Edge('a', 'b')));

        (new ParallelPortReserver(FlowDirection::LeftToRight))->process($graph);

        self::assertSame(3, $graph->getLayoutNode('a')->boxHeight());
    }

    /** @return iterable<string, array{string, array<array-key, int>}> */
    public static function topToBottomGraphs(): iterable
    {
        yield 'pair and single leaving one box' => ['digraph { a -> b; a -> b; a -> c }', ['a' => 7, 'b' => 5, 'c' => 5]];
        yield 'pair and single entering one box' => ['digraph { a -> c; b -> c; b -> c }', ['c' => 7, 'b' => 5, 'a' => 5]];
        yield 'busier side wins' => ['digraph { x -> y; x -> y; y -> z; y -> z; y -> z }', ['y' => 7, 'x' => 5, 'z' => 7]];
        yield 'a labelled single edge leaves the spacing alone' => ['digraph { a -> b; a -> b; a -> c [label="wide label"] }', ['a' => 7]];
        yield 'labelled parallel edges space the ports' => ['digraph { a -> b [label="one"]; a -> b [label="two"]; a -> c }', ['a' => 15, 'b' => 9, 'c' => 5]];
        yield 'even label widths round the spacing up to stay even' => ['digraph { a -> b [label="ab"]; a -> b [label="cd"]; a -> c }', ['a' => 11]];
        yield 'the widest label sets the spacing' => ['digraph { a -> b [label="p"]; a -> b [label="wide"]; a -> c }', ['a' => 15]];
        yield 'numeric ids' => ['digraph { 1 -> 2; 1 -> 2; 1 -> 3 }', ['1' => 7]];
    }
}
