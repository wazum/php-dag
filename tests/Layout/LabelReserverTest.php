<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Label;
use PhpDag\Graph\LabelPosition;
use PhpDag\Graph\Node;
use PhpDag\Layout\BrandesKopfPositioning;
use PhpDag\Layout\DummyLayoutNode;
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
    public function reservesLabelSpansAndWidensOnlyTheChannelThatNeedsIt(): void
    {
        $graph = new LayoutGraph();
        $sourceOne = $this->placedNode($graph, 's1', layer: 0, column: 0);
        $sourceTwo = $this->placedNode($graph, 's2', layer: 0, column: 8);
        $sourceThree = $this->placedNode($graph, 's3', layer: 0, column: 16);
        $target = $this->placedNode($graph, 'T', layer: 1, column: 8, row: 6);

        $this->connectLabeled($graph, 's1', 'T', 'aa');
        $this->connectLabeled($graph, 's2', 'T', 'wide-99.99');
        $this->connectLabeled($graph, 's3', 'T', null);
        $graph->buildLayerIndex();

        (new LabelReserver())->process($graph);

        self::assertSame(0, $sourceOne->column, 'The channel beside the narrow label already fits');
        self::assertSame(8, $sourceTwo->column);
        self::assertSame(21, $sourceThree->column, 'The channel beside the wide label must gain 2+10+1 - 8 = 5 columns');
        self::assertSame(8, $target->column, 'The target sits left of the shift threshold');
        self::assertSame([[3, 6], [11, 22]], $graph->reservedLabelSpans(0), 'Each claim reserves label width plus flanks beside its drop');
    }

    #[Test]
    public function recordsNoSpanForExplicitlyPositionedLabels(): void
    {
        $graph = new LayoutGraph();
        $this->placedNode($graph, 's1', layer: 0, column: 0);
        $this->placedNode($graph, 's2', layer: 0, column: 8);
        $this->placedNode($graph, 'T', layer: 1, column: 4, row: 6);

        $this->connectLabeled($graph, 's1', 'T', null);
        $labeledEdge = new Edge('s2', 'T', label: new Label('wide-99.99', LabelPosition::Target));
        $graph->addEdge(new LayoutEdge(edge: $labeledEdge));
        $graph->storeOriginalEdge($labeledEdge);
        $graph->buildLayerIndex();

        (new LabelReserver())->process($graph);

        self::assertSame([], $graph->reservedLabelSpans(0), 'Only Middle-positioned labels claim converging channels');
    }

    #[Test]
    public function claimsTheSideDictatedByTheEntryCentre(): void
    {
        // The drop sits one column left of the entry, so the label claims the
        // left channel; measuring the entry centre differently flips the side.
        $graph = new LayoutGraph();
        $this->placedNode($graph, 's1', layer: 0, column: 0);
        $this->placedNode($graph, 's2', layer: 0, column: 5);
        $this->placedNode($graph, 'T', layer: 1, column: 6, row: 6);

        $this->connectLabeled($graph, 's1', 'T', null);
        $this->connectLabeled($graph, 's2', 'T', 'qq');
        $graph->buildLayerIndex();

        (new LabelReserver())->process($graph);

        self::assertSame([[3, 6]], $graph->reservedLabelSpans(0));
    }

    #[Test]
    public function mergesSameCentreBoundsToTheLeftmostColumn(): void
    {
        // A real node and a dummy share a centre column: the shift threshold
        // must be the leftmost owner so the real box moves with its bound.
        $graph = new LayoutGraph();
        $left = $this->placedNode($graph, 'L', layer: 0, column: 0);
        $middle = $this->placedNode($graph, 'R', layer: 0, column: 4);
        $target = $this->placedNode($graph, 'T', layer: 1, column: 0, row: 6);
        $this->placedNode($graph, 'X', layer: 1, column: 30, row: 6);

        $dummy = new DummyLayoutNode('D', 'p', 'q');
        $dummy->layer = 0;
        $dummy->row = 0;
        $dummy->column = 6;
        $graph->addNode($dummy);

        $this->connectLabeled($graph, 'L', 'T', 'wwwwww');
        $this->connectLabeled($graph, 'R', 'T', null);
        $graph->addEdge(new LayoutEdge(edge: new Edge('D', 'X')));
        $graph->buildLayerIndex();

        (new LabelReserver())->process($graph);

        self::assertSame(0, $left->column);
        self::assertSame(0, $target->column);
        self::assertSame(9, $middle->column, 'The bound owner and everything at or right of it shift by the deficit');
        self::assertSame(11, $dummy->column);
    }

    #[Test]
    public function reservesSpansForConvergingFamiliesInEveryGapLayer(): void
    {
        // Two merge points at different depths, each fed by a labeled pair; both
        // gaps must reserve their spans, not only the first one encountered.
        $graph = new LayoutGraph();
        $this->placedNode($graph, 'a0', layer: 0, column: 0);
        $this->placedNode($graph, 'b0', layer: 0, column: 12);
        $this->placedNode($graph, 'm', layer: 1, column: 0, row: 6);
        $this->placedNode($graph, 'n', layer: 1, column: 14, row: 6);
        $this->placedNode($graph, 'T', layer: 2, column: 0, row: 12);

        $this->connectLabeled($graph, 'a0', 'm', 'gapzero-a');
        $this->connectLabeled($graph, 'b0', 'm', 'gapzero-b');
        $this->connectLabeled($graph, 'm', 'T', 'gapone-m');
        $this->connectLabeled($graph, 'n', 'T', 'gapone-n');
        $graph->buildLayerIndex();

        (new LabelReserver())->process($graph);

        self::assertNotSame([], $graph->reservedLabelSpans(0), 'The first gap must reserve its label channels');
        self::assertNotSame([], $graph->reservedLabelSpans(1), 'The second gap must reserve its label channels too');
    }

    private function placedNode(LayoutGraph $graph, string $id, int $layer, int $column, int $row = 0): RealLayoutNode
    {
        $node = new RealLayoutNode($id, new Node($id, 'N'));
        $node->layer = $layer;
        $node->row = $row;
        $node->column = $column;
        $graph->addNode($node);

        return $node;
    }

    private function connectLabeled(LayoutGraph $graph, string $sourceId, string $targetId, ?string $labelText): void
    {
        $edge = new Edge($sourceId, $targetId, label: null === $labelText ? null : new Label($labelText));
        $graph->addEdge(new LayoutEdge(edge: $edge));
        $graph->storeOriginalEdge($edge);
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
