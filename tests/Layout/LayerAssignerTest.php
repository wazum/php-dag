<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\LayerAssigner;
use PhpDag\Layout\LayoutGraph;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LayerAssignerTest extends TestCase
{
    #[Test]
    public function assignsLayersAndBuildsIndex(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addEdge(new Edge('A', 'B'));
        $layoutGraph = LayoutGraph::fromGraph($graph);

        (new LayerAssigner())->process($layoutGraph);

        self::assertSame(0, $layoutGraph->getLayoutNode('A')->layer);
        self::assertSame(1, $layoutGraph->getLayoutNode('B')->layer);
        self::assertSame(2, $layoutGraph->layerCount());
    }
}
