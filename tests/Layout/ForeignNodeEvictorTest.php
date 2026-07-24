<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Graph;
use PhpDag\Graph\Group;
use PhpDag\Graph\Node;
use PhpDag\Layout\ForeignNodeEvictor;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\Processor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ForeignNodeEvictorTest extends TestCase
{
    #[Test]
    public function implementsProcessor(): void
    {
        self::assertInstanceOf(Processor::class, new ForeignNodeEvictor());
    }

    #[Test]
    public function evictsALeftLeaningTrappedNodeClearToTheLeftAndLeavesMembersInPlace(): void
    {
        // Members span columns 0..34 (band centre 17) across rows 0..8. The
        // single-glyph foreign box is 5 wide; sitting at column 8 its centre (10)
        // is left of centre, so it is pushed to a fresh lane left of the members:
        // column 0 - (5 + GAP 2) = -7. Members must not move.
        $layoutGraph = $this->trappedGraph(foreignColumn: 8);

        (new ForeignNodeEvictor())->process($layoutGraph);

        self::assertSame(-7, $layoutGraph->getLayoutNode('foreign')->column);
        self::assertSame(0, $layoutGraph->getLayoutNode('ml')->column);
        self::assertSame(30, $layoutGraph->getLayoutNode('mr')->column);
        self::assertSame(15, $layoutGraph->getLayoutNode('mm')->column);
    }

    #[Test]
    public function evictsARightLeaningTrappedNodeClearToTheRight(): void
    {
        // At column 25 the foreign centre (27) is right of centre, so it is
        // pushed past the members' right edge (34): column 34 + 1 + GAP 2 = 37.
        $layoutGraph = $this->trappedGraph(foreignColumn: 25);

        (new ForeignNodeEvictor())->process($layoutGraph);

        self::assertSame(37, $layoutGraph->getLayoutNode('foreign')->column);
    }

    #[Test]
    public function shiftsContentAlreadyLeftOfTheBandToReserveTheLane(): void
    {
        // 'other' already sits clear to the left (columns -12..-8). Evicting the
        // foreign node left must push 'other' further left by the lane width
        // (5 + GAP 2 = 7) so the new lane stays empty.
        $layoutGraph = $this->trappedGraph(foreignColumn: 8);
        $this->place($layoutGraph, 'other', row: 6, column: -12);

        (new ForeignNodeEvictor())->process($layoutGraph);

        self::assertSame(-19, $layoutGraph->getLayoutNode('other')->column);
        // The lane shift moves only along the cross axis: the row is untouched.
        self::assertSame(6, $layoutGraph->getLayoutNode('other')->row);
        self::assertSame(-7, $layoutGraph->getLayoutNode('foreign')->column);
    }

    #[Test]
    public function shiftsContentAlreadyRightOfTheBandToReserveTheLane(): void
    {
        // 'other' sits clear to the right (column 50); evicting the foreign node
        // right must push it further right by the lane width.
        $layoutGraph = $this->trappedGraph(foreignColumn: 25);
        $this->place($layoutGraph, 'other', row: 6, column: 50);

        (new ForeignNodeEvictor())->process($layoutGraph);

        self::assertSame(57, $layoutGraph->getLayoutNode('other')->column);
        self::assertSame(37, $layoutGraph->getLayoutNode('foreign')->column);
    }

    #[Test]
    public function leavesANodeWhoseRowDoesNotOverlapTheBandUntouched(): void
    {
        // 'other' sits in the members' columns but well below the band (row 40),
        // so it is not enclosed and must not move.
        $layoutGraph = $this->trappedGraph(foreignColumn: 8);
        $this->place($layoutGraph, 'other', row: 40, column: 15);

        (new ForeignNodeEvictor())->process($layoutGraph);

        self::assertSame([40, 15], $this->position($layoutGraph, 'other'));
    }

    #[Test]
    public function leavesANodeAlreadyClearOfTheBandColumnsUntouched(): void
    {
        // 'other' overlaps the rows but sits entirely right of the members
        // (columns 40..44), so it is already outside and must not move.
        $layoutGraph = $this->trappedGraph(foreignColumn: 8);
        $this->place($layoutGraph, 'other', row: 6, column: 40);

        (new ForeignNodeEvictor())->process($layoutGraph);

        self::assertSame([6, 40], $this->position($layoutGraph, 'other'));
    }

    #[Test]
    public function treatsTheLeftEdgeColumnAsInsideButNotBeyondTheBoxStart(): void
    {
        // A node ending exactly on the members' left column (column -4, width 5
        // -> right edge 0) overlaps by one and is evicted to its lane (-7); one
        // column earlier (column -5 -> right edge -1) it is fully clear and, as
        // the only trapped candidate, stays put — pinning the inclusive left edge
        // and the node-right extent.
        $onEdge = $this->trappedGraph(foreignColumn: -4);
        (new ForeignNodeEvictor())->process($onEdge);
        self::assertSame(-7, $onEdge->getLayoutNode('foreign')->column);

        $clear = $this->trappedGraph(foreignColumn: -5);
        (new ForeignNodeEvictor())->process($clear);
        self::assertSame(-5, $clear->getLayoutNode('foreign')->column);
    }

    #[Test]
    public function evictsEveryTrappedNodeNotJustTheFirst(): void
    {
        // Two non-members are trapped on the left; the loop must evict both, not
        // stop after the first.
        $layoutGraph = $this->trappedGraph(foreignColumn: 8);
        $this->place($layoutGraph, 'other', row: 4, column: 10);

        (new ForeignNodeEvictor())->process($layoutGraph);

        self::assertLessThanOrEqual(0, $layoutGraph->getLayoutNode('foreign')->column + 5);
        self::assertLessThanOrEqual(0, $layoutGraph->getLayoutNode('other')->column + 5);
    }

    #[Test]
    public function treatsTheBandBottomRowAsInsideButNotTheRowBelowIt(): void
    {
        // Members span rows 0..8 (mm bottom = 6 + 2). A node on row 8 overlaps and
        // is evicted; a node on row 9 clears the band and stays — pinning the
        // inclusive bottom edge and the member box height.
        $onEdge = $this->trappedGraph(foreignColumn: 8);
        $this->place($onEdge, 'foreign', row: 8, column: 8);
        (new ForeignNodeEvictor())->process($onEdge);
        self::assertSame(-7, $onEdge->getLayoutNode('foreign')->column);

        $below = $this->trappedGraph(foreignColumn: 8);
        $this->place($below, 'foreign', row: 9, column: 8);
        (new ForeignNodeEvictor())->process($below);
        self::assertSame(8, $below->getLayoutNode('foreign')->column);
    }

    #[Test]
    public function treatsTheBandTopRowAsInsideButNotTheRowAboveIt(): void
    {
        // A node whose box bottom just reaches the band top (row -2, height 3)
        // overlaps and is evicted; one row higher it clears the band and stays.
        $onEdge = $this->trappedGraph(foreignColumn: 8);
        $this->place($onEdge, 'foreign', row: -2, column: 8);
        (new ForeignNodeEvictor())->process($onEdge);
        self::assertSame(-7, $onEdge->getLayoutNode('foreign')->column);

        $above = $this->trappedGraph(foreignColumn: 8);
        $this->place($above, 'foreign', row: -3, column: 8);
        (new ForeignNodeEvictor())->process($above);
        self::assertSame(8, $above->getLayoutNode('foreign')->column);
    }

    #[Test]
    public function treatsTheRightEdgeColumnAsInsideButNotTheColumnBeyondTheBoxEnd(): void
    {
        // Members reach column 34 (mr at 30, width 5). A node starting on column
        // 34 overlaps by one and is evicted; starting on column 35 it clears the
        // band and stays — pinning the inclusive right edge.
        $onEdge = $this->trappedGraph(foreignColumn: 8);
        $this->place($onEdge, 'foreign', row: 6, column: 34);
        (new ForeignNodeEvictor())->process($onEdge);
        self::assertSame(37, $onEdge->getLayoutNode('foreign')->column);

        $beyond = $this->trappedGraph(foreignColumn: 8);
        $this->place($beyond, 'foreign', row: 6, column: 35);
        (new ForeignNodeEvictor())->process($beyond);
        self::assertSame(35, $beyond->getLayoutNode('foreign')->column);
    }

    #[Test]
    public function leavesABystanderSittingExactlyOnTheRightEdgeColumnUnshifted(): void
    {
        // During a rightward eviction only content strictly past the band's right
        // edge is shifted; a node parked on the edge column itself must stay.
        $layoutGraph = $this->trappedGraph(foreignColumn: 25);
        $this->place($layoutGraph, 'other', row: 100, column: 34);

        (new ForeignNodeEvictor())->process($layoutGraph);

        self::assertSame(34, $layoutGraph->getLayoutNode('other')->column);
    }

    #[Test]
    public function leavesGraphsWithoutGroupsUntouched(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'A'));
        $graph->addNode(new Node('b', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'a', row: 2, column: 9);
        $this->place($layoutGraph, 'b', row: 6, column: 3);

        (new ForeignNodeEvictor())->process($layoutGraph);

        self::assertSame([2, 9], $this->position($layoutGraph, 'a'));
        self::assertSame([6, 3], $this->position($layoutGraph, 'b'));
    }

    private function trappedGraph(int $foreignColumn): LayoutGraph
    {
        $graph = new Graph();
        foreach (['ml', 'mr', 'mm', 'foreign', 'other'] as $id) {
            $graph->addNode(new Node($id, 'N'));
        }
        $graph->addGroup(new Group('cluster', 'Cluster', ['ml', 'mr', 'mm']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'ml', row: 0, column: 0);
        $this->place($layoutGraph, 'mr', row: 0, column: 30);
        $this->place($layoutGraph, 'mm', row: 6, column: 15);
        $this->place($layoutGraph, 'foreign', row: 6, column: $foreignColumn);
        // Parked below the band and inside its columns (never trapped, never on
        // an eviction lane) unless a test repositions it.
        $this->place($layoutGraph, 'other', row: 100, column: 15);

        return $layoutGraph;
    }

    private function place(LayoutGraph $layoutGraph, string $nodeId, int $row, int $column): void
    {
        $node = $layoutGraph->getLayoutNode($nodeId);
        $node->row = $row;
        $node->column = $column;
    }

    /**
     * @return array{int, int} [row, column]
     */
    private function position(LayoutGraph $layoutGraph, string $nodeId): array
    {
        $node = $layoutGraph->getLayoutNode($nodeId);

        return [$node->row, $node->column];
    }
}
