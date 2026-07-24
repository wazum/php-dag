<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\CrossingMinimization;
use PhpDag\Layout\CrossingMinimizer;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\Processor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CrossingMinimizerTest extends TestCase
{
    #[Test]
    public function delegatesToStrategy(): void
    {
        $strategy = new class implements CrossingMinimization {
            public bool $called = false;

            public function minimize(LayoutGraph $graph): void
            {
                $this->called = true;
            }
        };

        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $layoutGraph = LayoutGraph::fromGraph($graph);

        $processor = new CrossingMinimizer($strategy);
        $processor->process($layoutGraph);

        self::assertTrue($strategy->called);
    }

    #[Test]
    public function canBeConstructedWithoutArguments(): void
    {
        $minimizer = new CrossingMinimizer();

        self::assertInstanceOf(Processor::class, $minimizer);
    }
}
