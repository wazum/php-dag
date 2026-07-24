<?php

declare(strict_types=1);

namespace PhpDag\Tests\Render;

use PhpDag\Graph\Badge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Graph\NodeStyle;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Render\BoxRenderer;
use PhpDag\Render\Canvas;
use PhpDag\Render\ElementRenderer;
use PhpDag\Render\PlainTextFormatter;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\BorderStyle;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BoxRendererTest extends TestCase
{
    #[Test]
    public function implementsElementRenderer(): void
    {
        self::assertInstanceOf(ElementRenderer::class, new BoxRenderer());
    }

    #[Test]
    public function rendersSingleLineBorderedBox(): void
    {
        $canvas = new Canvas();
        $node = new Node('A', 'Test');

        (new BoxRenderer())->render($canvas, $this->buildLayoutGraph($node));

        $expected = <<<'TEXT'
        ╭──────╮
        │ Test │
        ╰──────╯
        TEXT;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersAGrownBoxTallerWithVerticallyCentredContent(): void
    {
        $canvas = new Canvas();
        $node = new Node('A', 'Test');
        $layoutGraph = $this->buildLayoutGraph($node);
        $layoutGraph->getLayoutNode('A')->minBoxHeight = 5;

        (new BoxRenderer())->render($canvas, $layoutGraph);

        $expected = <<<'TEXT'
        ╭──────╮
        │      │
        │ Test │
        │      │
        ╰──────╯
        TEXT;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersAsciiBorderWhenUnicodeGlyphsDisabled(): void
    {
        $canvas = new Canvas(unicodeGlyphs: false);
        $node = new Node('A', 'Test');

        (new BoxRenderer(unicodeGlyphs: false))->render($canvas, $this->buildLayoutGraph($node));

        $expected = <<<'TEXT'
        +------+
        | Test |
        +------+
        TEXT;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function boxInteriorOccludesEdgeSegmentsPassingThrough(): void
    {
        $canvas = new Canvas();
        $canvas->verticalLine(column: 1, startRow: 0, endRow: 2, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        (new BoxRenderer())->render($canvas, $this->buildLayoutGraph(new Node('A', 'Test')));

        self::assertSame(' ', $canvas->get(1, 1)->resolvedCharacter(), 'Box padding cell must occlude the edge underneath (boxes are z=10, edges z=5)');

        $expected = <<<'TEXT'
        ╭──────╮
        │ Test │
        ╰──────╯
        TEXT;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersTitleAndBodyBorderedBox(): void
    {
        $canvas = new Canvas();
        $node = new Node('B', 'Title Here', ['body line one', 'body line two']);

        (new BoxRenderer())->render($canvas, $this->buildLayoutGraph($node));

        $expected = <<<'TEXT'
        ╭───────────────╮
        │  Title Here   │
        │ body line one │
        │ body line two │
        ╰───────────────╯
        TEXT;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersSeparatorLineBetweenTitleAndBody(): void
    {
        $canvas = new Canvas();
        $node = new Node('C', 'Title Here', ['body line one'], new NodeStyle(titleBodySeparator: true));

        (new BoxRenderer())->render($canvas, $this->buildLayoutGraph($node));

        $expected = <<<'TEXT'
        ╭───────────────╮
        │  Title Here   │
        │               │
        │ body line one │
        ╰───────────────╯
        TEXT;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function centerPadsShortTitleWhenBodyIsWider(): void
    {
        $canvas = new Canvas();
        $node = new Node('D', 'Title', ['longer body']);

        (new BoxRenderer())->render($canvas, $this->buildLayoutGraph($node));

        $expected = <<<'TEXT'
        ╭─────────────╮
        │    Title    │
        │ longer body │
        ╰─────────────╯
        TEXT;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersBadgeOnTopBorderRow(): void
    {
        $canvas = new Canvas();
        $node = new Node('E', 'Client', style: new NodeStyle(badge: new Badge('★')));

        (new BoxRenderer())->render($canvas, $this->buildLayoutGraph($node));

        $expected = <<<'TEXT'
        ╭───────★╮
        │ Client │
        ╰────────╯
        TEXT;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersBorderlessNode(): void
    {
        $canvas = new Canvas();
        $node = new Node('F', 'Hello', style: new NodeStyle(borderStyle: BorderStyle::None));

        (new BoxRenderer())->render($canvas, $this->buildLayoutGraph($node));

        self::assertSame(' Hello', (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersBorderlessNodeWithBadge(): void
    {
        $canvas = new Canvas();
        $node = new Node(
            'G',
            'Hello',
            style: new NodeStyle(
                borderStyle: BorderStyle::None,
                badge: new Badge('★'),
            ),
        );

        (new BoxRenderer())->render($canvas, $this->buildLayoutGraph($node));

        self::assertSame(' Hello (★)', (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersAtNonZeroOffset(): void
    {
        $canvas = new Canvas();
        $node = new Node('H', 'Hi');

        (new BoxRenderer())->render($canvas, $this->buildLayoutGraph($node, row: 5, column: 10));

        self::assertSame('╭', $canvas->get(row: 5, column: 10)->resolvedCharacter());
        self::assertSame('╮', $canvas->get(row: 5, column: 15)->resolvedCharacter());
        self::assertSame('H', $canvas->get(row: 6, column: 12)->resolvedCharacter());
        self::assertSame('i', $canvas->get(row: 6, column: 13)->resolvedCharacter());
        self::assertSame('╰', $canvas->get(row: 7, column: 10)->resolvedCharacter());
    }

    /** @return iterable<string, array{BorderStyle, string}> */
    public static function borderStyleProvider(): iterable
    {
        yield 'Rounded' => [BorderStyle::Rounded, "╭──────╮\n│ Test │\n╰──────╯"];
        yield 'Solid' => [BorderStyle::Solid, "┌──────┐\n│ Test │\n└──────┘"];
        yield 'Double' => [BorderStyle::Double, "╔══════╗\n║ Test ║\n╚══════╝"];
        yield 'Dashed' => [BorderStyle::Dashed, "┌╌╌╌╌╌╌┐\n╎ Test ╎\n└╌╌╌╌╌╌┘"];
        yield 'Dotted' => [BorderStyle::Dotted, "┌┈┈┈┈┈┈┐\n┊ Test ┊\n└┈┈┈┈┈┈┘"];
        yield 'None' => [BorderStyle::None, ' Test'];
    }

    #[Test]
    #[DataProvider('borderStyleProvider')]
    public function rendersAllBorderStyles(BorderStyle $borderStyle, string $expected): void
    {
        $canvas = new Canvas();
        $node = new Node('Z', 'Test', style: new NodeStyle(borderStyle: $borderStyle));

        (new BoxRenderer())->render($canvas, $this->buildLayoutGraph($node));

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersMultibyteContentWithCorrectPadding(): void
    {
        $canvas = new Canvas();
        $node = new Node('M', '日本語', ['longer body']);

        (new BoxRenderer())->render($canvas, $this->buildLayoutGraph($node));

        $expected = <<<'TEXT'
        ╭─────────────╮
        │   日本語    │
        │ longer body │
        ╰─────────────╯
        TEXT;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function passesNodeColorToCanvas(): void
    {
        $node = new Node('A', 'Hi', color: AnsiColor::Red);
        $canvas = new Canvas();

        (new BoxRenderer())->render($canvas, $this->buildLayoutGraph($node));

        self::assertSame(AnsiColor::Red, $canvas->get(0, 0)->resolvedColor());
    }

    private function buildLayoutGraph(Node $node, int $row = 0, int $column = 0): LayoutGraph
    {
        $graph = new Graph();
        $graph->addNode($node);
        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->getLayoutNode($node->id)->row = $row;
        $layoutGraph->getLayoutNode($node->id)->column = $column;

        return $layoutGraph;
    }
}
