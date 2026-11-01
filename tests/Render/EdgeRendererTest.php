<?php

declare(strict_types=1);

namespace PhpDag\Tests\Render;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\BrandesKopfPositioning;
use PhpDag\Layout\ChainAwareRouting;
use PhpDag\Layout\DummyLayoutNode;
use PhpDag\Layout\DummyNodeInserter;
use PhpDag\Layout\DummyNodeRemover;
use PhpDag\Layout\EdgePort;
use PhpDag\Layout\FlowDirection;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LeftToRightPositioning;
use PhpDag\Layout\LeftToRightRouting;
use PhpDag\Layout\LongestPathLayering;
use PhpDag\Render\BoxRenderer;
use PhpDag\Render\Canvas;
use PhpDag\Render\EdgeRenderer;
use PhpDag\Render\ElementRenderer;
use PhpDag\Render\PlainTextFormatter;
use PhpDag\Render\Waypoint;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EdgeRendererTest extends TestCase
{
    #[Test]
    public function drawsAColouredEdge(): void
    {
        $canvas = $this->draw([$this->edge('a', [new Waypoint(0, 0), new Waypoint(4, 0)], color: AnsiColor::Red)]);

        self::assertSame("│\n│\n│\n│\n▼", (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function drawsEveryColouredEdgeNotJustTheFirst(): void
    {
        $canvas = $this->draw([
            $this->edge('a', [new Waypoint(0, 0), new Waypoint(4, 0)], color: AnsiColor::Red),
            $this->edge('b', [new Waypoint(0, 4), new Waypoint(4, 4)], color: AnsiColor::Green),
        ]);

        self::assertSame("│   │\n│   │\n│   │\n│   │\n▼   ▼", (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function drawsTheCornersOfAColouredZShapedEdge(): void
    {
        $canvas = $this->draw([$this->edge('a', [new Waypoint(0, 0), new Waypoint(2, 0), new Waypoint(2, 4), new Waypoint(4, 4)], color: AnsiColor::Red)]);

        self::assertSame("│\n│\n└───┐\n    │\n    ▼", (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function drawsTheOneCellRunsOfAShortColouredEdge(): void
    {
        $canvas = $this->draw([$this->edge('a', [new Waypoint(0, 0), new Waypoint(0, 2), new Waypoint(2, 2)], color: AnsiColor::Red)]);

        self::assertSame("──┐\n  │\n  ▼", (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function keepsTheWholeLongerEdgeWhereTwoEdgesShareAColumn(): void
    {
        $long = [new Waypoint(0, 0), new Waypoint(6, 0)];
        $short = [new Waypoint(2, 0), new Waypoint(3, 0)];
        $expected = "│\n│\n│\n▼\n│\n│\n▼";

        self::assertSame($expected, (new PlainTextFormatter())->format($this->draw([$this->edge('a', $long), $this->edge('b', $short)])));
        self::assertSame($expected, (new PlainTextFormatter())->format($this->draw([$this->edge('b', $short), $this->edge('a', $long)])));
    }

    #[Test]
    public function implementsElementRenderer(): void
    {
        self::assertInstanceOf(ElementRenderer::class, new EdgeRenderer());
    }

    #[Test]
    public function rendersAsciiArrowWhenUnicodeGlyphsDisabled(): void
    {
        $canvas = $this->draw([$this->edge('a', [new Waypoint(0, 0), new Waypoint(2, 0)])], unicodeGlyphs: false);

        $expected = <<<'EXPECTED'
        |
        |
        v
        EXPECTED;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersVerticalEdgeWithDownwardArrow(): void
    {
        $canvas = $this->draw([$this->edge('a', [new Waypoint(0, 2), new Waypoint(4, 2)])]);

        $expected = <<<'EXPECTED'
          │
          │
          │
          │
          ▼
        EXPECTED;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function reversedEdgeGetsItsArrowAtTheStart(): void
    {
        $canvas = $this->draw([$this->edge('a', [new Waypoint(0, 2), new Waypoint(4, 2)], reversed: true)]);

        $expected = <<<'EXPECTED'
          ▲
          │
          │
          │
          │
        EXPECTED;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersStraightHorizontalEdge(): void
    {
        $canvas = $this->draw([$this->edge('a', [new Waypoint(0, 0), new Waypoint(0, 4)])]);

        self::assertSame('────▶', (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersLShapedEdgeWithCorner(): void
    {
        $canvas = $this->draw([$this->edge('a', [new Waypoint(0, 0), new Waypoint(0, 4), new Waypoint(3, 4)])]);

        $expected = <<<'EXPECTED'
        ────┐
            │
            │
            ▼
        EXPECTED;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersZShapedEdge(): void
    {
        $canvas = $this->draw([$this->edge('a', [new Waypoint(0, 0), new Waypoint(2, 0), new Waypoint(2, 4), new Waypoint(4, 4)])]);

        $expected = <<<'EXPECTED'
        │
        │
        └───┐
            │
            ▼
        EXPECTED;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersUpwardEdge(): void
    {
        $canvas = $this->draw([$this->edge('a', [new Waypoint(3, 0), new Waypoint(0, 0)])]);

        self::assertSame("▲\n│\n│\n│", (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersLeftwardEdge(): void
    {
        $canvas = $this->draw([$this->edge('a', [new Waypoint(0, 3), new Waypoint(0, 0)])]);

        self::assertSame('◀───', (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersShortHorizontalSegment(): void
    {
        $canvas = $this->draw([$this->edge('a', [new Waypoint(0, 0), new Waypoint(0, 2)])]);

        self::assertSame('──▶', (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersDashedEdge(): void
    {
        $canvas = $this->draw([$this->edge('a', [new Waypoint(0, 0), new Waypoint(3, 0)], EdgeStrokeStyle::Dashed)]);

        self::assertSame("╎\n╎\n╎\n▼", (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersHeavyEdge(): void
    {
        $canvas = $this->draw([$this->edge('a', [new Waypoint(0, 0), new Waypoint(0, 3)], EdgeStrokeStyle::Heavy)]);

        self::assertSame('━━━▶', (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function twoEdgesCrossingProduceCrossingCharacter(): void
    {
        $canvas = $this->draw([
            $this->edge('a', [new Waypoint(2, 0), new Waypoint(2, 4)]),
            $this->edge('b', [new Waypoint(0, 2), new Waypoint(4, 2)]),
        ]);

        self::assertSame('┼', $canvas->get(2, 2)->resolvedCharacter());
    }

    #[Test]
    public function heavyEdgeRendersStyledExitConnection(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B', edgeStrokeStyle: EdgeStrokeStyle::Heavy));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new BrandesKopfPositioning())->position($layoutGraph);
        (new ChainAwareRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        $canvas = new Canvas();
        (new BoxRenderer())->render($canvas, $layoutGraph);
        (new EdgeRenderer())->render($canvas, $layoutGraph);

        $output = (new PlainTextFormatter())->format($canvas);

        self::assertStringContainsString('┳', $output, 'Heavy exit connection should use ┳ not ┬');
    }

    #[Test]
    public function heavyEdgeRendersStyledEntryConnection(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B', edgeStrokeStyle: EdgeStrokeStyle::Heavy));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new BrandesKopfPositioning())->position($layoutGraph);
        (new ChainAwareRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        $canvas = new Canvas();
        (new BoxRenderer())->render($canvas, $layoutGraph);
        (new EdgeRenderer())->render($canvas, $layoutGraph);

        $output = (new PlainTextFormatter())->format($canvas);

        self::assertStringContainsString('┻', $output, 'Heavy entry connection should use ┻ not ┴');
    }

    #[Test]
    public function styledConnectionWinsOverSolidWhenShared(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Hub'));
        $graph->addNode(new Node('B', 'Left'));
        $graph->addNode(new Node('C', 'Right'));
        $graph->addEdge(new Edge('A', 'B', edgeStrokeStyle: EdgeStrokeStyle::Double));
        $graph->addEdge(new Edge('A', 'C'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new BrandesKopfPositioning())->position($layoutGraph);
        (new ChainAwareRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        $canvas = new Canvas();
        (new BoxRenderer())->render($canvas, $layoutGraph);
        (new EdgeRenderer())->render($canvas, $layoutGraph);

        $output = (new PlainTextFormatter())->format($canvas);

        self::assertStringContainsString('╦', $output, 'Double exit connection must win over Solid at shared exit point');

        $lines = explode("\n", $output);
        $routingLine = $lines[4];
        self::assertStringContainsString('╩', $routingLine, 'Routing junction must use Double style (╩) not Solid (┴) when Double edge passes through');
    }

    #[Test]
    public function passesEdgeColorToCanvas(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addEdge(new Edge('A', 'B', color: AnsiColor::Cyan));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new BrandesKopfPositioning())->position($layoutGraph);
        (new ChainAwareRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        $canvas = new Canvas();
        (new EdgeRenderer())->render($canvas, $layoutGraph);

        $edge = $layoutGraph->edges()[0];
        $firstWaypoint = $edge->waypoints[0];
        self::assertSame(AnsiColor::Cyan, $canvas->get($firstWaypoint->row, $firstWaypoint->column)->resolvedColor());
    }

    #[Test]
    public function leftToRightExitConnectionUsesRightBorderGlyph(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new LeftToRightPositioning())->position($layoutGraph);
        (new LeftToRightRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        $canvas = new Canvas();
        (new BoxRenderer())->render($canvas, $layoutGraph);
        (new EdgeRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        $output = (new PlainTextFormatter())->format($canvas);

        self::assertStringContainsString('├', $output, 'LR exit connection should use ├ (UP|DOWN|RIGHT) on right border');
    }

    #[Test]
    public function leftToRightEntryConnectionUsesLeftBorderGlyph(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new LeftToRightPositioning())->position($layoutGraph);
        (new LeftToRightRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        $canvas = new Canvas();
        (new BoxRenderer())->render($canvas, $layoutGraph);
        (new EdgeRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        $output = (new PlainTextFormatter())->format($canvas);

        self::assertStringContainsString('┤', $output, 'LR entry connection should use ┤ (UP|DOWN|LEFT) on left border');
    }

    #[Test]
    public function leftToRightExitConnectionFillsGapForNarrowNodes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'Database'));
        $graph->addNode(new Node('C', 'DB'));
        $graph->addNode(new Node('D', 'End'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('B', 'D'));
        $graph->addEdge(new Edge('C', 'D'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new LeftToRightPositioning())->position($layoutGraph);
        (new LeftToRightRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        $canvas = new Canvas();
        (new BoxRenderer())->render($canvas, $layoutGraph);
        (new EdgeRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        $output = (new PlainTextFormatter())->format($canvas);

        self::assertDoesNotMatchRegularExpression('/├\s+/', $output,
            'Exit connection for narrow node must fill gap with horizontal line, not spaces');
    }

    #[Test]
    public function edgeWithoutWaypointsDoesNotAbortRenderingOfRemainingEdges(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addNode(new Node('D', 'D'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('C', 'D'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->getLayoutNode('C')->row = 0;
        $layoutGraph->getLayoutNode('D')->row = 6;
        $layoutGraph->edges()[1]->waypoints = [new Waypoint(3, 2), new Waypoint(5, 2)];

        $canvas = new Canvas();
        (new EdgeRenderer())->render($canvas, $layoutGraph);

        self::assertSame('│', $canvas->get(4, 2)->resolvedCharacter(), 'Route of the second edge must still be drawn');
        self::assertSame('┬', $canvas->get(2, 2)->resolvedCharacter(), 'Exit connection of the second edge must still be drawn');
        self::assertSame('▼', $canvas->get(5, 2)->resolvedCharacter(), 'Arrow of the second edge must still be drawn');
    }

    #[Test]
    public function reversedEdgeWithoutTargetPortDrawsArrowAtFirstWaypoint(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->getLayoutNode('B')->row = 0;
        $layoutGraph->getLayoutNode('A')->row = 6;
        $layoutEdge = $layoutGraph->edges()[0];
        $layoutEdge->reversed = true;
        $layoutEdge->waypoints = [new Waypoint(3, 2), new Waypoint(5, 2)];

        $canvas = new Canvas();
        (new EdgeRenderer())->render($canvas, $layoutGraph);

        self::assertSame('▲', $canvas->get(3, 2)->resolvedCharacter(), 'Reversed edge must point back to its semantic target');
        self::assertSame('│', $canvas->get(5, 2)->resolvedCharacter(), 'Reversed edge must not draw a forward arrow');
    }

    #[Test]
    public function northSourceConnectionGapDoesNotCorruptWaypointCorner(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->getLayoutNode('A')->row = 4;
        $layoutGraph->getLayoutNode('A')->column = 0;
        $layoutGraph->getLayoutNode('B')->row = 3;
        $layoutGraph->getLayoutNode('B')->column = 6;
        $layoutEdge = $layoutGraph->edges()[0];
        $layoutEdge->sourcePort = EdgePort::North;
        $layoutEdge->waypoints = [new Waypoint(1, 2), new Waypoint(1, 8)];

        $canvas = new Canvas();
        (new EdgeRenderer())->render($canvas, $layoutGraph);

        self::assertSame('┌', $canvas->get(1, 2)->resolvedCharacter(), 'Corner above the source node must keep its corner glyph');
    }

    #[Test]
    public function westSourceConnectionGapDoesNotCorruptWaypointCorner(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layoutGraph->getLayoutNode('A')->row = 0;
        $layoutGraph->getLayoutNode('A')->column = 6;
        $layoutGraph->getLayoutNode('B')->row = 7;
        $layoutGraph->getLayoutNode('B')->column = 0;
        $layoutEdge = $layoutGraph->edges()[0];
        $layoutEdge->sourcePort = EdgePort::West;
        $layoutEdge->waypoints = [new Waypoint(1, 2), new Waypoint(5, 2)];

        $canvas = new Canvas();
        (new EdgeRenderer())->render($canvas, $layoutGraph);

        self::assertSame('┌', $canvas->get(1, 2)->resolvedCharacter(), 'Corner left of the source node must keep its corner glyph');
    }

    #[Test]
    public function rendersDenseFanWithoutRepaintingSharedTrunksQuadratically(): void
    {
        if (extension_loaded('xdebug')) {
            self::markTestSkipped('Wall-clock timing is unreliable under Xdebug (coverage/mutation runs).');
        }

        $graph = new Graph();
        $graph->addNode(new Node('root', 'Root'));
        $graph->addNode(new Node('sink', 'Sink'));

        $fanSize = 750;
        for ($index = 0; $index < $fanSize; ++$index) {
            $nodeId = 'node-'.$index;
            $graph->addNode(new Node($nodeId, $nodeId));
            $graph->addEdge(new Edge('root', $nodeId));
            $graph->addEdge(new Edge($nodeId, 'sink'));
        }

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $rootColumn = intdiv($fanSize * 10, 2);
        $layoutGraph->getLayoutNode('root')->column = $rootColumn;
        $layoutGraph->getLayoutNode('sink')->column = $rootColumn;
        $layoutGraph->getLayoutNode('sink')->row = 12;

        foreach ($layoutGraph->edges() as $edgeIndex => $edge) {
            $nodeIndex = intdiv($edgeIndex, 2);
            $nodeColumn = $nodeIndex * 10;
            $nodeId = 'node-'.$nodeIndex;
            $layoutGraph->getLayoutNode($nodeId)->column = $nodeColumn;
            $layoutGraph->getLayoutNode($nodeId)->row = 6;

            $edge->waypoints = 0 === $edgeIndex % 2
                ? [new Waypoint(3, $rootColumn), new Waypoint(3, $nodeColumn), new Waypoint(5, $nodeColumn)]
                : [new Waypoint(9, $nodeColumn), new Waypoint(9, $rootColumn), new Waypoint(11, $rootColumn)];
        }

        $canvas = new Canvas();
        $start = hrtime(true);
        (new EdgeRenderer())->render($canvas, $layoutGraph);
        $elapsedMilliseconds = (hrtime(true) - $start) / 1_000_000;

        // A wide margin keeps this stable on shared CI runners while still
        // catching the former O(edges × trunk-width) repaint loop.
        self::assertLessThan(400.0, $elapsedMilliseconds, sprintf('Dense fan edge rendering took %.1f ms', $elapsedMilliseconds));
    }

    /** @param list<LayoutEdge> $edges */
    private function draw(array $edges, bool $unicodeGlyphs = true): Canvas
    {
        $graph = new LayoutGraph();
        foreach ($edges as $edge) {
            $graph->addNode(new DummyLayoutNode($edge->edge->sourceId, $edge->edge->sourceId, $edge->edge->targetId));
            $graph->addNode(new DummyLayoutNode($edge->edge->targetId, $edge->edge->sourceId, $edge->edge->targetId));
            $graph->addEdge($edge);
        }
        $canvas = new Canvas($unicodeGlyphs);
        (new EdgeRenderer(unicodeGlyphs: $unicodeGlyphs))->render($canvas, $graph);

        return $canvas;
    }

    /** @param list<Waypoint> $waypoints */
    private function edge(string $name, array $waypoints, EdgeStrokeStyle $strokeStyle = EdgeStrokeStyle::Solid, bool $reversed = false, ?AnsiColor $color = null): LayoutEdge
    {
        $edge = new LayoutEdge(new Edge($name.'-from', $name.'-to', $strokeStyle, color: $color), $reversed);
        $edge->waypoints = $waypoints;

        return $edge;
    }
}
