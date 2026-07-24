<?php

declare(strict_types=1);

namespace PhpDag\Tests\Render;

use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Render\BoxRenderer;
use PhpDag\Render\Canvas;
use PhpDag\Render\Direction;
use PhpDag\Render\ElementRenderer;
use PhpDag\Render\PlainTextFormatter;
use PhpDag\Render\Renderer;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    #[Test]
    public function rendersLayoutGraphToString(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Test'));
        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->getLayoutNode('A')->row = 0;
        $layoutGraph->getLayoutNode('A')->column = 0;

        $renderer = new Renderer(
            [new BoxRenderer()],
            new PlainTextFormatter(),
        );

        $result = $renderer->render($layoutGraph);

        self::assertStringContainsString('Test', $result);
    }

    #[Test]
    public function rendersFullBorderedBox(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Hello'));
        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->getLayoutNode('A')->row = 0;
        $layoutGraph->getLayoutNode('A')->column = 0;

        $renderer = new Renderer(
            [new BoxRenderer()],
            new PlainTextFormatter(),
        );

        $expected = <<<'TEXT'
        ╭───────╮
        │ Hello │
        ╰───────╯
        TEXT;

        self::assertSame($expected, $renderer->render($layoutGraph));
    }

    #[Test]
    public function defaultRendererResolvesEdgeJunctionsWithUnicodeGlyphs(): void
    {
        $edgeMarkingRenderer = new class implements ElementRenderer {
            public function render(Canvas $canvas, LayoutGraph $graph): void
            {
                $canvas->markEdgePassthrough(0, 0, Direction::UP | Direction::DOWN, 1, EdgeStrokeStyle::Solid, 5);
            }
        };

        $renderer = new Renderer([$edgeMarkingRenderer], new PlainTextFormatter());

        self::assertSame('│', $renderer->render(LayoutGraph::fromGraph(new Graph())));
    }
}
