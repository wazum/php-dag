<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Label;
use PhpDag\Graph\LabelPosition;
use PhpDag\Graph\Node;
use PhpDag\Layout\BrandesKopfPositioning;
use PhpDag\Layout\DummyNodeInserter;
use PhpDag\Layout\LabelReserver;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LongestPathLayering;
use PhpDag\Layout\Processor;
use PhpDag\Layout\RealLayoutNode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LabelReserverTest extends TestCase
{
    #[Test]
    public function implementsProcessor(): void
    {
        self::assertInstanceOf(Processor::class, new LabelReserver());
    }

    #[Test]
    public function doesNothingWhenNoLabels(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta')],
            edges: [new Edge('A', 'B')],
        );

        $rowBefore = $graph->getLayoutNode('B')->row;
        (new LabelReserver())->process($graph);
        $rowAfter = $graph->getLayoutNode('B')->row;

        self::assertSame($rowBefore, $rowAfter, 'Rows must not change when no labels present');
    }

    #[Test]
    public function shiftsTargetLayerDownWhenEdgeHasLabel(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta')],
            edges: [new Edge('A', 'B', label: new Label('yes'))],
        );

        $rowBefore = $graph->getLayoutNode('B')->row;
        (new LabelReserver())->process($graph);
        $rowAfter = $graph->getLayoutNode('B')->row;

        self::assertGreaterThan($rowBefore, $rowAfter, 'Target row must shift down to make space for label');
    }

    #[Test]
    public function shiftsMultipleLayersForLabelsInDifferentGaps(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'A'), new Node('B', 'B'), new Node('C', 'C')],
            edges: [
                new Edge('A', 'B', label: new Label('first')),
                new Edge('B', 'C', label: new Label('second')),
            ],
        );

        $rowBBefore = $graph->getLayoutNode('B')->row;
        $rowCBefore = $graph->getLayoutNode('C')->row;

        (new LabelReserver())->process($graph);

        $rowBAfter = $graph->getLayoutNode('B')->row;
        $rowCAfter = $graph->getLayoutNode('C')->row;

        self::assertSame($rowBBefore + 1, $rowBAfter, 'B shifts by 1 (label in gap 0)');
        self::assertSame($rowCBefore + 2, $rowCAfter, 'C shifts by 2 (cumulative: gap 0 + gap 1)');
    }

    #[Test]
    public function skipsUnlabeledEdgesAndProcessesLabeledOnes(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'A'), new Node('B', 'B'), new Node('C', 'C')],
            edges: [
                new Edge('A', 'B'),
                new Edge('A', 'C', label: new Label('tag')),
            ],
        );

        $rowBBefore = $graph->getLayoutNode('B')->row;
        $rowCBefore = $graph->getLayoutNode('C')->row;

        (new LabelReserver())->process($graph);

        self::assertSame($rowBBefore, $graph->getLayoutNode('B')->row, 'B must not shift: the bending gap already provides a connector row the label can use');
        self::assertSame($rowCBefore, $graph->getLayoutNode('C')->row, 'C must not shift: the bending gap already provides a connector row the label can use');
    }

    #[Test]
    public function shiftsForSourcePositionLabel(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'A'), new Node('B', 'B')],
            edges: [new Edge('A', 'B', label: new Label('src', LabelPosition::Source))],
        );

        $rowBefore = $graph->getLayoutNode('B')->row;
        (new LabelReserver())->process($graph);

        self::assertSame($rowBefore + 1, $graph->getLayoutNode('B')->row, 'B shifts down for Source-positioned label');
    }

    #[Test]
    public function shiftsForTargetPositionLabel(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'A'), new Node('B', 'B'), new Node('C', 'C')],
            edges: [
                new Edge('A', 'B'),
                new Edge('B', 'C', label: new Label('tgt', LabelPosition::Target)),
            ],
        );

        $rowBBefore = $graph->getLayoutNode('B')->row;
        $rowCBefore = $graph->getLayoutNode('C')->row;

        (new LabelReserver())->process($graph);

        self::assertSame($rowBBefore, $graph->getLayoutNode('B')->row, 'B must not shift (label is in gap before C)');
        self::assertSame($rowCBefore + 1, $graph->getLayoutNode('C')->row, 'C shifts for Target-positioned label in gap 1');
    }

    #[Test]
    public function middlePositionLabelOnBendingGapDoesNotShiftLayers(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'A'), new Node('B', 'B'), new Node('C', 'C')],
            edges: [
                new Edge('A', 'B'),
                new Edge('B', 'C'),
                new Edge('A', 'C', label: new Label('mid', LabelPosition::Middle)),
            ],
        );

        $rowBBefore = $graph->getLayoutNode('B')->row;
        $rowCBefore = $graph->getLayoutNode('C')->row;

        (new LabelReserver())->process($graph);

        self::assertSame($rowBBefore, $graph->getLayoutNode('B')->row, 'B must not shift (label is in gap 1, between layers 1 and 2)');
        self::assertSame($rowCBefore, $graph->getLayoutNode('C')->row, 'C must not shift: gap 1 bends, so the label reuses the connector row');
    }

    #[Test]
    public function doesNotShiftColumnsWhenLabelFitsOnRightSide(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'A'), new Node('B', 'B')],
            edges: [new Edge('A', 'B', label: new Label('yes'))],
        );

        $columnBBefore = $graph->getLayoutNode('B')->column;
        (new LabelReserver())->process($graph);

        self::assertSame($columnBBefore, $graph->getLayoutNode('B')->column, 'Column must not change when label fits');
    }

    #[Test]
    public function shiftsNodeColumnWhenLabelDoesNotFitOnEitherSide(): void
    {
        $graph = new LayoutGraph();

        $nodeA = new RealLayoutNode('A', new Node('A', 'A'));
        $nodeA->layer = 0;
        $nodeA->row = 0;
        $nodeA->column = 10;
        $graph->addNode($nodeA);

        $nodeB = new RealLayoutNode('B', new Node('B', 'VeryWideBoxHere'));
        $nodeB->layer = 1;
        $nodeB->row = 5;
        $nodeB->column = 0;
        $graph->addNode($nodeB);

        $nodeC = new RealLayoutNode('C', new Node('C', 'C'));
        $nodeC->layer = 1;
        $nodeC->row = 5;
        $nodeC->column = 14;
        $graph->addNode($nodeC);

        $labeledEdge = new Edge('A', 'C', label: new Label('mytag'));
        $graph->addEdge(new LayoutEdge(edge: new Edge('A', 'B')));
        $graph->addEdge(new LayoutEdge(edge: $labeledEdge));
        $graph->storeOriginalEdge(new Edge('A', 'B'));
        $graph->storeOriginalEdge($labeledEdge);
        $graph->buildLayerIndex();

        (new LabelReserver())->process($graph);

        self::assertSame(0, $nodeB->column, 'B is left of edge center — must not shift');
        self::assertSame(35, $nodeC->column, 'C must shift right: shiftAmount = rightEnd(19) - rightConflict.column(0) + 2 = 21, so 14 + 21 = 35');
    }

    #[Test]
    public function shiftAmountAccountsForConflictNodeColumnOffset(): void
    {
        $graph = new LayoutGraph();

        $nodeA = new RealLayoutNode('A', new Node('A', 'A'));
        $nodeA->layer = 0;
        $nodeA->row = 0;
        $nodeA->column = 10;
        $graph->addNode($nodeA);

        $nodeB = new RealLayoutNode('B', new Node('B', 'VeryWideBoxHere'));
        $nodeB->layer = 1;
        $nodeB->row = 5;
        $nodeB->column = 5;
        $graph->addNode($nodeB);

        $nodeC = new RealLayoutNode('C', new Node('C', 'C'));
        $nodeC->layer = 1;
        $nodeC->row = 5;
        $nodeC->column = 14;
        $graph->addNode($nodeC);

        $labeledEdge = new Edge('A', 'C', label: new Label('mytag'));
        $graph->addEdge(new LayoutEdge(edge: new Edge('A', 'B')));
        $graph->addEdge(new LayoutEdge(edge: $labeledEdge));
        $graph->storeOriginalEdge(new Edge('A', 'B'));
        $graph->storeOriginalEdge($labeledEdge);
        $graph->buildLayerIndex();

        (new LabelReserver())->process($graph);

        self::assertSame(5, $nodeB->column, 'B is left of edge center — must not shift');
        self::assertSame(30, $nodeC->column, 'shiftAmount = rightEnd(19) - conflict.column(5) + 2 = 16, so 14 + 16 = 30');
    }

    #[Test]
    public function reservesGapForLaterLabeledEdgeWhenEarlierLabelSitsOnBendingGap(): void
    {
        $graph = new LayoutGraph();

        $nodeA = new RealLayoutNode('A', new Node('A', 'A'));
        $nodeA->layer = 0;
        $nodeA->row = 0;
        $nodeA->column = 0;
        $graph->addNode($nodeA);

        $nodeB = new RealLayoutNode('B', new Node('B', 'B'));
        $nodeB->layer = 1;
        $nodeB->row = 5;
        $nodeB->column = 10;
        $graph->addNode($nodeB);

        $nodeC = new RealLayoutNode('C', new Node('C', 'C'));
        $nodeC->layer = 2;
        $nodeC->row = 10;
        $nodeC->column = 10;
        $graph->addNode($nodeC);

        $nodeD = new RealLayoutNode('D', new Node('D', 'D'));
        $nodeD->layer = 3;
        $nodeD->row = 15;
        $nodeD->column = 10;
        $graph->addNode($nodeD);

        $bendingLabeledEdge = new Edge('A', 'B', label: new Label('x'));
        $straightLabeledEdge = new Edge('C', 'D', label: new Label('y'));
        $graph->addEdge(new LayoutEdge(edge: $bendingLabeledEdge));
        $graph->addEdge(new LayoutEdge(edge: new Edge('B', 'C')));
        $graph->addEdge(new LayoutEdge(edge: $straightLabeledEdge));
        $graph->storeOriginalEdge($bendingLabeledEdge);
        $graph->storeOriginalEdge($straightLabeledEdge);
        $graph->buildLayerIndex();

        (new LabelReserver())->process($graph);

        self::assertSame(5, $nodeB->row, 'B must not shift: the first label sits on bending gap 0 which is skipped');
        self::assertSame(10, $nodeC->row, 'C must not shift: the second label reserves gap 2, between C and D');
        self::assertSame(16, $nodeD->row, 'D must shift: skipping the bending gap must not abort reservation for later labeled edges');
    }

    #[Test]
    public function shiftsAllColumnsForOuterLabelEvenWhenUnlabeledEdgeComesFirst(): void
    {
        $graph = new LayoutGraph();

        $nodeA = new RealLayoutNode('A', new Node('A', 'A'));
        $nodeA->layer = 0;
        $nodeA->row = 0;
        $nodeA->column = 10;
        $graph->addNode($nodeA);

        $nodeB = new RealLayoutNode('B', new Node('B', 'B'));
        $nodeB->layer = 1;
        $nodeB->row = 5;
        $nodeB->column = 0;
        $graph->addNode($nodeB);

        $nodeC = new RealLayoutNode('C', new Node('C', 'C'));
        $nodeC->layer = 1;
        $nodeC->row = 5;
        $nodeC->column = 20;
        $graph->addNode($nodeC);

        $unlabeledEdge = new Edge('A', 'C');
        $labeledEdge = new Edge('A', 'B', label: new Label('mytag'));
        $graph->addEdge(new LayoutEdge(edge: $unlabeledEdge));
        $graph->addEdge(new LayoutEdge(edge: $labeledEdge));
        $graph->storeOriginalEdge($unlabeledEdge);
        $graph->storeOriginalEdge($labeledEdge);
        $graph->buildLayerIndex();

        (new LabelReserver())->process($graph);

        self::assertSame(14, $nodeA->column, 'A shifts right by exactly 4: label needs columns down to targetCenter(2) - width(5) - 1 = -4');
        self::assertSame(4, $nodeB->column, 'B shifts right by the same amount; an unlabeled edge before the labeled one must not stop the scan');
        self::assertSame(24, $nodeC->column, 'C shifts right by the same amount');
    }

    #[Test]
    public function outerLabelMarginComparesAgainstSourceBoxCenter(): void
    {
        $graph = new LayoutGraph();

        $nodeA = new RealLayoutNode('A', new Node('A', 'A'));
        $nodeA->layer = 0;
        $nodeA->row = 0;
        $nodeA->column = 10;
        $graph->addNode($nodeA);

        $nodeB = new RealLayoutNode('B', new Node('B', 'B'));
        $nodeB->layer = 1;
        $nodeB->row = 5;
        $nodeB->column = 9;
        $graph->addNode($nodeB);

        $labeledEdge = new Edge('A', 'B', label: new Label('elevenchars'));
        $graph->addEdge(new LayoutEdge(edge: $labeledEdge));
        $graph->storeOriginalEdge($labeledEdge);
        $graph->buildLayerIndex();

        (new LabelReserver())->process($graph);

        self::assertSame(11, $nodeA->column, 'targetCenter(11) is one left of sourceCenter(12), so the outer label still needs a 1-column right shift');
        self::assertSame(10, $nodeB->column, 'B shifts right by the same amount');
    }

    /**
     * @param list<Node> $nodes
     * @param list<Edge> $edges
     */
    private function buildPositionedGraph(array $nodes, array $edges = []): LayoutGraph
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

        return $layoutGraph;
    }
}
