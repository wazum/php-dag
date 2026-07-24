<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\AsciiDagBuilder;
use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\LayoutEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParallelLongEdgePreservationTest extends TestCase
{
    #[Test]
    public function keepsBothParallelEdgesThatSpanDummyLayers(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'))
            ->addNode(new Node('B', 'B'))
            ->addNode(new Node('C', 'C'))
            ->addEdge(new Edge('A', 'B'))
            ->addEdge(new Edge('B', 'C'))
            ->addEdge(new Edge('A', 'C'))
            ->addEdge(new Edge('A', 'C'));

        $engine = new LayoutEngine((new AsciiDagBuilder())->defaultPipeline());
        $layoutGraph = $engine->layout($graph);

        self::assertCount(4, $layoutGraph->edges());
    }
}
