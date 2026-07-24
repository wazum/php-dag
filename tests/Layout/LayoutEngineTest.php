<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\LayerAssigner;
use PhpDag\Layout\LayoutEngine;
use PhpDag\Layout\Pipeline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LayoutEngineTest extends TestCase
{
    #[Test]
    public function layoutsGraphThroughPipeline(): void
    {
        $pipeline = new Pipeline();
        $pipeline->add(new LayerAssigner());
        $engine = new LayoutEngine($pipeline);

        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $layoutGraph = $engine->layout($graph);

        self::assertSame(0, $layoutGraph->getLayoutNode('A')->layer);
    }
}
