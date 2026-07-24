<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Graph;
use PhpDag\Graph\Group;
use PhpDag\Graph\Node;
use PhpDag\Layout\GroupSpacer;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\Processor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GroupSpacerTest extends TestCase
{
    #[Test]
    public function implementsProcessor(): void
    {
        self::assertInstanceOf(Processor::class, new GroupSpacer());
    }

    #[Test]
    public function reservesMarginRingWithoutNormalizingWhenTheGroupIsClearOfTheCanvasEdge(): void
    {
        // Node('M', 'M') renders a 5-wide x 3-tall box. Placing the member at
        // (10, 10) gives it a bounding box of rows 10..12 and columns 10..14,
        // far enough from the origin that no normalization shift is needed.
        $graph = new Graph();
        $graph->addNode(new Node('member', 'M'));
        $graph->addNode(new Node('below', 'X'));
        $graph->addNode(new Node('right', 'X'));
        $graph->addGroup(new Group('cluster', 'G', ['member']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'member', row: 10, column: 10);
        $this->place($layoutGraph, 'below', row: 30, column: 10);
        $this->place($layoutGraph, 'right', row: 10, column: 30);

        (new GroupSpacer())->process($layoutGraph);

        // MARGIN = 2. The member moves down/right by one MARGIN to open the top
        // and left gap; nodes past the bottom/right edge move by two MARGINs.
        // No coordinate dips below MARGIN, so normalization is a no-op.
        self::assertSame([12, 12], $this->position($layoutGraph, 'member'));
        self::assertSame([34, 12], $this->position($layoutGraph, 'below'));
        self::assertSame([12, 34], $this->position($layoutGraph, 'right'));
    }

    #[Test]
    public function normalizesNegativeCoordinatesUpToTheMargin(): void
    {
        // The 'above' node sits above and left of the member block, so the ring
        // shifts never touch it; after spacing its row/column stay negative and
        // normalization must lift the whole graph so it clears the margin.
        $graph = new Graph();
        $graph->addNode(new Node('member', 'M'));
        $graph->addNode(new Node('above', 'X'));
        $graph->addGroup(new Group('cluster', 'G', ['member']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'member', row: 5, column: 5);
        $this->place($layoutGraph, 'above', row: -1, column: -1);

        (new GroupSpacer())->process($layoutGraph);

        // member: ring shifts (5->7, 5->7); 'above' untouched at (-1, -1).
        // minRow = minColumn = -1, so normalization adds MARGIN - (-1) = 3.
        self::assertSame([10, 10], $this->position($layoutGraph, 'member'));
        self::assertSame([2, 2], $this->position($layoutGraph, 'above'));
    }

    #[Test]
    public function normalizesRowsIndependentlyOfColumns(): void
    {
        // Only the row coordinate needs lifting (the 'above' node is negative in
        // row but well to the right in column), so rowShift > 0 while
        // columnShift == 0. This pins each branch of the early-return guard.
        $graph = new Graph();
        $graph->addNode(new Node('member', 'M'));
        $graph->addNode(new Node('above', 'X'));
        $graph->addGroup(new Group('cluster', 'G', ['member']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'member', row: 5, column: 5);
        $this->place($layoutGraph, 'above', row: -1, column: 20);

        (new GroupSpacer())->process($layoutGraph);

        self::assertSame([10, 7], $this->position($layoutGraph, 'member'));
        self::assertSame([2, 24], $this->position($layoutGraph, 'above'));
    }

    #[Test]
    public function normalizesColumnsIndependentlyOfRows(): void
    {
        // Mirror of the previous case: only the column coordinate needs lifting,
        // so columnShift > 0 while rowShift == 0.
        $graph = new Graph();
        $graph->addNode(new Node('member', 'M'));
        $graph->addNode(new Node('left', 'X'));
        $graph->addGroup(new Group('cluster', 'G', ['member']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'member', row: 5, column: 5);
        $this->place($layoutGraph, 'left', row: 20, column: -1);

        (new GroupSpacer())->process($layoutGraph);

        self::assertSame([7, 10], $this->position($layoutGraph, 'member'));
        self::assertSame([24, 2], $this->position($layoutGraph, 'left'));
    }

    #[Test]
    public function skipsGroupsWhoseMembersAreAbsentFromTheLayoutGraph(): void
    {
        // A group can reference a node that later dropped out of the layout
        // graph. memberBounds must report "no members" so the group is skipped
        // rather than producing a bounding box from the PHP_INT sentinels.
        $graph = new Graph();
        $graph->addNode(new Node('member', 'M'));
        $graph->addNode(new Node('keep', 'X'));
        $graph->addGroup(new Group('cluster', 'G', ['member']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'keep', row: 5, column: 5);
        $layoutGraph->removeNodes(['member']);

        (new GroupSpacer())->process($layoutGraph);

        self::assertSame([5, 5], $this->position($layoutGraph, 'keep'));
    }

    #[Test]
    public function continuesPastAGroupWithNoMembersToSpaceLaterGroups(): void
    {
        // First group's only member is gone, so it yields no bounds; the loop
        // must move on and still space the second group's member.
        $graph = new Graph();
        $graph->addNode(new Node('ghost', 'X'));
        $graph->addNode(new Node('member', 'M'));
        $graph->addNode(new Node('below', 'X'));
        $graph->addGroup(new Group('empty', 'Empty', ['ghost']));
        $graph->addGroup(new Group('cluster', 'G', ['member']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'member', row: 10, column: 10);
        $this->place($layoutGraph, 'below', row: 30, column: 10);
        $layoutGraph->removeNodes(['ghost']);

        (new GroupSpacer())->process($layoutGraph);

        self::assertSame([12, 12], $this->position($layoutGraph, 'member'));
        self::assertSame([34, 12], $this->position($layoutGraph, 'below'));
    }

    #[Test]
    public function skipsAbsentMembersWhileStillMeasuringPresentOnes(): void
    {
        // The absent member is listed before the present one. Measuring must
        // skip the absent id and keep scanning, not abandon the whole group.
        $graph = new Graph();
        $graph->addNode(new Node('ghost', 'X'));
        $graph->addNode(new Node('member', 'M'));
        $graph->addNode(new Node('below', 'X'));
        $graph->addGroup(new Group('cluster', 'G', ['ghost', 'member']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'member', row: 10, column: 10);
        $this->place($layoutGraph, 'below', row: 30, column: 10);
        $layoutGraph->removeNodes(['ghost']);

        (new GroupSpacer())->process($layoutGraph);

        self::assertSame([12, 12], $this->position($layoutGraph, 'member'));
        self::assertSame([34, 12], $this->position($layoutGraph, 'below'));
    }

    #[Test]
    public function leavesCoordinatesUntouchedWhenThereAreNoGroups(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'A'));
        $graph->addNode(new Node('b', 'B'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'a', row: 3, column: 7);
        $this->place($layoutGraph, 'b', row: 11, column: 2);

        (new GroupSpacer())->process($layoutGraph);

        self::assertSame([3, 7], $this->position($layoutGraph, 'a'));
        self::assertSame([11, 2], $this->position($layoutGraph, 'b'));
    }

    #[Test]
    public function reservesOneSharedBorderRingForGroupsSpanningTheSameRows(): void
    {
        // Two side-by-side groups occupy the same rows. The border ring above and
        // below must be reserved once for the shared band, not once per group, so
        // members shift by a single MARGIN and a node below them by two — never
        // double that.
        $graph = new Graph();
        foreach (['p1', 'p2', 'q1', 'q2', 'below'] as $id) {
            $graph->addNode(new Node($id, 'N'));
        }
        $graph->addGroup(new Group('p', 'p', ['p1', 'p2']));
        $graph->addGroup(new Group('q', 'q', ['q1', 'q2']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'p1', row: 0, column: 0);
        $this->place($layoutGraph, 'q1', row: 0, column: 20);
        $this->place($layoutGraph, 'p2', row: 4, column: 0);
        $this->place($layoutGraph, 'q2', row: 4, column: 20);
        $this->place($layoutGraph, 'below', row: 10, column: 10);

        (new GroupSpacer())->process($layoutGraph);

        // Top members shift by one MARGIN (the single shared top ring); the node
        // below shifts by two (shared top + shared bottom ring).
        self::assertSame(2, $layoutGraph->getLayoutNode('p1')->row);
        self::assertSame(2, $layoutGraph->getLayoutNode('q1')->row);
        self::assertSame(14, $layoutGraph->getLayoutNode('below')->row);
    }

    #[Test]
    public function widensLeftPaddingWhenTheLabelCannotFitClearOfTheEntryCrossing(): void
    {
        // Member 'M' is a 5-wide box, so an incoming edge's crossing lands at
        // its centre and a 7-glyph label cannot fit between the border and that
        // crossing at the bare MARGIN ring. GroupSpacer must reserve extra left
        // padding (and record it) so the border can grow leftwards to hold the
        // whole label, shifting the member right by that wider padding.
        $graph = new Graph();
        $graph->addNode(new Node('member', 'M'));
        $graph->addGroup(new Group('cluster', 'Cluster', ['member']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'member', row: 10, column: 10);

        (new GroupSpacer())->process($layoutGraph);

        self::assertSame(9, $layoutGraph->groupLeftPadding('cluster'));
        self::assertSame([12, 19], $this->position($layoutGraph, 'member'));
    }

    #[Test]
    public function widensByExactlyTheLabelOverflowPastTheEntryGap(): void
    {
        // Member 'M' is 5 wide; its centre crossing leaves a 3-column gap on each
        // side. A 2-glyph label needs 4 columns, one past the gap, so the left
        // padding widens from the ring (2) to 4. Mis-measuring the gap by one
        // either flips this to no widening or over-widens it.
        $graph = new Graph();
        $graph->addNode(new Node('member', 'M'));
        $graph->addGroup(new Group('cluster', 'AB', ['member']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'member', row: 10, column: 10);

        (new GroupSpacer())->process($layoutGraph);

        self::assertSame(4, $layoutGraph->groupLeftPadding('cluster'));
    }

    #[Test]
    public function measuresTheLabelWidthInGlyphsNotBytes(): void
    {
        // 'é' is two bytes but one glyph, so its label needs 3 columns — exactly
        // the entry gap — and must not trigger any widening.
        $graph = new Graph();
        $graph->addNode(new Node('member', 'M'));
        $graph->addGroup(new Group('cluster', 'é', ['member']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'member', row: 10, column: 10);

        (new GroupSpacer())->process($layoutGraph);

        self::assertSame(2, $layoutGraph->groupLeftPadding('cluster'));
    }

    #[Test]
    public function keepsTheRingWhenTheLabelFitsTheGapBetweenTwoCrossings(): void
    {
        // Two members leave a wide crossing-free gap between their centres; a
        // short label fits there, so no widening is needed.
        $graph = new Graph();
        $graph->addNode(new Node('m1', 'M'));
        $graph->addNode(new Node('m2', 'M'));
        $graph->addGroup(new Group('cluster', 'Wide', ['m1', 'm2']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'm1', row: 0, column: 0);
        $this->place($layoutGraph, 'm2', row: 0, column: 20);

        (new GroupSpacer())->process($layoutGraph);

        self::assertSame(2, $layoutGraph->groupLeftPadding('cluster'));
    }

    #[Test]
    public function widensFromAPresentMemberEvenWhenAnEarlierMemberIsAbsent(): void
    {
        // The absent member is listed first; measuring must skip it and still
        // pick up the present member's crossing, or it would see no crossings and
        // skip widening entirely.
        $graph = new Graph();
        $graph->addNode(new Node('ghost', 'X'));
        $graph->addNode(new Node('member', 'M'));
        $graph->addGroup(new Group('cluster', 'Cluster', ['ghost', 'member']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'member', row: 10, column: 10);
        $layoutGraph->removeNodes(['ghost']);

        (new GroupSpacer())->process($layoutGraph);

        self::assertSame(9, $layoutGraph->groupLeftPadding('cluster'));
    }

    #[Test]
    public function sortsCrossingsBeforeMeasuringTheWidestGap(): void
    {
        // Members are declared right-first, so their centre crossings arrive out
        // of column order. Only sorting them yields the true widest gap (19),
        // which is below the long label (20) and triggers widening; left
        // unsorted the gap measures 22 and widening would be skipped.
        $graph = new Graph();
        $graph->addNode(new Node('ml', 'M'));
        $graph->addNode(new Node('mr', 'M'));
        $graph->addGroup(new Group('cluster', str_repeat('A', 18), ['mr', 'ml']));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->place($layoutGraph, 'ml', row: 0, column: 0);
        $this->place($layoutGraph, 'mr', row: 0, column: 20);

        (new GroupSpacer())->process($layoutGraph);

        self::assertSame(20, $layoutGraph->groupLeftPadding('cluster'));
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
