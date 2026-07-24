<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\LayerAssignment;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LongestPathLayering;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LongestPathLayeringTest extends TestCase
{
    #[Test]
    public function implementsLayerAssignmentInterface(): void
    {
        self::assertInstanceOf(LayerAssignment::class, new LongestPathLayering());
    }

    #[Test]
    public function assignsSingleNodeToLayerZero(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $layoutGraph = LayoutGraph::fromGraph($graph);

        $layering = new LongestPathLayering();
        $layers = $layering->assign($layoutGraph);

        self::assertSame(['A' => 0], $layers);
    }

    #[Test]
    public function assignsLinearChainToIncrementingLayers(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);

        self::assertSame(['A' => 0, 'B' => 1, 'C' => 2], $layers);
    }

    #[Test]
    public function assignsDiamondGraphCorrectly(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addNode(new Node('D', 'Delta'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('B', 'D'));
        $graph->addEdge(new Edge('C', 'D'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);

        self::assertSame(0, $layers['A']);
        self::assertSame(1, $layers['B']);
        self::assertSame(1, $layers['C']);
        self::assertSame(2, $layers['D']);
    }

    #[Test]
    public function assignsFanOutCorrectly(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Root'));
        $graph->addNode(new Node('B', 'Left'));
        $graph->addNode(new Node('C', 'Center'));
        $graph->addNode(new Node('D', 'Right'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('A', 'D'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);

        self::assertSame(0, $layers['A']);
        self::assertSame(1, $layers['B']);
        self::assertSame(1, $layers['C']);
        self::assertSame(1, $layers['D']);
    }

    #[Test]
    public function respectsMinLengthConstraint(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addNode(new Node('D', 'Delta'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('B', 'D'));
        $graph->addEdge(new Edge('C', 'D', minLength: 3));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);

        self::assertSame(0, $layers['A']);
        self::assertSame(1, $layers['B']);
        self::assertSame(1, $layers['C']);
        self::assertSame(4, $layers['D']);
    }

    #[Test]
    public function handlesDisconnectedComponents(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addNode(new Node('D', 'Delta'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('C', 'D'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);

        self::assertSame(0, $layers['A']);
        self::assertSame(1, $layers['B']);
        self::assertSame(0, $layers['C']);
        self::assertSame(1, $layers['D']);
    }

    #[Test]
    public function assignsFanInCorrectly(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addNode(new Node('D', 'Delta'));
        $graph->addEdge(new Edge('A', 'D'));
        $graph->addEdge(new Edge('B', 'D'));
        $graph->addEdge(new Edge('C', 'D'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);

        self::assertSame(0, $layers['A']);
        self::assertSame(0, $layers['B']);
        self::assertSame(0, $layers['C']);
        self::assertSame(1, $layers['D']);
    }

    #[Test]
    public function assignsCorrectlyWhenNodesAreInReverseTopologicalOrder(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);

        self::assertSame(0, $layers['A']);
        self::assertSame(1, $layers['B']);
        self::assertSame(2, $layers['C']);
    }
}
