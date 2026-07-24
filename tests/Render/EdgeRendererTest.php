<?php

declare(strict_types=1);

namespace PhpDag\Tests\Render;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Layout\BrandesKopfPositioning;
use PhpDag\Layout\ChainAwareRouting;
use PhpDag\Layout\DummyNodeInserter;
use PhpDag\Layout\DummyNodeRemover;
use PhpDag\Layout\EdgePort;
use PhpDag\Layout\FlowDirection;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LeftToRightPositioning;
use PhpDag\Layout\LeftToRightRouting;
use PhpDag\Layout\LongestPathLayering;
use PhpDag\Render\BoxRenderer;
use PhpDag\Render\Canvas;
use PhpDag\Render\EdgeRenderer;
use PhpDag\Render\EdgeRoute;
use PhpDag\Render\ElementRenderer;
use PhpDag\Render\PlainTextFormatter;
use PhpDag\Render\Waypoint;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class EdgeRendererTest extends TestCase
{
    #[Test]
    public function renderRoutesDrawsColoredRouteBodies(): void
    {
        $canvas = new Canvas();
        $route = new EdgeRoute(
            waypoints: [new Waypoint(0, 0), new Waypoint(4, 0)],
            edgeId: 1,
            targetArrow: false,
            color: AnsiColor::Red,
        );

        $method = new ReflectionMethod(EdgeRenderer::class, 'renderRoutes');
        $method->invoke(new EdgeRenderer(), $canvas, [$route]);

        self::assertSame(implode("\n", array_fill(0, 5, '│')), (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function renderRoutesDrawsEveryColoredRouteNotJustTheFirst(): void
    {
        $canvas = new Canvas();
        $left = new EdgeRoute(waypoints: [new Waypoint(0, 0), new Waypoint(4, 0)], edgeId: 1, targetArrow: false, color: AnsiColor::Red);
        $right = new EdgeRoute(waypoints: [new Waypoint(0, 4), new Waypoint(4, 4)], edgeId: 2, targetArrow: false, color: AnsiColor::Green);

        $method = new ReflectionMethod(EdgeRenderer::class, 'renderRoutes');
        $method->invoke(new EdgeRenderer(), $canvas, [$left, $right]);

        self::assertSame(implode("\n", array_fill(0, 5, '│   │')), (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function mergeIntervalsSortsThenCoalescesTouchingIntervals(): void
    {
        $method = new ReflectionMethod(EdgeRenderer::class, 'mergeIntervals');

        $merged = $method->invoke(new EdgeRenderer(), [[10, 11], [1, 3], [7, 8], [4, 5]]);

        self::assertSame([[1, 5], [7, 8], [10, 11]], $merged);
    }

    #[Test]
    public function mergeIntervalsOrdersByStartSoAnEnclosingIntervalIsNotTruncated(): void
    {
        $method = new ReflectionMethod(EdgeRenderer::class, 'mergeIntervals');

        // The wide interval must sort first so the merge keeps its low start. Comparing the
        // wrong endpoints would reorder these and shrink the result to [[2, 10]] / [[5, 10]].
        self::assertSame([[1, 10]], $method->invoke(new EdgeRenderer(), [[1, 10], [2, 3]]));
        self::assertSame([[1, 10]], $method->invoke(new EdgeRenderer(), [[5, 6], [1, 10]]));
    }

    #[Test]
    public function implementsElementRenderer(): void
    {
        self::assertInstanceOf(ElementRenderer::class, new EdgeRenderer());
    }

    #[Test]
    public function rendersStraightVerticalEdge(): void
    {
        $canvas = new Canvas();
        $route = new EdgeRoute(
            waypoints: [new Waypoint(0, 2), new Waypoint(4, 2)],
            edgeId: 1,
            targetArrow: false,
        );
        $renderer = new EdgeRenderer();

        $renderer->renderRoute($canvas, $route);

        $expected = <<<'EXPECTED'
          │
          │
          │
          │
          │
        EXPECTED;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersAsciiArrowWhenUnicodeGlyphsDisabled(): void
    {
        $canvas = new Canvas(unicodeGlyphs: false);
        $route = new EdgeRoute(
            waypoints: [new Waypoint(0, 0), new Waypoint(2, 0)],
            edgeId: 1,
        );
        $renderer = new EdgeRenderer(unicodeGlyphs: false);

        $renderer->renderRoute($canvas, $route);

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
        $canvas = new Canvas();
        $route = new EdgeRoute(
            waypoints: [new Waypoint(0, 2), new Waypoint(4, 2)],
            edgeId: 1,
        );
        $renderer = new EdgeRenderer();

        $renderer->renderRoute($canvas, $route);

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
    public function rendersVerticalEdgeWithSourceArrow(): void
    {
        $canvas = new Canvas();
        $route = new EdgeRoute(
            waypoints: [new Waypoint(0, 2), new Waypoint(4, 2)],
            edgeId: 1,
            sourceArrow: true,
            targetArrow: false,
        );
        $renderer = new EdgeRenderer();

        $renderer->renderRoute($canvas, $route);

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
        $canvas = new Canvas();
        $route = new EdgeRoute(
            waypoints: [new Waypoint(0, 0), new Waypoint(0, 4)],
            edgeId: 1,
            targetArrow: false,
        );
        $renderer = new EdgeRenderer();

        $renderer->renderRoute($canvas, $route);

        self::assertSame('─────', (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersLShapedEdgeWithCorner(): void
    {
        $canvas = new Canvas();
        $route = new EdgeRoute(
            waypoints: [new Waypoint(0, 0), new Waypoint(0, 4), new Waypoint(3, 4)],
            edgeId: 1,
        );
        $renderer = new EdgeRenderer();

        $renderer->renderRoute($canvas, $route);

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
        $canvas = new Canvas();
        $route = new EdgeRoute(
            waypoints: [
                new Waypoint(0, 0),
                new Waypoint(2, 0),
                new Waypoint(2, 4),
                new Waypoint(4, 4),
            ],
            edgeId: 1,
        );
        $renderer = new EdgeRenderer();

        $renderer->renderRoute($canvas, $route);

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
        $canvas = new Canvas();
        $route = new EdgeRoute(
            waypoints: [new Waypoint(3, 0), new Waypoint(0, 0)],
            edgeId: 1,
            targetArrow: false,
        );
        $renderer = new EdgeRenderer();

        $renderer->renderRoute($canvas, $route);

        $expected = <<<'EXPECTED'
        │
        │
        │
        │
        EXPECTED;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersLeftwardEdge(): void
    {
        $canvas = new Canvas();
        $route = new EdgeRoute(
            waypoints: [new Waypoint(0, 3), new Waypoint(0, 0)],
            edgeId: 1,
            targetArrow: false,
        );
        $renderer = new EdgeRenderer();

        $renderer->renderRoute($canvas, $route);

        self::assertSame('────', (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersShortHorizontalSegment(): void
    {
        $canvas = new Canvas();
        $route = new EdgeRoute(
            waypoints: [new Waypoint(0, 0), new Waypoint(0, 2)],
            edgeId: 1,
            targetArrow: false,
        );
        $renderer = new EdgeRenderer();

        $renderer->renderRoute($canvas, $route);

        self::assertSame('───', (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersDashedEdge(): void
    {
        $canvas = new Canvas();
        $route = new EdgeRoute(
            waypoints: [new Waypoint(0, 0), new Waypoint(3, 0)],
            edgeId: 1,
            strokeStyle: EdgeStrokeStyle::Dashed,
            targetArrow: false,
        );
        $renderer = new EdgeRenderer();

        $renderer->renderRoute($canvas, $route);

        $expected = <<<'EXPECTED'
        ╎
        ╎
        ╎
        ╎
        EXPECTED;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersHeavyEdge(): void
    {
        $canvas = new Canvas();
        $route = new EdgeRoute(
            waypoints: [new Waypoint(0, 0), new Waypoint(0, 3)],
            edgeId: 1,
            strokeStyle: EdgeStrokeStyle::Heavy,
            targetArrow: false,
        );
        $renderer = new EdgeRenderer();

        $renderer->renderRoute($canvas, $route);

        self::assertSame('━━━━', (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function twoEdgesCrossingProduceCrossingCharacter(): void
    {
        $canvas = new Canvas();
        $horizontalRoute = new EdgeRoute(
            waypoints: [new Waypoint(2, 0), new Waypoint(2, 4)],
            edgeId: 1,
            targetArrow: false,
        );
        $verticalRoute = new EdgeRoute(
            waypoints: [new Waypoint(0, 2), new Waypoint(4, 2)],
            edgeId: 2,
            targetArrow: false,
        );
        $renderer = new EdgeRenderer();

        $renderer->renderRoute($canvas, $horizontalRoute);
        $renderer->renderRoute($canvas, $verticalRoute);

        self::assertSame('┼', $canvas->get(2, 2)->resolvedCharacter());
    }

    #[Test]
    public function rendersBoxWithVerticalEdge(): void
    {
        $canvas = new Canvas();
        $boxRenderer = new BoxRenderer();
        $edgeRenderer = new EdgeRenderer();

        $nodeA = new Node('A', 'Start');
        $nodeB = new Node('B', 'End');

        $boxRenderer->render($canvas, $this->buildLayoutGraph($nodeA, row: 0, column: 0));

        $centerColumn = intdiv($nodeA->boxWidth(), 2);
        $edgeStartRow = $nodeA->boxHeight();
        $edgeEndRow = $edgeStartRow + 2;

        $edgeRenderer->renderRoute($canvas, new EdgeRoute(
            waypoints: [
                new Waypoint($edgeStartRow, $centerColumn),
                new Waypoint($edgeEndRow, $centerColumn),
            ],
            edgeId: 1,
        ));

        $boxRenderer->render($canvas, $this->buildLayoutGraph($nodeB, row: $edgeEndRow + 1, column: 0));

        $expected = <<<'EXPECTED'
        ╭───────╮
        │ Start │
        ╰───────╯
            │
            │
            ▼
        ╭─────╮
        │ End │
        ╰─────╯
        EXPECTED;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
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
