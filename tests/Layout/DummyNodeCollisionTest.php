<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\AsciiDagBuilder;
use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\LayoutEngine;
use PhpDag\Layout\RealLayoutNode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DummyNodeCollisionTest extends TestCase
{
    #[Test]
    public function aRealNodeNamedLikeAGeneratedDummyIsNotClobbered(): void
    {
        // A→B→C puts C on rank 2, so the long A→C edge inserts a dummy on rank 1
        // whose natural id is "__dummy_A_C_1" — the exact id of a real node here.
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addNode(new Node('__dummy_A_C_1', 'Real'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('B', '__dummy_A_C_1'));

        $engine = new LayoutEngine((new AsciiDagBuilder())->defaultPipeline());
        $layoutGraph = $engine->layout($graph);

        self::assertTrue($layoutGraph->hasNode('__dummy_A_C_1'), 'The real node must survive dummy insertion');
        self::assertInstanceOf(RealLayoutNode::class, $layoutGraph->getLayoutNode('__dummy_A_C_1'));
    }
}
