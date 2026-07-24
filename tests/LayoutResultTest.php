<?php

declare(strict_types=1);

namespace PhpDag\Tests;

use InvalidArgumentException;
use PhpDag\AsciiDag;
use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\LayoutResult;
use PhpDag\Tests\Support\ZeroWriteStreamWrapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LayoutResultTest extends TestCase
{
    #[Test]
    public function layoutRendersTheSameOutputAsRender(): void
    {
        $graph = $this->diamond();
        $dag = AsciiDag::default();

        $result = $dag->layout($graph);

        self::assertInstanceOf(LayoutResult::class, $result);
        self::assertSame($dag->render($graph), $result->render());
    }

    #[Test]
    public function renderToStreamWritesTheSameBytesAsRender(): void
    {
        $result = AsciiDag::default()->layout($this->diamond());

        $stream = fopen('php://memory', 'rb+');
        self::assertNotFalse($stream);
        $result->renderTo($stream);
        rewind($stream);
        $streamed = stream_get_contents($stream);
        fclose($stream);

        self::assertSame($result->render(), $streamed);
    }

    #[Test]
    public function reportsTheVisibleWidthAndHeightOfTheDrawing(): void
    {
        $result = AsciiDag::default()->layout($this->diamond());

        $lines = explode("\n", $result->render());
        $expectedWidth = max(array_map(mb_strwidth(...), $lines));

        self::assertSame(count($lines), $result->height());
        self::assertSame($expectedWidth, $result->width());
    }

    #[Test]
    public function anEmptyGraphHasZeroDimensionsAndRendersNothing(): void
    {
        $result = AsciiDag::default()->layout(new Graph());

        self::assertSame(0, $result->width());
        self::assertSame(0, $result->height());
        self::assertSame('', $result->render());

        $stream = fopen('php://memory', 'rb+');
        self::assertNotFalse($stream);
        $result->renderTo($stream);
        rewind($stream);
        self::assertSame('', stream_get_contents($stream));
        fclose($stream);
    }

    #[Test]
    public function renderToRejectsAReadOnlyStreamBeforeWriting(): void
    {
        $result = AsciiDag::default()->layout($this->diamond());
        $stream = fopen('php://memory', 'rb');
        self::assertNotFalse($stream);

        $this->expectException(InvalidArgumentException::class);

        try {
            $result->renderTo($stream);
        } finally {
            fclose($stream);
        }
    }

    #[Test]
    public function renderToThrowsWhenTheStreamAcceptsNoBytes(): void
    {
        $result = AsciiDag::default()->layout($this->diamond());
        stream_wrapper_register('zerowrite', ZeroWriteStreamWrapper::class);

        try {
            $stream = fopen('zerowrite://stuck', 'wb');
            self::assertNotFalse($stream);

            $this->expectException(RuntimeException::class);

            try {
                $result->renderTo($stream);
            } finally {
                fclose($stream);
            }
        } finally {
            stream_wrapper_unregister('zerowrite');
        }
    }

    private function diamond(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Root'));
        $graph->addNode(new Node('B', 'Left'));
        $graph->addNode(new Node('C', 'Right'));
        $graph->addNode(new Node('D', 'Sink'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('B', 'D'));
        $graph->addEdge(new Edge('C', 'D'));

        return $graph;
    }
}
