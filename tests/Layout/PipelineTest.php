<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use ArrayObject;
use InvalidArgumentException;
use LogicException;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\CycleBreaker;
use PhpDag\Layout\DepthFirstOrdering;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\Pipeline;
use PhpDag\Layout\Processor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    /** @var ArrayObject<int, string> */
    private ArrayObject $executionOrder;

    protected function setUp(): void
    {
        $this->executionOrder = new ArrayObject();
    }

    #[Test]
    public function containsReportsWhetherAProcessorClassIsPresent(): void
    {
        $pipeline = new Pipeline();
        $pipeline->add(new CycleBreaker());

        self::assertTrue($pipeline->contains(CycleBreaker::class));
        self::assertFalse($pipeline->contains(DepthFirstOrdering::class));
    }

    #[Test]
    public function insertBeforePlacesProcessorBeforeTheGivenClass(): void
    {
        $existing = $this->recordingProcessor('existing');
        $inserted = $this->recordingProcessor('inserted');

        $pipeline = new Pipeline();
        $pipeline->add($existing);
        $pipeline->insertBefore($existing::class, $inserted);

        $pipeline->execute($this->emptyLayoutGraph());

        self::assertSame(['inserted', 'existing'], $this->executionOrder->getArrayCopy());
    }

    #[Test]
    public function insertAfterPlacesProcessorAfterTheGivenClass(): void
    {
        $existing = $this->recordingProcessor('existing');
        $last = new class implements Processor {
            public function process(LayoutGraph $graph): void
            {
            }
        };

        $pipeline = new Pipeline();
        $pipeline->add($existing);
        $pipeline->add($last);
        $pipeline->insertAfter($existing::class, $this->recordingProcessor('inserted'));

        $pipeline->execute($this->emptyLayoutGraph());

        self::assertSame(['existing', 'inserted'], $this->executionOrder->getArrayCopy());
    }

    #[Test]
    public function replaceSwapsProcessorOfTheGivenClass(): void
    {
        $original = new class implements Processor {
            public function process(LayoutGraph $graph): void
            {
                throw new LogicException('Replaced processor must not run');
            }
        };

        $pipeline = new Pipeline();
        $pipeline->add($this->recordingProcessor('kept'));
        $pipeline->add($original);
        $pipeline->replace($original::class, $this->recordingProcessor('replacement'));

        $pipeline->execute($this->emptyLayoutGraph());

        self::assertSame(['kept', 'replacement'], $this->executionOrder->getArrayCopy());
    }

    #[Test]
    public function removeDeletesProcessorOfTheGivenClass(): void
    {
        $removed = new class implements Processor {
            public function process(LayoutGraph $graph): void
            {
                throw new LogicException('Removed processor must not run');
            }
        };

        $pipeline = new Pipeline();
        $pipeline->add($this->recordingProcessor('kept'));
        $pipeline->add($removed);
        $pipeline->remove($removed::class);

        $pipeline->execute($this->emptyLayoutGraph());

        self::assertSame(['kept'], $this->executionOrder->getArrayCopy());
    }

    #[Test]
    public function insertBeforeKeepsProcessorsAfterTheInsertionPoint(): void
    {
        $anchor = $this->anchorProcessor('anchor');

        $pipeline = new Pipeline();
        $pipeline->add($this->recordingProcessor('first'));
        $pipeline->add($anchor);
        $pipeline->add($this->recordingProcessor('last'));
        $pipeline->insertBefore($anchor::class, $this->recordingProcessor('inserted'));

        $pipeline->execute($this->emptyLayoutGraph());

        self::assertSame(['first', 'inserted', 'anchor', 'last'], $this->executionOrder->getArrayCopy());
    }

    #[Test]
    public function insertAfterKeepsProcessorsAfterTheInsertionPoint(): void
    {
        $anchor = $this->anchorProcessor('anchor');

        $pipeline = new Pipeline();
        $pipeline->add($anchor);
        $pipeline->add($this->recordingProcessor('second'));
        $pipeline->add($this->recordingProcessor('third'));
        $pipeline->insertAfter($anchor::class, $this->recordingProcessor('inserted'));

        $pipeline->execute($this->emptyLayoutGraph());

        self::assertSame(['anchor', 'inserted', 'second', 'third'], $this->executionOrder->getArrayCopy());
    }

    #[Test]
    public function replaceKeepsProcessorsAfterTheReplacedOne(): void
    {
        $replaced = new class implements Processor {
            public function process(LayoutGraph $graph): void
            {
                throw new LogicException('Replaced processor must not run');
            }
        };

        $pipeline = new Pipeline();
        $pipeline->add($this->recordingProcessor('before'));
        $pipeline->add($replaced);
        $pipeline->add($this->recordingProcessor('after'));
        $pipeline->replace($replaced::class, $this->recordingProcessor('replacement'));

        $pipeline->execute($this->emptyLayoutGraph());

        self::assertSame(['before', 'replacement', 'after'], $this->executionOrder->getArrayCopy());
    }

    #[Test]
    public function removeKeepsProcessorsAfterTheRemovedOne(): void
    {
        $removed = new class implements Processor {
            public function process(LayoutGraph $graph): void
            {
                throw new LogicException('Removed processor must not run');
            }
        };

        $pipeline = new Pipeline();
        $pipeline->add($this->recordingProcessor('before'));
        $pipeline->add($removed);
        $pipeline->add($this->recordingProcessor('after'));
        $pipeline->remove($removed::class);

        $pipeline->execute($this->emptyLayoutGraph());

        self::assertSame(['before', 'after'], $this->executionOrder->getArrayCopy());
    }

    #[Test]
    public function insertBeforeThrowsForUnknownProcessorClass(): void
    {
        $pipeline = new Pipeline();

        $this->expectException(InvalidArgumentException::class);
        $pipeline->insertBefore(CycleBreaker::class, $this->recordingProcessor('inserted'));
    }

    private function recordingProcessor(string $label): Processor
    {
        return new class($this->executionOrder, $label) implements Processor {
            /** @param ArrayObject<int, string> $executionOrder */
            public function __construct(
                private readonly ArrayObject $executionOrder,
                private readonly string $label,
            ) {
            }

            public function process(LayoutGraph $graph): void
            {
                $this->executionOrder->append($this->label);
            }
        };
    }

    /** Same recording behavior as recordingProcessor() but a distinct class, so indexOf() finds exactly this one. */
    private function anchorProcessor(string $label): Processor
    {
        return new class($this->executionOrder, $label) implements Processor {
            /** @param ArrayObject<int, string> $executionOrder */
            public function __construct(
                private readonly ArrayObject $executionOrder,
                private readonly string $label,
            ) {
            }

            public function process(LayoutGraph $graph): void
            {
                $this->executionOrder->append($this->label);
            }
        };
    }

    private function emptyLayoutGraph(): LayoutGraph
    {
        return LayoutGraph::fromGraph(new Graph());
    }

    #[Test]
    public function executesProcessorsInOrder(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $layoutGraph = LayoutGraph::fromGraph($graph);

        $pipeline = new Pipeline();
        $pipeline->add(new class implements Processor {
            public function process(LayoutGraph $graph): void
            {
                $graph->getLayoutNode('A')->layer = 5;
            }
        });
        $pipeline->add(new class implements Processor {
            public function process(LayoutGraph $graph): void
            {
                $graph->getLayoutNode('A')->layer = $graph->getLayoutNode('A')->layer * 2;
            }
        });

        $pipeline->execute($layoutGraph);

        self::assertSame(10, $layoutGraph->getLayoutNode('A')->layer);
    }
}
