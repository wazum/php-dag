<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Label;
use PhpDag\Graph\LabelPosition;
use PhpDag\Graph\Node;
use PhpDag\Layout\DummyNodeInserter;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LeftToRightLabelReserver;
use PhpDag\Layout\LeftToRightPositioning;
use PhpDag\Layout\LongestPathLayering;
use PhpDag\Layout\Processor;
use PhpDag\Layout\RealLayoutNode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LeftToRightLabelReserverTest extends TestCase
{
    #[Test]
    public function implementsProcessor(): void
    {
        self::assertInstanceOf(Processor::class, new LeftToRightLabelReserver());
    }

    #[Test]
    public function doesNothingWhenNoLabels(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta')],
            edges: [new Edge('A', 'B')],
        );

        $columnBefore = $graph->getLayoutNode('B')->column;
        (new LeftToRightLabelReserver())->process($graph);
        $columnAfter = $graph->getLayoutNode('B')->column;

        self::assertSame($columnBefore, $columnAfter, 'Columns must not change when no labels present');
    }

    #[Test]
    public function insertsColumnGapForLabeledEdge(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'Alpha'), new Node('B', 'Beta')],
            edges: [new Edge('A', 'B', label: new Label('yes'))],
        );

        $columnBefore = $graph->getLayoutNode('B')->column;
        (new LeftToRightLabelReserver())->process($graph);
        $columnAfter = $graph->getLayoutNode('B')->column;

        self::assertGreaterThan($columnBefore, $columnAfter, 'Target column must shift right to make space for label');
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

        $columnBBefore = $graph->getLayoutNode('B')->column;
        $columnCBefore = $graph->getLayoutNode('C')->column;

        (new LeftToRightLabelReserver())->process($graph);

        $columnBAfter = $graph->getLayoutNode('B')->column;
        $columnCAfter = $graph->getLayoutNode('C')->column;

        self::assertSame($columnBBefore + 1, $columnBAfter, 'B shifts by 1 (label in gap 0)');
        self::assertSame($columnCBefore + 2, $columnCAfter, 'C shifts by 2 (cumulative: gap 0 + gap 1)');
    }

    #[Test]
    public function shiftsForSourcePositionLabel(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'A'), new Node('B', 'B')],
            edges: [new Edge('A', 'B', label: new Label('src', LabelPosition::Source))],
        );

        $columnBefore = $graph->getLayoutNode('B')->column;
        (new LeftToRightLabelReserver())->process($graph);

        self::assertSame($columnBefore + 1, $graph->getLayoutNode('B')->column, 'B shifts right for Source-positioned label');
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

        $columnBBefore = $graph->getLayoutNode('B')->column;
        $columnCBefore = $graph->getLayoutNode('C')->column;

        (new LeftToRightLabelReserver())->process($graph);

        self::assertSame($columnBBefore, $graph->getLayoutNode('B')->column, 'B must not shift (label is in gap before C)');
        self::assertSame($columnCBefore + 1, $graph->getLayoutNode('C')->column, 'C shifts for Target-positioned label in gap 1');
    }

    #[Test]
    public function shiftsForMiddlePositionLabelOnLongEdge(): void
    {
        $graph = $this->buildPositionedGraph(
            nodes: [new Node('A', 'A'), new Node('B', 'B'), new Node('C', 'C')],
            edges: [
                new Edge('A', 'B'),
                new Edge('B', 'C'),
                new Edge('A', 'C', label: new Label('mid', LabelPosition::Middle)),
            ],
        );

        $columnBBefore = $graph->getLayoutNode('B')->column;
        $columnCBefore = $graph->getLayoutNode('C')->column;

        (new LeftToRightLabelReserver())->process($graph);

        self::assertSame($columnBBefore, $graph->getLayoutNode('B')->column, 'B must not shift (label is in gap 1, between layers 1 and 2)');
        self::assertSame($columnCBefore + 1, $graph->getLayoutNode('C')->column, 'C shifts for Middle label in gap intdiv(0+2,2)=1');
    }

    #[Test]
    public function tallTargetUsesItsTrueCenterDecidingUpwardMargin(): void
    {
        // Source center sits at row 3; the tall target's box spans rows 0-5
        // (true center 3), so the edge is level, not upward — no top margin is
        // reserved. A mistaken center (e.g. height/3 = 2) would read it as
        // upward and shift the layout down.
        $graph = new LayoutGraph();

        $source = new RealLayoutNode('S', new Node('S', 'S'));
        $source->layer = 0;
        $source->row = 2;
        $source->column = 0;
        $graph->addNode($source);

        $target = new RealLayoutNode('T', new Node('T', 'T', ['b1', 'b2', 'b3']));
        $target->layer = 1;
        $target->row = 0;
        $target->column = 10;
        $graph->addNode($target);
        self::assertSame(6, $target->boxHeight(), 'Precondition: tall target is 6 rows');

        $labeledEdge = new Edge('S', 'T', label: new Label('go'));
        $graph->addEdge(new LayoutEdge(edge: $labeledEdge));
        $graph->storeOriginalEdge($labeledEdge);
        $graph->buildLayerIndex();

        (new LeftToRightLabelReserver())->process($graph);

        self::assertSame(0, $target->row, 'Level edge into a tall target reserves no top margin');
    }

    #[Test]
    public function ensuresTopBoundaryMargin(): void
    {
        $graph = new LayoutGraph();

        $nodeA = new RealLayoutNode('A', new Node('A', 'A'));
        $nodeA->layer = 0;
        $nodeA->row = 3;
        $nodeA->column = 0;
        $graph->addNode($nodeA);

        $nodeB = new RealLayoutNode('B', new Node('B', 'B'));
        $nodeB->layer = 1;
        $nodeB->row = 0;
        $nodeB->column = 10;
        $graph->addNode($nodeB);

        $labeledEdge = new Edge('A', 'B', label: new Label('longlabel'));
        $graph->addEdge(new LayoutEdge(edge: $labeledEdge));
        $graph->storeOriginalEdge($labeledEdge);
        $graph->buildLayerIndex();

        $rowABefore = $nodeA->row;
        $rowBBefore = $nodeB->row;

        (new LeftToRightLabelReserver())->process($graph);

        self::assertGreaterThan($rowABefore, $nodeA->row, 'A must shift down to make room for label above B');
        self::assertGreaterThan($rowBBefore, $nodeB->row, 'B must shift down to make room for label above B');
    }

    #[Test]
    public function topBoundaryMarginIsOneRowRegardlessOfLabelWidth(): void
    {
        $graph = new LayoutGraph();

        $sourceNode = new RealLayoutNode('A', new Node('A', 'A'));
        $sourceNode->layer = 0;
        $sourceNode->row = 3;
        $sourceNode->column = 0;
        $graph->addNode($sourceNode);

        $targetNode = new RealLayoutNode('B', new Node('B', 'B'));
        $targetNode->layer = 1;
        $targetNode->row = 0;
        $targetNode->column = 10;
        $graph->addNode($targetNode);

        $labeledEdge = new Edge('A', 'B', label: new Label('a-very-long-release-label'));
        $graph->addEdge(new LayoutEdge(edge: $labeledEdge));
        $graph->storeOriginalEdge($labeledEdge);
        $graph->buildLayerIndex();

        (new LeftToRightLabelReserver())->process($graph);

        self::assertSame(1, $targetNode->row, 'Labels occupy one row; the top margin must be one row, not the label width');
    }

    #[Test]
    public function keepsRowsForHorizontalLabeledEdge(): void
    {
        $graph = new LayoutGraph();

        $nodeA = new RealLayoutNode('A', new Node('A', 'A'));
        $nodeA->layer = 0;
        $nodeA->row = 0;
        $nodeA->column = 0;
        $graph->addNode($nodeA);

        $nodeB = new RealLayoutNode('B', new Node('B', 'B'));
        $nodeB->layer = 1;
        $nodeB->row = 0;
        $nodeB->column = 10;
        $graph->addNode($nodeB);

        $labeledEdge = new Edge('A', 'B', label: new Label('yes'));
        $graph->addEdge(new LayoutEdge(edge: $labeledEdge));
        $graph->storeOriginalEdge($labeledEdge);
        $graph->buildLayerIndex();

        (new LeftToRightLabelReserver())->process($graph);

        self::assertSame(0, $nodeA->row, 'A horizontal edge needs no top margin, so rows must stay untouched');
        self::assertSame(0, $nodeB->row, 'A horizontal edge needs no top margin, so rows must stay untouched');
    }

    #[Test]
    public function appliesExactTopMarginEvenWhenUnlabeledEdgeComesFirst(): void
    {
        $graph = new LayoutGraph();

        $nodeA = new RealLayoutNode('A', new Node('A', 'A'));
        $nodeA->layer = 0;
        $nodeA->row = 3;
        $nodeA->column = 0;
        $graph->addNode($nodeA);

        $nodeB = new RealLayoutNode('B', new Node('B', 'B'));
        $nodeB->layer = 1;
        $nodeB->row = 0;
        $nodeB->column = 10;
        $graph->addNode($nodeB);

        $nodeC = new RealLayoutNode('C', new Node('C', 'C'));
        $nodeC->layer = 1;
        $nodeC->row = 20;
        $nodeC->column = 10;
        $graph->addNode($nodeC);

        $unlabeledEdge = new Edge('A', 'C');
        $labeledEdge = new Edge('A', 'B', label: new Label('longlabel'));
        $graph->addEdge(new LayoutEdge(edge: $unlabeledEdge));
        $graph->addEdge(new LayoutEdge(edge: $labeledEdge));
        $graph->storeOriginalEdge($unlabeledEdge);
        $graph->storeOriginalEdge($labeledEdge);
        $graph->buildLayerIndex();

        (new LeftToRightLabelReserver())->process($graph);

        self::assertSame(4, $nodeA->row, 'A shifts down by exactly 1: the single label row above B (top row 0) needs row -1');
        self::assertSame(1, $nodeB->row, 'B shifts down by the same amount; an unlabeled edge before the labeled one must not stop the scan');
    }

    #[Test]
    public function keepsRowsWhenTargetCenterSitsJustBelowSourceCenter(): void
    {
        $graph = new LayoutGraph();

        $nodeA = new RealLayoutNode('A', new Node('A', 'A'));
        $nodeA->layer = 0;
        $nodeA->row = 0;
        $nodeA->column = 0;
        $graph->addNode($nodeA);

        $nodeB = new RealLayoutNode('B', new Node('B', 'B'));
        $nodeB->layer = 1;
        $nodeB->row = 1;
        $nodeB->column = 10;
        $graph->addNode($nodeB);

        $labeledEdge = new Edge('A', 'B', label: new Label('no'));
        $graph->addEdge(new LayoutEdge(edge: $labeledEdge));
        $graph->storeOriginalEdge($labeledEdge);
        $graph->buildLayerIndex();

        (new LeftToRightLabelReserver())->process($graph);

        self::assertSame(0, $nodeA->row, 'targetCenter(2) is below sourceCenter(1): a downward edge needs no top margin');
        self::assertSame(1, $nodeB->row, 'targetCenter(2) is below sourceCenter(1): a downward edge needs no top margin');
    }

    #[Test]
    public function topMarginUsesTrueBoxCenterOfTallSourceNode(): void
    {
        $graph = new LayoutGraph();

        $nodeA = new RealLayoutNode('A', new Node('A', 'A', ['body line']));
        $nodeA->layer = 0;
        $nodeA->row = 0;
        $nodeA->column = 0;
        $graph->addNode($nodeA);

        $nodeB = new RealLayoutNode('B', new Node('B', 'B'));
        $nodeB->layer = 1;
        $nodeB->row = 0;
        $nodeB->column = 10;
        $graph->addNode($nodeB);

        $labeledEdge = new Edge('A', 'B', label: new Label('no'));
        $graph->addEdge(new LayoutEdge(edge: $labeledEdge));
        $graph->storeOriginalEdge($labeledEdge);
        $graph->buildLayerIndex();

        (new LeftToRightLabelReserver())->process($graph);

        self::assertSame(1, $nodeA->row, 'targetCenter(1) is above the tall source box center(2): the label row above B (top row 0) needs row -1, so shift by 1');
        self::assertSame(1, $nodeB->row, 'B shifts down by the same amount');
    }

    #[Test]
    public function topMarginUsesTrueBoxCenterOfTallTargetNode(): void
    {
        $graph = new LayoutGraph();

        $nodeA = new RealLayoutNode('A', new Node('A', 'A'));
        $nodeA->layer = 0;
        $nodeA->row = 2;
        $nodeA->column = 0;
        $graph->addNode($nodeA);

        $nodeB = new RealLayoutNode('B', new Node('B', 'B', ['body line']));
        $nodeB->layer = 1;
        $nodeB->row = 0;
        $nodeB->column = 10;
        $graph->addNode($nodeB);

        $labeledEdge = new Edge('A', 'B', label: new Label('no'));
        $graph->addEdge(new LayoutEdge(edge: $labeledEdge));
        $graph->storeOriginalEdge($labeledEdge);
        $graph->buildLayerIndex();

        (new LeftToRightLabelReserver())->process($graph);

        self::assertSame(3, $nodeA->row, 'tall target box center(2) is above sourceCenter(3): shift down by targetCenter - width(2) - 1 = -1');
        self::assertSame(1, $nodeB->row, 'B shifts down by the same amount');
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
        (new LeftToRightPositioning())->position($layoutGraph);

        return $layoutGraph;
    }
}
