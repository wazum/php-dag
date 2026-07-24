<?php

declare(strict_types=1);

namespace PhpDag\Tests\Render;

use PhpDag\Graph\Graph;
use PhpDag\Graph\Group;
use PhpDag\Graph\Node;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Render\BoxRenderer;
use PhpDag\Render\Canvas;
use PhpDag\Render\EdgeRenderer;
use PhpDag\Render\EdgeRoute;
use PhpDag\Render\ElementRenderer;
use PhpDag\Render\GroupRenderer;
use PhpDag\Render\PlainTextFormatter;
use PhpDag\Render\Waypoint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GroupRendererTest extends TestCase
{
    #[Test]
    public function implementsElementRenderer(): void
    {
        self::assertInstanceOf(ElementRenderer::class, new GroupRenderer());
    }

    #[Test]
    public function drawsADoubleLineUnicodeBorderAroundASingleMemberAwayFromTheOrigin(): void
    {
        $canvas = $this->canvasWithGroup(memberTitle: 'Member', label: 'Lbl', row: 3, column: 4);

        (new GroupRenderer())->render($canvas, $this->lastLayoutGraph);

        $expected = <<<'EXPECTED'
          ╔═ Lbl ══════╗
          ║            ║
          ║ ╭────────╮ ║
          ║ │ Member │ ║
          ║ ╰────────╯ ║
          ║            ║
          ╚════════════╝
        EXPECTED;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function truncatesAnOverlongLabelToTheAvailableBorderWidth(): void
    {
        // Box width 6 (title 'AB') leaves an available label width of 6, so the
        // 18-wide label is cut to five glyphs plus an ellipsis. Any change to the
        // available-width arithmetic shifts where the ellipsis lands.
        $canvas = $this->canvasWithGroup(memberTitle: 'AB', label: 'VeryLongGroupLabel', row: 3, column: 4);

        (new GroupRenderer())->render($canvas, $this->lastLayoutGraph);

        $topBorderLine = explode("\n", (new PlainTextFormatter())->format($canvas))[0];

        self::assertSame('  ╔═ VeryL…', $topBorderLine);
    }

    #[Test]
    public function substitutesACrossJunctionWhereAHorizontalEdgePassesThroughAVerticalBorder(): void
    {
        $canvas = $this->canvasWithGroup(memberTitle: 'Member', label: 'Lbl', row: 3, column: 4);
        // The left border line lands on column 2. Route a horizontal edge across
        // it at row 4 so the renderer must replace the plain ║ with a ╫ junction.
        (new EdgeRenderer())->renderRoute(
            $canvas,
            new EdgeRoute(waypoints: [new Waypoint(4, 0), new Waypoint(4, 3)], edgeId: 1, targetArrow: false),
        );

        (new GroupRenderer())->render($canvas, $this->lastLayoutGraph);

        $crossingLine = explode("\n", (new PlainTextFormatter())->format($canvas))[3];

        self::assertSame('──╫─│ Member │ ║', $crossingLine);
    }

    #[Test]
    public function shiftsTheLabelRightToClearATopBorderCrossing(): void
    {
        $canvas = $this->canvasWithGroup(memberTitle: 'Member', label: 'Lbl', row: 3, column: 4);
        // Drop a vertical edge through the top border at column 5, where the
        // left-aligned label would otherwise sit; the label must slide right of
        // the crossing.
        (new EdgeRenderer())->renderRoute(
            $canvas,
            new EdgeRoute(waypoints: [new Waypoint(0, 5), new Waypoint(2, 5)], edgeId: 1, targetArrow: false),
        );

        (new GroupRenderer())->render($canvas, $this->lastLayoutGraph);

        $topLine = explode("\n", (new PlainTextFormatter())->format($canvas))[1];

        self::assertSame('  ╔══╪ Lbl ════╗', $topLine);
    }

    #[Test]
    public function restoresACrossingTheLabelHadToCoverWhenNoClearSlotFits(): void
    {
        // The member box is too narrow for the label to dodge the centre
        // crossing, so the label falls back onto it; the crossing junction must
        // be redrawn on top so the edge still reads as passing through.
        $canvas = $this->canvasWithGroup(memberTitle: 'X', label: 'WIDER', row: 3, column: 4);
        (new EdgeRenderer())->renderRoute(
            $canvas,
            new EdgeRoute(waypoints: [new Waypoint(0, 6), new Waypoint(2, 6)], edgeId: 1, targetArrow: false),
        );

        (new GroupRenderer())->render($canvas, $this->lastLayoutGraph);

        $topLine = explode("\n", (new PlainTextFormatter())->format($canvas))[1];

        self::assertSame('  ╔═ W╪DER', $topLine);
    }

    #[Test]
    public function placesTheLabelClearOfEveryTopBorderCrossingNotJustTheFirst(): void
    {
        // Two edges drop through the top border (columns 5 and 9). The label must
        // clear both crossings, not just the first one detected.
        $canvas = $this->canvasWithGroup(memberTitle: 'Member', label: 'AB', row: 3, column: 4);
        foreach ([5, 9] as $column) {
            (new EdgeRenderer())->renderRoute(
                $canvas,
                new EdgeRoute(waypoints: [new Waypoint(0, $column), new Waypoint(2, $column)], edgeId: $column, targetArrow: false),
            );
        }

        (new GroupRenderer())->render($canvas, $this->lastLayoutGraph);

        $topLine = explode("\n", (new PlainTextFormatter())->format($canvas))[1];

        self::assertSame('  ╔══╪═══╪ AB ═╗', $topLine);
    }

    private LayoutGraph $lastLayoutGraph;

    private function canvasWithGroup(string $memberTitle, string $label, int $row, int $column): Canvas
    {
        $graph = new Graph();
        $graph->addNode(new Node('member', $memberTitle));
        $graph->addGroup(new Group('cluster', $label, ['member']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->getLayoutNode('member')->row = $row;
        $layoutGraph->getLayoutNode('member')->column = $column;
        $this->lastLayoutGraph = $layoutGraph;

        $canvas = new Canvas();
        (new BoxRenderer())->render($canvas, $layoutGraph);

        return $canvas;
    }
}
