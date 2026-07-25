<?php

declare(strict_types=1);

namespace PhpDag\Tests\Render;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Label;
use PhpDag\Graph\LabelPosition;
use PhpDag\Graph\Node;
use PhpDag\Layout\BrandesKopfPositioning;
use PhpDag\Layout\ChainAwareRouting;
use PhpDag\Layout\DummyLayoutNode;
use PhpDag\Layout\DummyNodeInserter;
use PhpDag\Layout\DummyNodeRemover;
use PhpDag\Layout\FlowDirection;
use PhpDag\Layout\LabelReserver;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LeftToRightLabelReserver;
use PhpDag\Layout\LeftToRightPositioning;
use PhpDag\Layout\LeftToRightRouting;
use PhpDag\Layout\LongestPathLayering;
use PhpDag\Layout\RealLayoutNode;
use PhpDag\Render\BoxRenderer;
use PhpDag\Render\Canvas;
use PhpDag\Render\EdgeRenderer;
use PhpDag\Render\ElementRenderer;
use PhpDag\Render\LabelRenderer;
use PhpDag\Render\PlainTextFormatter;
use PhpDag\Render\Waypoint;
use PhpDag\Style\AnsiColor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LabelRendererTest extends TestCase
{
    #[Test]
    public function implementsElementRenderer(): void
    {
        self::assertInstanceOf(ElementRenderer::class, new LabelRenderer());
    }

    #[Test]
    public function rendersLabelTextToTheRightOfVerticalEdge(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->buildFullLayout(
            nodes: [new Node('A', 'A'), new Node('B', 'B')],
            edges: [new Edge('A', 'B', label: new Label('yes'))],
        );

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        $output = (new PlainTextFormatter())->format($canvas);
        self::assertStringContainsString('yes', $output);
    }

    #[Test]
    public function placesLabelAtMidpointRowAndTwoColumnsRightOfEdge(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->buildFullLayout(
            nodes: [new Node('A', 'A'), new Node('B', 'B')],
            edges: [new Edge('A', 'B', label: new Label('yes'))],
        );

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        $edge = $layoutGraph->edges()[0];
        $firstRow = $edge->waypoints[0]->row;
        $lastRow = $edge->waypoints[count($edge->waypoints) - 1]->row;
        $expectedRow = intdiv($firstRow + $lastRow, 2);
        $expectedColumn = $edge->waypoints[0]->column + 2;

        self::assertSame('y', $canvas->get($expectedRow, $expectedColumn)->resolvedCharacter(), 'Label first char at edgeColumn + 2');
        self::assertSame('e', $canvas->get($expectedRow, $expectedColumn + 1)->resolvedCharacter());
        self::assertSame('s', $canvas->get($expectedRow, $expectedColumn + 2)->resolvedCharacter());
    }

    #[Test]
    public function placesSourcePositionedLabelAtFirstRowPlusOne(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->buildFullLayout(
            nodes: [new Node('A', 'A'), new Node('B', 'B')],
            edges: [new Edge('A', 'B', label: new Label('yes', LabelPosition::Source))],
        );

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        $edge = $layoutGraph->edges()[0];
        $expectedRow = $edge->waypoints[0]->row + 1;
        $expectedColumn = $edge->waypoints[0]->column + 2;

        self::assertSame('y', $canvas->get($expectedRow, $expectedColumn)->resolvedCharacter(), 'Source label at firstRow + 1');
    }

    #[Test]
    public function placesTargetPositionedLabelAtLastRowMinusOne(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->buildFullLayout(
            nodes: [new Node('A', 'A'), new Node('B', 'B'), new Node('C', 'C')],
            edges: [
                new Edge('A', 'B'),
                new Edge('B', 'C'),
                new Edge('A', 'C', label: new Label('tgt', LabelPosition::Target)),
            ],
        );

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        $labeledEdge = null;
        foreach ($layoutGraph->edges() as $edge) {
            if (null !== $edge->edge->label) {
                $labeledEdge = $edge;
            }
        }

        self::assertNotNull($labeledEdge, 'Labeled edge must exist');
        $lastRow = $labeledEdge->waypoints[count($labeledEdge->waypoints) - 1]->row;

        $labelRowContent = '';
        for ($column = 0; $column < 30; ++$column) {
            $labelRowContent .= $canvas->get($lastRow - 1, $column)->resolvedCharacter();
        }

        self::assertStringContainsString('tgt', $labelRowContent, 'Target label must render on lastRow - 1, beside the entry channel');
    }

    #[Test]
    public function movesToTheNextFreeRowWhenTheAnchorSlotIsOccupied(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->buildFullLayout(
            nodes: [new Node('A', 'A'), new Node('B', 'B')],
            edges: [new Edge('A', 'B', label: new Label('yes'))],
        );

        (new BoxRenderer())->render($canvas, $layoutGraph);
        (new EdgeRenderer())->render($canvas, $layoutGraph);

        $edge = $layoutGraph->edges()[0];
        $firstRow = $edge->waypoints[0]->row;
        $lastRow = $edge->waypoints[count($edge->waypoints) - 1]->row;
        $labelRow = intdiv($firstRow + $lastRow, 2);
        $edgeColumn = $edge->waypoints[0]->column;
        $rightColumn = $edgeColumn + 2;

        $canvas->text($labelRow, $rightColumn, 'XXX', 10);

        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('X', $canvas->get($labelRow, $rightColumn)->resolvedCharacter(), 'The occupied slot must not be overwritten');
        self::assertSame('y', $canvas->get($labelRow + 1, $rightColumn)->resolvedCharacter(), 'Label must move to the next free row beside the edge');
    }

    #[Test]
    public function placesLabelOnOuterSideOfLeftGoingBranch(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->buildFullLayout(
            nodes: [
                new Node('src', 'Review'),
                new Node('left', 'Approve'),
                new Node('right', 'Reject'),
            ],
            edges: [
                new Edge('src', 'left', label: new Label('yes')),
                new Edge('src', 'right', label: new Label('no')),
            ],
        );

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        $leftLabeledEdge = null;
        foreach ($layoutGraph->edges() as $edge) {
            if (null !== $edge->edge->label && 'yes' === $edge->edge->label->text) {
                $leftLabeledEdge = $edge;
            }
        }
        self::assertNotNull($leftLabeledEdge);

        $firstRow = $leftLabeledEdge->waypoints[0]->row;
        $lastRow = $leftLabeledEdge->waypoints[count($leftLabeledEdge->waypoints) - 1]->row;
        $labelRow = intdiv($firstRow + $lastRow, 2);

        $leftNode = $layoutGraph->getLayoutNode('left');
        $verticalColumn = $leftNode->column + intdiv($leftNode->boxWidth(), 2);

        $expectedLeftColumn = $verticalColumn - 3 - 1;
        self::assertSame('y', $canvas->get($labelRow, $expectedLeftColumn)->resolvedCharacter(),
            sprintf(
                'Left-going branch label "yes" should be on the LEFT side of the edge (col %d), not the right',
                $expectedLeftColumn,
            ),
        );
    }

    #[Test]
    public function shiftsLayoutRightToFitOuterLabelOnLeftBranch(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->buildFullLayout(
            nodes: [
                new Node('hub', 'Push'),
                new Node('left', 'Lint'),
                new Node('right', 'Unit Tests'),
            ],
            edges: [
                new Edge('hub', 'left', label: new Label('check', LabelPosition::Source)),
                new Edge('hub', 'right'),
            ],
        );

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        $labeledEdge = null;
        foreach ($layoutGraph->edges() as $edge) {
            if (null !== $edge->edge->label) {
                $labeledEdge = $edge;
            }
        }
        self::assertNotNull($labeledEdge);

        $leftNode = $layoutGraph->getLayoutNode('left');
        $verticalColumn = $leftNode->column + intdiv($leftNode->boxWidth(), 2);

        // The source anchor row sits right above the target box, so the label
        // moves one row up onto the bend row, beside the bar's corner.
        $labelRow = $labeledEdge->waypoints[0]->row;

        $labelWidth = 5;
        $expectedLeftColumn = $verticalColumn - $labelWidth - 1;

        self::assertGreaterThanOrEqual(0, $expectedLeftColumn,
            'Layout must shift right so that the outer-side label fits at a non-negative column',
        );
        self::assertSame('c', $canvas->get($labelRow, $expectedLeftColumn)->resolvedCharacter(),
            sprintf(
                'Label "check" should be on the LEFT (outer) side at column %d (edge at %d)',
                $expectedLeftColumn,
                $verticalColumn,
            ),
        );
    }

    #[Test]
    public function placesLabelNextToVerticalEdgeSegmentOnBranchingEdge(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->buildFullLayout(
            nodes: [
                new Node('hub', 'Hub'),
                new Node('left', 'Left'),
                new Node('right', 'Right'),
            ],
            edges: [
                new Edge('hub', 'left', label: new Label('go')),
                new Edge('hub', 'right'),
            ],
        );

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        $labeledEdge = null;
        foreach ($layoutGraph->edges() as $edge) {
            if (null !== $edge->edge->label) {
                $labeledEdge = $edge;
            }
        }
        self::assertNotNull($labeledEdge);

        $firstRow = $labeledEdge->waypoints[0]->row;
        $lastRow = $labeledEdge->waypoints[count($labeledEdge->waypoints) - 1]->row;
        $labelRow = intdiv($firstRow + $lastRow, 2);

        $leftNode = $layoutGraph->getLayoutNode('left');
        $verticalSegmentColumn = $leftNode->column + intdiv($leftNode->boxWidth(), 2);
        $labelWidth = mb_strlen('go');
        $expectedLabelColumn = $verticalSegmentColumn - $labelWidth - 1;

        self::assertSame('g', $canvas->get($labelRow, $expectedLabelColumn)->resolvedCharacter(),
            sprintf(
                'Label "go" should be on the outer (left) side at column %d (edge segment at %d)',
                $expectedLabelColumn,
                $verticalSegmentColumn,
            ),
        );
    }

    #[Test]
    public function passesEdgeColorToLabelText(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->buildFullLayout(
            nodes: [new Node('A', 'A'), new Node('B', 'B')],
            edges: [new Edge('A', 'B', label: new Label('yes'), color: AnsiColor::Green)],
        );

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        $edge = $layoutGraph->edges()[0];
        $firstRow = $edge->waypoints[0]->row;
        $lastRow = $edge->waypoints[count($edge->waypoints) - 1]->row;
        $labelRow = intdiv($firstRow + $lastRow, 2);
        $labelColumn = $edge->waypoints[0]->column + 2;

        self::assertSame(AnsiColor::Green, $canvas->get($labelRow, $labelColumn)->resolvedColor());
    }

    #[Test]
    public function placesLabelBelowHorizontalEdgeInLeftToRightMode(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->buildLeftToRightLayout(
            nodes: [new Node('A', 'A'), new Node('B', 'B')],
            edges: [new Edge('A', 'B', label: new Label('yes'))],
        );

        (new EdgeRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);
        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        $edge = $layoutGraph->edges()[0];
        $firstColumn = $edge->waypoints[0]->column;
        $lastColumn = $edge->waypoints[count($edge->waypoints) - 1]->column;
        $labelColumn = intdiv($firstColumn + $lastColumn, 2);
        $edgeRow = $edge->waypoints[0]->row;

        self::assertSame('y', $canvas->get($edgeRow + 1, $labelColumn)->resolvedCharacter(),
            'LR label should be placed one row below the horizontal edge segment',
        );
    }

    #[Test]
    public function placesTargetLabelNextToHorizontalSegmentInLeftToRightMode(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->buildLeftToRightLayout(
            nodes: [
                new Node('hub', 'Hub'),
                new Node('n1', 'Node 1'),
                new Node('n2', 'Node 2'),
                new Node('n3', 'Node 3'),
            ],
            edges: [
                new Edge('hub', 'n1'),
                new Edge('hub', 'n2'),
                new Edge('hub', 'n3', label: new Label('go', LabelPosition::Target)),
            ],
        );

        (new EdgeRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);
        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        $labeledEdge = null;
        foreach ($layoutGraph->edges() as $edge) {
            if (null !== $edge->edge->label) {
                $labeledEdge = $edge;
            }
        }
        self::assertNotNull($labeledEdge);

        $lastColumn = $labeledEdge->waypoints[count($labeledEdge->waypoints) - 1]->column;
        $labelColumn = $lastColumn - 1;

        $horizontalRow = $this->findHorizontalSegmentRow($labeledEdge->waypoints, $labelColumn);
        self::assertSame('g', $canvas->get($horizontalRow + 1, $labelColumn)->resolvedCharacter(),
            'Target-positioned LR label on a downward lane should be placed below the horizontal segment, on the outside of its own lane',
        );
    }

    #[Test]
    public function placesSourceLabelAtFirstColumnPlusOneInLeftToRightMode(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->buildLeftToRightLayout(
            nodes: [new Node('A', 'A'), new Node('B', 'B')],
            edges: [new Edge('A', 'B', label: new Label('ok', LabelPosition::Source))],
        );

        (new EdgeRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);
        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        $edge = $layoutGraph->edges()[0];
        $expectedColumn = $edge->waypoints[0]->column + 1;
        $edgeRow = $edge->waypoints[0]->row;

        self::assertSame('o', $canvas->get($edgeRow + 1, $expectedColumn)->resolvedCharacter(),
            'Source-positioned LR label should start at firstColumn + 1',
        );
    }

    #[Test]
    public function passesEdgeColorToLabelTextInLeftToRightMode(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->buildLeftToRightLayout(
            nodes: [new Node('A', 'A'), new Node('B', 'B')],
            edges: [new Edge('A', 'B', label: new Label('yes'), color: AnsiColor::Green)],
        );

        (new EdgeRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);
        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        $edge = $layoutGraph->edges()[0];
        $firstColumn = $edge->waypoints[0]->column;
        $lastColumn = $edge->waypoints[count($edge->waypoints) - 1]->column;
        $labelColumn = intdiv($firstColumn + $lastColumn, 2);
        $edgeRow = $edge->waypoints[0]->row;

        self::assertSame(AnsiColor::Green, $canvas->get($edgeRow + 1, $labelColumn)->resolvedColor());
    }

    #[Test]
    public function leftToRightLabelDoesNotCreateLeadingBlankLines(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('start', 'Review'));
        $graph->addNode(new Node('approve', 'Approve'));
        $graph->addNode(new Node('reject', 'Reject'));
        $graph->addNode(new Node('done', 'Done'));
        $graph->addEdge(new Edge('start', 'approve', label: new Label('yes')));
        $graph->addEdge(new Edge('start', 'reject', label: new Label('no')));
        $graph->addEdge(new Edge('approve', 'done'));
        $graph->addEdge(new Edge('reject', 'done'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new LeftToRightPositioning())->position($layoutGraph);
        (new LeftToRightLabelReserver())->process($layoutGraph);
        (new LeftToRightRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        $canvas = new Canvas();
        (new BoxRenderer())->render($canvas, $layoutGraph);
        (new EdgeRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);
        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        $output = (new PlainTextFormatter())->format($canvas);
        $firstLine = explode("\n", $output)[0];
        self::assertNotSame('', trim($firstLine),
            'First line must not be blank — label placement must not extend above graph content');
    }

    #[Test]
    public function leftToRightLabelHasAtLeastOneSpaceMarginToAdjacentStructures(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('start', 'Review'));
        $graph->addNode(new Node('approve', 'Approve'));
        $graph->addNode(new Node('reject', 'Reject'));
        $graph->addNode(new Node('done', 'Done'));
        $graph->addEdge(new Edge('start', 'approve', label: new Label('yes')));
        $graph->addEdge(new Edge('start', 'reject', label: new Label('no')));
        $graph->addEdge(new Edge('approve', 'done'));
        $graph->addEdge(new Edge('reject', 'done'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new LeftToRightPositioning())->position($layoutGraph);
        (new LeftToRightLabelReserver())->process($layoutGraph);
        (new LeftToRightRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        $canvas = new Canvas();
        (new BoxRenderer())->render($canvas, $layoutGraph);
        (new EdgeRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);
        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        $output = (new PlainTextFormatter())->format($canvas);

        self::assertDoesNotMatchRegularExpression(
            '/[a-z]╭|╮[a-z]|[a-z]╰|╯[a-z]|[a-z]│|│[a-z]/u',
            $output,
            'Labels must have at least 1 space margin to adjacent box borders and edges: '.$output,
        );
    }

    #[Test]
    public function leftToRightLabelIsNotTruncatedByBoxBorders(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('start', 'Review'));
        $graph->addNode(new Node('approve', 'Approve'));
        $graph->addNode(new Node('reject', 'Reject'));
        $graph->addNode(new Node('done', 'Done'));
        $graph->addEdge(new Edge('start', 'approve', label: new Label('yes')));
        $graph->addEdge(new Edge('start', 'reject', label: new Label('no')));
        $graph->addEdge(new Edge('approve', 'done'));
        $graph->addEdge(new Edge('reject', 'done'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new LeftToRightPositioning())->position($layoutGraph);
        (new LeftToRightLabelReserver())->process($layoutGraph);
        (new LeftToRightRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        $canvas = new Canvas();
        (new BoxRenderer())->render($canvas, $layoutGraph);
        (new EdgeRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);
        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        $output = (new PlainTextFormatter())->format($canvas);

        self::assertStringContainsString('yes', $output,
            'Full label "yes" must be visible — not truncated by box borders');
    }

    #[Test]
    public function placesUpwardLeftToRightLabelDirectlyAboveEdgeSegment(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(5, 0, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[5, 0], [2, 0], [2, 10]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(1, 5)->resolvedCharacter(),
            'Upward-lane label must sit exactly one row above the horizontal segment');
    }

    #[Test]
    public function placesUpwardLabelAtTopRowWhenIntermediateRowsAreBlocked(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(2, 5, 'X', 10);
        $canvas->putCharacter(1, 5, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[5, 0], [3, 0], [3, 10]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(0, 5)->resolvedCharacter(),
            'Row 0 is a valid placement and must not trigger the downward fallback');
    }

    #[Test]
    public function fallsBackBelowWhenAllRowsAboveUpwardEdgeAreBlocked(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(1, 5, 'X', 10);
        $canvas->putCharacter(0, 5, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[4, 0], [2, 0], [2, 10]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(3, 5)->resolvedCharacter(),
            'When every row above is blocked the label must fall back below the edge segment');
    }

    #[Test]
    public function fallbackScansPastBlockedRowDirectlyBelowUpwardEdge(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(1, 5, 'X', 10);
        $canvas->putCharacter(0, 5, 'X', 10);
        $canvas->putCharacter(3, 5, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[4, 0], [2, 0], [2, 10]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(4, 5)->resolvedCharacter(),
            'The downward fallback must keep scanning past a blocked row instead of dropping the label onto it');
    }

    #[Test]
    public function fallbackSkipsRowWhoseLeftNeighborIsOccupied(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(1, 5, 'X', 10);
        $canvas->putCharacter(0, 5, 'X', 10);
        $canvas->putCharacter(3, 4, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[4, 0], [2, 0], [2, 10]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(4, 5)->resolvedCharacter(),
            'The downward fallback must skip a row whose cell left of the label is occupied to keep a 1-space margin');
    }

    #[Test]
    public function fallbackSkipsRowWhoseRightMarginIsOccupied(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(1, 5, 'X', 10);
        $canvas->putCharacter(0, 5, 'X', 10);
        $canvas->putCharacter(3, 8, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[4, 0], [2, 0], [2, 10]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(4, 5)->resolvedCharacter(),
            'The downward fallback must skip a row whose cell right of the label is occupied to keep a 1-space margin');
    }

    #[Test]
    public function fallbackAcceptsRowWithExactlyOneFreeCellAfterLabel(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(1, 5, 'X', 10);
        $canvas->putCharacter(0, 5, 'X', 10);
        $canvas->putCharacter(3, 9, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[4, 0], [2, 0], [2, 10]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(3, 5)->resolvedCharacter(),
            'One blank margin cell after the label suffices in the fallback; occupancy beyond it must not reject the row');
    }

    #[Test]
    public function fallbackOverwritesLowerLayerGlyphUnderLabelBody(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(1, 5, 'X', 10);
        $canvas->putCharacter(0, 5, 'X', 10);
        $canvas->putCharacter(3, 5, '│', 5);
        $layoutGraph = $this->labeledEdgeGraph([[4, 0], [2, 0], [2, 10]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(3, 5)->resolvedCharacter(),
            'Only the margin cell left of the label must be free in the fallback; the label body may overwrite lower z-index glyphs');
    }

    #[Test]
    public function fallsBackBelowWhenUpwardEdgeRowLiesBeyondCanvasContent(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(0, 0, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[5, 0], [3, 0], [3, 10]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(4, 5)->resolvedCharacter(),
            'An upward scan ending at the canvas-height boundary must trigger the downward fallback below the edge');
    }

    #[Test]
    public function skipsCandidateRowWhoseLeftNeighborIsOccupied(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(3, 4, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[2, 0], [2, 10]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(4, 5)->resolvedCharacter(),
            'A row whose cell left of the label is occupied must be skipped to keep a 1-space margin');
    }

    #[Test]
    public function overwritesLowerLayerGlyphUnderLabelBody(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(3, 5, '│', 5);
        $layoutGraph = $this->labeledEdgeGraph([[2, 0], [2, 10]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(3, 5)->resolvedCharacter(),
            'Only the margin cell left of the label is required to be free; the label body may overwrite lower z-index glyphs');
    }

    #[Test]
    public function skipsCandidateRowWhereLabelBodyOverlapsBlockedCells(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(3, 5, 'X', 10);
        $canvas->putCharacter(3, 6, 'X', 10);
        $canvas->putCharacter(3, 7, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[2, 0], [2, 10]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(4, 5)->resolvedCharacter(),
            'A row where the label body would be swallowed by higher z-index cells must be skipped');
    }

    #[Test]
    public function acceptsRowWithExactlyOneFreeCellAfterLabel(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(3, 8, ' ', 10);
        $canvas->putCharacter(3, 9, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[2, 0], [2, 10]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(3, 5)->resolvedCharacter(),
            'One blank margin cell after the label suffices; occupancy beyond it must not reject the row');
    }

    #[Test]
    public function usesFirstWaypointRowWhenNoHorizontalSegmentExists(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(5, 9, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[0, 0], [5, 0]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(1, 0)->resolvedCharacter(),
            'Without a horizontal segment the label must anchor at the first waypoint row');
    }

    #[Test]
    public function matchesHorizontalSegmentEndingExactlyAtLabelColumn(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(8, 0, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[5, 0], [5, 3], [0, 3], [0, 6]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(6, 3)->resolvedCharacter(),
            'A label column on the inclusive end of a horizontal segment must anchor to that segment row');
    }

    #[Test]
    public function selectsHorizontalSegmentContainingLabelColumnNotFirstSegment(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(8, 0, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[5, 0], [5, 2], [0, 2], [0, 8]], new Label('yes'));

        (new LabelRenderer(FlowDirection::LeftToRight))->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(1, 4)->resolvedCharacter(),
            'The label must anchor to the horizontal segment that actually spans its column');
    }

    #[Test]
    public function usesFirstWaypointColumnWhenNoVerticalSegmentExists(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 0], [0, 5]], new Label('yes'));

        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(0, 2)->resolvedCharacter(),
            'Without a vertical segment the label must anchor at the first waypoint column');
    }

    #[Test]
    public function selectsVerticalSegmentContainingLabelRowNotFirstSegment(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 5], [2, 5], [2, 0], [8, 0]], new Label('yes'));

        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(4, 2)->resolvedCharacter(),
            'The label must anchor to the vertical segment that actually spans its row');
    }

    #[Test]
    public function fallsBackToInnerSideWhenOuterLeftRegionIsOccupied(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(2, 3, 'X', 10);
        $layoutGraph = $this->labeledEdgeGraph([[0, 9], [1, 9], [1, 6], [5, 6]], new Label('yes'));

        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(2, 8)->resolvedCharacter(),
            'When the preferred left region is occupied the label must move to the right of the edge');
    }

    #[Test]
    public function prefersTheSideAwayFromTheSourceOfABendingEdge(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 12], [0, 6], [8, 6]], new Label('yes'));

        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(4, 2)->resolvedCharacter(),
            'A left-bending edge must carry its label on the left, away from the source');
    }

    #[Test]
    public function prefersRowsAboveTheAnchorWhenBothSidesAreBlocked(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 6], [8, 6]], new Label('yy'));

        $canvas->text(4, 2, 'AAAA', 10);
        $canvas->text(4, 7, 'AAAA', 10);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(2, 8)->resolvedCharacter(),
            'With the anchor row blocked the label must climb toward the source, not sink below');
    }

    #[Test]
    public function anchorsTargetPositionedLabelAtTheRowAboveTheEdgeEnd(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 5], [6, 5]], new Label('yy', LabelPosition::Target));

        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(5, 7)->resolvedCharacter(),
            'A Target-positioned label must sit beside the row above the edge end');
    }

    #[Test]
    public function rejectsSlotsOverlappingABoxRowBand(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraphWithBox([[2, 7], [10, 7]], new Label('yy'), boxRow: 4, boxColumn: 10);

        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(6, 4)->resolvedCharacter(),
            'A slot whose span reaches into a box row band must be rejected in favour of the other side');
    }

    #[Test]
    public function acceptsASlotJustPastABoxRightEdge(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraphWithBox([[2, 13], [10, 13]], new Label('yy'), boxRow: 4, boxColumn: 10);

        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(6, 15)->resolvedCharacter(),
            'A slot starting right after the box right edge must be accepted');
    }

    #[Test]
    public function rejectsASlotTouchingABoxRightEdge(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraphWithBox([[2, 12], [10, 12]], new Label('yy'), boxRow: 4, boxColumn: 10);

        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(2, 14)->resolvedCharacter(),
            'A slot starting on the box right edge must be rejected and moved above the box band');
    }

    #[Test]
    public function prefersTheRightSideOfAStraightConvergingEdge(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->convergingEdgeGraph(new Label('yy'));

        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(3, 8)->resolvedCharacter(),
            'A straight converging edge must carry its label on the right of its own drop');
    }

    #[Test]
    public function fallsBackToTheLeftWhenTheRightOfAConvergingEdgeIsBlocked(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->convergingEdgeGraph(new Label('ab'));
        for ($row = 3; $row <= 8; ++$row) {
            $canvas->text($row, 8, 'XXX', 10);
        }

        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame(' ', $canvas->get(3, 2)->resolvedCharacter(), 'The left slot must start exactly at drop - width - 1');
        self::assertSame('a', $canvas->get(3, 3)->resolvedCharacter(),
            'With the right side blocked the label must move to the left of its own drop');
    }

    #[Test]
    public function skipsAnUnfitNegativeSlotForAFittingOne(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 2], [6, 2]], new Label('yy'));
        for ($row = 0; $row <= 7; ++$row) {
            $canvas->text($row, 4, 'XXXXX', 10);
        }
        $canvas->text(3, -1, 'Z', 10);

        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(1, -1)->resolvedCharacter(),
            'An occupied negative slot must be skipped for a clear one, never overwritten');
    }

    #[Test]
    public function requiresAFreeFlankBesideTheLabel(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 2], [6, 2]], new Label('yy'));
        $canvas->text(3, 6, 'Z', 10);

        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(2, 4)->resolvedCharacter(),
            'A slot whose right flank is occupied must be rejected for a clear row');
    }

    #[Test]
    public function acceptsASlotEndingJustBeforeABoxLeftEdge(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraphWithBox([[2, 6], [10, 6]], new Label('yy'), boxRow: 4, boxColumn: 10);

        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('y', $canvas->get(6, 8)->resolvedCharacter(),
            'A slot ending one column before the box left edge must be accepted');
    }

    #[Test]
    public function drawsALongEdgeLabelInlineOnItsLane(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 10], [8, 10]], new Label('aa || bb'));
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(4, 6)->resolvedCharacter(), 'Label centered on the lane: start = 10 - intdiv(8, 2)');
        self::assertSame('│', $canvas->get(3, 10)->resolvedCharacter(), 'Lane continues above the label');
        self::assertSame('│', $canvas->get(5, 10)->resolvedCharacter(), 'Lane continues below the label');
    }

    #[Test]
    public function slidesTheInlineLabelAlongTheLaneWhenTheAnchorRowIsBlocked(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 10], [8, 10]], new Label('aa || bb'));
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        $canvas->text(4, 12, 'XXX', 10);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(3, 6)->resolvedCharacter(), 'Blocked anchor row: the label slides one lane row up');
    }

    #[Test]
    public function findsAFreeInlineRowFarFromTheAnchorWhenTheEdgeSpansNegativeRows(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[-3, 10], [5, 10]], new Label('aa || bb'));
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        foreach ([1, 0, 2, -1, 3] as $row) {
            $canvas->text($row, 5, 'XXXXXXXXXX', 10);
        }
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(-2, 6)->resolvedCharacter(),
            'The offset walk must cover the full negative-to-positive row span, not just maxRow - minRow columns worth of rows below the anchor');
    }

    #[Test]
    public function prefersTheRowBelowTheAnchorWhenTheAnchorAndTheRowAboveAreBothBlocked(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 10], [8, 10]], new Label('aa || bb'));
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        $canvas->text(4, 5, 'XXXXXXXXXX', 10);
        $canvas->text(3, 5, 'XXXXXXXXXX', 10);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(5, 6)->resolvedCharacter(),
            'With the anchor row and the row above it blocked, the walk must still reach anchorRow + 1');
    }

    #[Test]
    public function excludesTheSourceExitRowItselfFromInlineCandidates(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 10], [8, 10]], new Label('aa || bb', LabelPosition::Source));
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        $canvas->text(1, 5, 'XXXXXXXXXX', 10);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(2, 6)->resolvedCharacter(),
            'Row 0 (minRow) is otherwise free but must be excluded; the label must land on row 2, not row 0');
    }

    #[Test]
    public function excludesTheTargetEntryRowItselfFromInlineCandidates(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 10], [8, 10]], new Label('aa || bb', LabelPosition::Target));
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        $canvas->text(7, 5, 'XXXXXXXXXX', 10);
        $canvas->text(6, 5, 'XXXXXXXXXX', 10);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(5, 6)->resolvedCharacter(),
            'Row 8 (maxRow) is otherwise free but must be excluded; the label must land on row 5, not row 8');
    }

    #[Test]
    public function rejectsAnInlineSlotWhoseLeftMarginCellIsOccupied(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 10], [8, 10]], new Label('aa || bb'));
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        $canvas->putCharacter(4, 5, 'X', 10);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(3, 6)->resolvedCharacter(),
            'Column start - 1 (the left margin) must be checked; an occupied margin must reject the anchor row');
    }

    #[Test]
    public function acceptsAnInlineSlotWhenOnlyTheCellTwoBeforeItIsOccupied(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 10], [8, 10]], new Label('aa || bb'));
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        $canvas->putCharacter(4, 4, 'X', 10);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(4, 6)->resolvedCharacter(),
            'Column start - 2 is outside the scanned margin; occupying it must not reject the anchor row');
    }

    #[Test]
    public function rejectsAnInlineSlotWhoseFirstColumnIsOccupied(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 10], [8, 10]], new Label('aa || bb'));
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        $canvas->putCharacter(4, 6, 'X', 10);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(3, 6)->resolvedCharacter(),
            'Column start (the label\'s own first character) must be checked; an occupied first column must reject the anchor row');
    }

    #[Test]
    public function rejectsAnInlineSlotWhoseRightMarginCellIsOccupied(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 10], [8, 10]], new Label('aa || bb'));
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        $canvas->putCharacter(4, 14, 'X', 10);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(3, 6)->resolvedCharacter(),
            'Column start + width (the right margin) must be checked; an occupied margin must reject the anchor row');
    }

    #[Test]
    public function rejectsAnInlineSlotOverlappingABoxEntirely(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraphWithBox([[0, 10], [8, 10]], new Label('aa || bb'), boxRow: 4, boxColumn: 6);
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(3, 6)->resolvedCharacter(),
            'A box squarely overlapping the anchor row and lane column must reject that row');
    }

    #[Test]
    public function rejectsAnInlineSlotTouchingABoxTopRow(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraphWithBox([[0, 10], [8, 10]], new Label('aa || bb'), boxRow: 4, boxColumn: 6);
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(3, 6)->resolvedCharacter(),
            'The anchor row sitting exactly on the box\'s top row must be rejected');
    }

    #[Test]
    public function rejectsAnInlineSlotTouchingABoxBottomRow(): void
    {
        $canvas = new Canvas();
        // boxHeight is 3, so boxRow 2 gives a [2, 4] band whose bottom row is the anchor (4);
        // row 3 also falls inside that band, so the walk must reach row 5 to find a free row.
        $layoutGraph = $this->labeledEdgeGraphWithBox([[0, 10], [8, 10]], new Label('aa || bb'), boxRow: 2, boxColumn: 6);
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(5, 6)->resolvedCharacter(),
            'The anchor row sitting exactly on the box\'s bottom row must be rejected');
    }

    #[Test]
    public function rejectsAnInlineSlotWhenABoxEndsExactlyAtItsLeftEdge(): void
    {
        $canvas = new Canvas();
        // boxWidth is 5; boxColumn 2 gives a box spanning columns [2, 6], whose right
        // edge (6) touches start (6).
        $layoutGraph = $this->labeledEdgeGraphWithBox([[0, 10], [8, 10]], new Label('aa || bb'), boxRow: 4, boxColumn: 2);
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(3, 6)->resolvedCharacter(),
            'A box whose right edge touches the label\'s own start column must reject the anchor row');
    }

    #[Test]
    public function rejectsAnInlineSlotWhenABoxStartsExactlyAtItsRightEdge(): void
    {
        $canvas = new Canvas();
        // start + width - 1 = 13, so boxColumn 13 touches the label's right edge.
        $layoutGraph = $this->labeledEdgeGraphWithBox([[0, 10], [8, 10]], new Label('aa || bb'), boxRow: 4, boxColumn: 13);
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(3, 6)->resolvedCharacter(),
            'A box whose left edge touches the label\'s right edge must reject the anchor row');
    }

    #[Test]
    public function acceptsAnInlineSlotWhenABoxStartsOneColumnPastItsRightEdge(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraphWithBox([[0, 10], [8, 10]], new Label('aa || bb'), boxRow: 4, boxColumn: 14);
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(4, 6)->resolvedCharacter(),
            'A box starting one column past the label\'s right edge must not reject the anchor row');
    }

    #[Test]
    public function acceptsAnInlineSlotWhenABoxStartsTwoColumnsPastItsRightEdge(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraphWithBox([[0, 10], [8, 10]], new Label('aa || bb'), boxRow: 4, boxColumn: 15);
        $layoutGraph->edges()[0]->labelLaneColumn = 10;

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        self::assertSame('a', $canvas->get(4, 6)->resolvedCharacter(),
            'A box starting two columns past the label\'s right edge must not reject the anchor row');
    }

    #[Test]
    public function fallsBackToBesideTheLaneWhenNoInlineRowFits(): void
    {
        $canvas = new Canvas();
        $layoutGraph = $this->labeledEdgeGraph([[0, 10], [8, 10]], new Label('yy'));
        $layoutGraph->edges()[0]->labelLaneColumn = 10;
        for ($row = 1; $row <= 7; ++$row) {
            $canvas->text($row, 12, 'XXXXXXXX', 10);
            $canvas->text($row, 5, 'XXXX', 10);
        }

        (new EdgeRenderer())->render($canvas, $layoutGraph);
        (new LabelRenderer())->render($canvas, $layoutGraph);

        $found = false;
        for ($row = 0; $row <= 9; ++$row) {
            for ($column = -6; $column <= 25; ++$column) {
                if ('y' === $canvas->cellAt($row, $column)?->resolvedCharacter()) {
                    $found = true;
                }
            }
        }
        self::assertTrue($found, 'With every inline row blocked the label must still render somewhere via the fallback');
    }

    private function convergingEdgeGraph(Label $label): LayoutGraph
    {
        $layoutGraph = new LayoutGraph();

        foreach ([['s1', 0, 4], ['s2', 0, 14], ['T', 9, 4]] as [$id, $row, $column]) {
            $node = new RealLayoutNode($id, new Node($id, 'N'));
            $node->row = $row;
            $node->column = $column;
            $layoutGraph->addNode($node);
        }

        $labeledEdge = new LayoutEdge(edge: new Edge('s1', 'T', label: $label));
        $labeledEdge->waypoints = [new Waypoint(3, 6), new Waypoint(8, 6)];
        $layoutGraph->addEdge($labeledEdge);

        $siblingEdge = new LayoutEdge(edge: new Edge('s2', 'T'));
        $siblingEdge->waypoints = [new Waypoint(3, 16), new Waypoint(8, 6)];
        $layoutGraph->addEdge($siblingEdge);

        return $layoutGraph;
    }

    /** @param list<array{int, int}> $waypointCoordinates */
    private function labeledEdgeGraphWithBox(array $waypointCoordinates, Label $label, int $boxRow, int $boxColumn): LayoutGraph
    {
        $layoutGraph = $this->labeledEdgeGraph($waypointCoordinates, $label);
        $bystander = new RealLayoutNode('bystander', new Node('bystander', 'B'));
        $bystander->row = $boxRow;
        $bystander->column = $boxColumn;
        $layoutGraph->addNode($bystander);

        return $layoutGraph;
    }

    /** @param list<array{int, int}> $waypointCoordinates */
    private function labeledEdgeGraph(array $waypointCoordinates, Label $label): LayoutGraph
    {
        $layoutGraph = new LayoutGraph();
        $layoutGraph->addNode(new DummyLayoutNode('source', 'source', 'target'));
        $layoutGraph->addNode(new DummyLayoutNode('target', 'source', 'target'));
        $layoutEdge = new LayoutEdge(edge: new Edge('source', 'target', label: $label));
        $layoutEdge->waypoints = array_map(
            static fn (array $coordinates): Waypoint => new Waypoint($coordinates[0], $coordinates[1]),
            $waypointCoordinates,
        );
        $layoutGraph->addEdge($layoutEdge);

        return $layoutGraph;
    }

    /**
     * @param list<Node> $nodes
     * @param list<Edge> $edges
     */
    private function buildFullLayout(array $nodes, array $edges = []): LayoutGraph
    {
        $graph = new Graph();
        foreach ($nodes as $node) {
            $graph->addNode($node);
        }
        foreach ($edges as $edge) {
            $graph->addEdge($edge);
        }

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $layers = (new LongestPathLayering())->assign($layoutGraph);
        foreach ($layers as $nodeId => $layer) {
            $layoutGraph->getLayoutNode($nodeId)->layer = $layer;
        }
        $layoutGraph->buildLayerIndex();
        (new DummyNodeInserter())->process($layoutGraph);
        (new BrandesKopfPositioning())->position($layoutGraph);
        (new LabelReserver())->process($layoutGraph);
        (new ChainAwareRouting())->route($layoutGraph);
        (new DummyNodeRemover())->process($layoutGraph);

        return $layoutGraph;
    }

    /**
     * @param list<Node> $nodes
     * @param list<Edge> $edges
     */
    private function buildLeftToRightLayout(array $nodes, array $edges = []): LayoutGraph
    {
        $graph = new Graph();
        foreach ($nodes as $node) {
            $graph->addNode($node);
        }
        foreach ($edges as $edge) {
            $graph->addEdge($edge);
        }

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

        return $layoutGraph;
    }

    /** @param list<Waypoint> $waypoints */
    private function findHorizontalSegmentRow(array $waypoints, int $column): int
    {
        for ($waypointOffset = 0, $lastWaypointOffset = count($waypoints) - 1; $waypointOffset < $lastWaypointOffset; ++$waypointOffset) {
            $from = $waypoints[$waypointOffset];
            $to = $waypoints[$waypointOffset + 1];
            if ($from->row !== $to->row) {
                continue;
            }
            $minColumn = min($from->column, $to->column);
            $maxColumn = max($from->column, $to->column);
            if ($column >= $minColumn && $column <= $maxColumn) {
                return $from->row;
            }
        }

        return $waypoints[0]->row;
    }
}
