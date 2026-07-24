<?php

declare(strict_types=1);

namespace PhpDag\Tests;

use PhpDag\AsciiDag;
use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Group;
use PhpDag\Graph\Label;
use PhpDag\Graph\Node;
use PhpDag\Layout\LayoutQuality;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AsciiDagTest extends TestCase
{
    #[Test]
    public function rendersLinearChain(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));

        $result = AsciiDag::default()->render($graph);

        self::assertStringContainsString('Start', $result);
        self::assertStringContainsString('End', $result);
        self::assertStringContainsString('│', $result);
    }

    #[Test]
    public function rendersCyclicGraphWithNumericStringIdsIdenticallyToAlphabeticIds(): void
    {
        $numeric = new Graph();
        $numeric->addNode(new Node('0', 'Zero'));
        $numeric->addNode(new Node('1', 'One'));
        $numeric->addNode(new Node('2', 'Two'));
        $numeric->addEdge(new Edge('0', '1'));
        $numeric->addEdge(new Edge('1', '2'));
        $numeric->addEdge(new Edge('2', '0'));

        $alphabetic = new Graph();
        $alphabetic->addNode(new Node('a', 'Zero'));
        $alphabetic->addNode(new Node('b', 'One'));
        $alphabetic->addNode(new Node('c', 'Two'));
        $alphabetic->addEdge(new Edge('a', 'b'));
        $alphabetic->addEdge(new Edge('b', 'c'));
        $alphabetic->addEdge(new Edge('c', 'a'));

        self::assertSame(
            AsciiDag::default()->render($alphabetic),
            AsciiDag::default()->render($numeric),
        );
    }

    #[Test]
    public function rendersBranchingGraphWithLongEdgeIdenticallyForNumericAndAlphabeticIds(): void
    {
        $numeric = new Graph();
        $numeric->addNode(new Node('0', 'Root'));
        $numeric->addNode(new Node('1', 'Left'));
        $numeric->addNode(new Node('2', 'Right'));
        $numeric->addNode(new Node('3', 'Sink'));
        $numeric->addEdge(new Edge('0', '1'));
        $numeric->addEdge(new Edge('0', '2'));
        $numeric->addEdge(new Edge('1', '3'));
        $numeric->addEdge(new Edge('2', '3'));
        $numeric->addEdge(new Edge('0', '3'));

        $alphabetic = new Graph();
        $alphabetic->addNode(new Node('a', 'Root'));
        $alphabetic->addNode(new Node('b', 'Left'));
        $alphabetic->addNode(new Node('c', 'Right'));
        $alphabetic->addNode(new Node('d', 'Sink'));
        $alphabetic->addEdge(new Edge('a', 'b'));
        $alphabetic->addEdge(new Edge('a', 'c'));
        $alphabetic->addEdge(new Edge('b', 'd'));
        $alphabetic->addEdge(new Edge('c', 'd'));
        $alphabetic->addEdge(new Edge('a', 'd'));

        self::assertSame(
            AsciiDag::default()->render($alphabetic),
            AsciiDag::default()->render($numeric),
        );
    }

    #[Test]
    public function rendersDiamondGraph(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Root'));
        $graph->addNode(new Node('B', 'Left'));
        $graph->addNode(new Node('C', 'Right'));
        $graph->addNode(new Node('D', 'Sink'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('B', 'D'));
        $graph->addEdge(new Edge('C', 'D'));

        $result = AsciiDag::default()->render($graph);

        self::assertStringContainsString('Root', $result);
        self::assertStringContainsString('Left', $result);
        self::assertStringContainsString('Right', $result);
        self::assertStringContainsString('Sink', $result);
    }

    #[Test]
    public function rendersLinearChainWithBoxEdgeConnection(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
        ╭───────╮
        │ Start │
        ╰───┬───╯
            │
            ▼
         ╭──┴──╮
         │ End │
         ╰─────╯
        EXPECTED;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function rendersLongEdgeWithConnectionGlyphs(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Top'));
        $graph->addNode(new Node('B', 'Middle'));
        $graph->addNode(new Node('C', 'Bottom'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('A', 'C'));

        $result = AsciiDag::default()->render($graph);

        $lines = explode("\n", $result);
        $middleBoxBottomLine = $lines[8];
        self::assertStringContainsString('┬', $middleBoxBottomLine, 'Middle box bottom border must have ┬ connection');

        $edgeMergeLine = $lines[10];
        self::assertStringContainsString('└', $edgeMergeLine, 'Edge merge channel must show └ connecting up toward the box');
    }

    #[Test]
    public function rendersLinearChainWithEntryAndExitConnections(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
        ╭───────╮
        │ Start │
        ╰───┬───╯
            │
            ▼
         ╭──┴──╮
         │ End │
         ╰─────╯
        EXPECTED;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function rendersParallelEdgesAsSeparateLanes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'A'));
        $graph->addNode(new Node('b', 'B'));
        $graph->addEdge(new Edge('a', 'b'));
        $graph->addEdge(new Edge('a', 'b'));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
        ╭───╮
        │ A │
        ╰┬─┬╯
         │ │
         ▼ ▼
        ╭┴─┴╮
        │ B │
        ╰───╯
        EXPECTED;

        self::assertSame($expected, $result, sprintf("Two parallel edges must render as two separate lanes:\n%s", $result));
    }

    #[Test]
    public function rendersParallelEdgesAsStraightLanesBetweenDifferentWidthNodes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('review', 'Review'));
        $graph->addNode(new Node('ship', 'Ship'));
        $graph->addEdge(new Edge('review', 'ship'));
        $graph->addEdge(new Edge('review', 'ship'));

        $result = AsciiDag::default()->render($graph);

        // The boxes differ in width but share a centre column, so each lane sits
        // on a column valid for both boxes and drops straight down — no bends.
        $expected = <<<'EXPECTED'
        ╭────────╮
        │ Review │
        ╰─┬────┬─╯
          │    │
          ▼    ▼
         ╭┴────┴╮
         │ Ship │
         ╰──────╯
        EXPECTED;

        self::assertSame($expected, $result, sprintf("Parallel edges between different-width nodes must be straight:\n%s", $result));
    }

    #[Test]
    public function rendersParallelEdgesAsStraightLanesWhenTheSourceIsNarrower(): void
    {
        // Mirror of the previous case: the narrower box is now the source, so its
        // inner span binds the shared lane columns. Lanes stay straight.
        $graph = new Graph();
        $graph->addNode(new Node('ship', 'Ship'));
        $graph->addNode(new Node('review', 'Review'));
        $graph->addEdge(new Edge('ship', 'review'));
        $graph->addEdge(new Edge('ship', 'review'));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
         ╭──────╮
         │ Ship │
         ╰┬────┬╯
          │    │
          ▼    ▼
        ╭─┴────┴─╮
        │ Review │
        ╰────────╯
        EXPECTED;

        self::assertSame($expected, $result, sprintf("Parallel edges with a narrower source must be straight:\n%s", $result));
    }

    #[Test]
    public function leftToRightParallelEdgesGrowBoxesAndRenderAsStraightLanes(): void
    {
        // ELK-style: in left-to-right flow a single-line box is too short to host
        // two ports, so both boxes grow to height 5 and each parallel edge gets
        // its own straight horizontal lane.
        $graph = new Graph();
        $graph->addNode(new Node('review', 'Review'));
        $graph->addNode(new Node('ship', 'Ship'));
        $graph->addEdge(new Edge('review', 'ship'));
        $graph->addEdge(new Edge('review', 'ship'));

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        $expected = <<<'EXPECTED'
        ╭────────╮  ╭──────╮
        │        ├─▶┤      │
        │ Review │  │ Ship │
        │        ├─▶┤      │
        ╰────────╯  ╰──────╯
        EXPECTED;

        self::assertSame($expected, $result, sprintf("Left-to-right parallel edges must grow boxes and run straight:\n%s", $result));
    }

    #[Test]
    public function leftToRightHandlesTwoConsecutiveParallelGroups(): void
    {
        // Two parallel groups in a row (A⇉B and B⇉C): both must keep their own
        // straight lanes, not just the first.
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('B', 'C'));

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        $expected = <<<'EXPECTED'
        ╭───╮  ╭───╮  ╭───╮
        │   ├─▶┤   ├─▶┤   │
        │ A │  │ B │  │ C │
        │   ├─▶┤   ├─▶┤   │
        ╰───╯  ╰───╯  ╰───╯
        EXPECTED;

        self::assertSame($expected, $result, sprintf("Two consecutive parallel groups must each stay straight:\n%s", $result));
    }

    #[Test]
    public function rendersASelfLoopAsALoopBesideTheNode(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addEdge(new Edge('A', 'A'));

        $result = AsciiDag::default()->render($graph);
        $lines = explode("\n", rtrim($result));

        self::assertGreaterThan(3, count($lines), sprintf("A self-loop must draw a loop beside its node:\n%s", $result));
    }

    #[Test]
    public function rendersASelfLoopLabelBesideTheLoop(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addEdge(new Edge('A', 'A', label: new Label('retry')));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
        ╭───╮
        │ A ├◀┐ retry
        ╰─┬─╯ │
          └───┘
        EXPECTED;

        self::assertSame($expected, $result, sprintf("A self-loop's label must render beside the loop:\n%s", $result));
    }

    #[Test]
    public function topToBottomParallelEdgeLabelsSitOnTheSideOfTheirLane(): void
    {
        // Each parallel lane's label splays out to the side the lane is on: the
        // left lane's label to the left of the box, the right lane's to the right.
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B', label: new Label('yes')));
        $graph->addEdge(new Edge('A', 'B', label: new Label('no')));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
           ╭───╮
           │ A │
           ╰┬─┬╯
            │ │
        yes │ │ no
            ▼ ▼
           ╭┴─┴╮
           │ B │
           ╰───╯
        EXPECTED;

        self::assertSame($expected, $result, sprintf("Parallel-edge labels must splay to the side of their lane:\n%s", $result));
    }

    #[Test]
    public function leftToRightParallelEdgeLabelsSitAboveAndBelowTheirLane(): void
    {
        // The top lane's label goes above the boxes, the bottom lane's below —
        // each on the side its horizontal lane sits.
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'B', label: new Label('yes')));
        $graph->addEdge(new Edge('A', 'B', label: new Label('no')));

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        $expected = <<<'EXPECTED'
             yes
        ╭───╮   ╭───╮
        │   ├──▶┤   │
        │ A │   │ B │
        │   ├──▶┤   │
        ╰───╯   ╰───╯
             no
        EXPECTED;

        self::assertSame($expected, $result, sprintf("Left-to-right parallel-edge labels must sit above/below their lane:\n%s", $result));
    }

    #[Test]
    public function leftToRightLabelsTwoParallelGroupsAboveAndBelow(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addEdge(new Edge('A', 'B', label: new Label('p')));
        $graph->addEdge(new Edge('A', 'B', label: new Label('q')));
        $graph->addEdge(new Edge('B', 'C', label: new Label('r')));
        $graph->addEdge(new Edge('B', 'C', label: new Label('s')));

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        $expected = <<<'EXPECTED'
             p       r
        ╭───╮   ╭───╮   ╭───╮
        │   ├──▶┤   ├──▶┤   │
        │ A │   │ B │   │ C │
        │   ├──▶┤   ├──▶┤   │
        ╰───╯   ╰───╯   ╰───╯
             q       s
        EXPECTED;

        self::assertSame($expected, $result, sprintf("Each parallel group's labels must sit on its own lanes:\n%s", $result));
    }

    #[Test]
    public function leftToRightKeepsSingleEdgeLabelsAroundAParallelGroup(): void
    {
        // A single labeled edge before and after a parallel group: the parallel
        // labels (above/below) and both single labels must all render — neither
        // pass may stop early at a non-matching edge.
        $graph = new Graph();
        $graph->addNode(new Node('P', 'P'));
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('Q', 'Q'));
        $graph->addEdge(new Edge('P', 'A', label: new Label('init')));
        $graph->addEdge(new Edge('A', 'B', label: new Label('yes')));
        $graph->addEdge(new Edge('A', 'B', label: new Label('no')));
        $graph->addEdge(new Edge('B', 'Q', label: new Label('done')));

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        foreach (['init', 'yes', 'no', 'done'] as $text) {
            self::assertStringContainsString($text, $result, sprintf("Label '%s' must render:\n%s", $text, $result));
        }
    }

    #[Test]
    public function rendersEveryEdgeLabelInAConvergentLayout(): void
    {
        // Convergent layouts (what a dependency `why` produces) crowd the merging
        // edges' labels onto one channel; none may be lost or clobbered.
        $graph = new Graph();
        foreach (['src', 'm0', 'm1', 'm2', 'dst'] as $id) {
            $graph->addNode(new Node($id, $id));
        }
        $graph->addEdge(new Edge('src', 'm0'));
        $graph->addEdge(new Edge('src', 'm1'));
        $graph->addEdge(new Edge('src', 'm2'));
        $graph->addEdge(new Edge('m0', 'dst', label: new Label('1.2.0')));
        $graph->addEdge(new Edge('m1', 'dst', label: new Label('3.4.5')));
        $graph->addEdge(new Edge('m2', 'dst', label: new Label('6.7.8')));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
                ╭─────╮
                │ src │
                ╰──┬──╯
                   │
           ┌───────┼───────┐
           ▼       ▼       ▼
        ╭──┴─╮  ╭──┴─╮  ╭──┴─╮
        │ m0 │  │ m1 │  │ m2 │
        ╰──┬─╯  ╰──┬─╯  ╰──┬─╯
           │ 1.2.0 │ 3.4.5 │ 6.7.8
           │       │       │
           └───────┼───────┘
                   ▼
                ╭──┴──╮
                │ dst │
                ╰─────╯
        EXPECTED;

        self::assertSame($expected, $result, sprintf("Converging labels must sit beside their own edge with a clear line below:\n%s", $result));
    }

    #[Test]
    public function keepsEveryLabelVisibleWhenConvergingLabelsExceedTheirChannels(): void
    {
        // Labels wider than the space between the converging verticals cannot sit
        // beside their own edge; they must still all render somewhere, never be
        // dropped or overwrite one another.
        $graph = new Graph();
        foreach (['src', 'm0', 'm1', 'm2', 'dst'] as $id) {
            $graph->addNode(new Node($id, $id));
        }
        $graph->addEdge(new Edge('src', 'm0'));
        $graph->addEdge(new Edge('src', 'm1'));
        $graph->addEdge(new Edge('src', 'm2'));
        $labels = ['requires-1.2.0', 'requires-3.4.5', 'requires-6.7.8'];
        $graph->addEdge(new Edge('m0', 'dst', label: new Label($labels[0])));
        $graph->addEdge(new Edge('m1', 'dst', label: new Label($labels[1])));
        $graph->addEdge(new Edge('m2', 'dst', label: new Label($labels[2])));

        $result = AsciiDag::default()->render($graph);

        // Wide labels fan the converging drops out until every label fits
        // beside its own edge.
        $expected = <<<'EXPECTED'
                         ╭─────╮
                         │ src │
                         ╰──┬──╯
                            │
           ┌────────────────┼────────────────┐
           ▼                ▼                ▼
        ╭──┴─╮           ╭──┴─╮           ╭──┴─╮
        │ m0 │           │ m1 │           │ m2 │
        ╰──┬─╯           ╰──┬─╯           ╰──┬─╯
           │ requires-1.2.0 │ requires-3.4.5 │ requires-6.7.8
           │                │                │
           └────────────────┼────────────────┘
                            ▼
                         ╭──┴──╮
                         │ dst │
                         ╰─────╯
        EXPECTED;

        self::assertSame($expected, $result, sprintf("Wide converging labels must fan out and sit beside their own edge:\n%s", $result));
    }

    #[Test]
    public function rendersDiamondEdgeLabelsBesideTheirOwnEdges(): void
    {
        // Diverging labels sit beside the fan-out bar; converging labels get a
        // reserved row and sit beside their own edge above the merge bar.
        $graph = new Graph();
        foreach (['A', 'B', 'C', 'D'] as $id) {
            $graph->addNode(new Node($id, $id));
        }
        $graph->addEdge(new Edge('A', 'B', label: new Label('1.0')));
        $graph->addEdge(new Edge('A', 'C', label: new Label('2.0')));
        $graph->addEdge(new Edge('B', 'D', label: new Label('3.0')));
        $graph->addEdge(new Edge('C', 'D', label: new Label('4.0')));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
             ╭───╮
             │ A │
             ╰─┬─╯
               │
        1.0 ┌──┴───┐ 2.0
            ▼      ▼
          ╭─┴─╮  ╭─┴─╮
          │ B │  │ C │
          ╰─┬─╯  ╰─┬─╯
        3.0 │      │ 4.0
            │      │
            └──┬───┘
               ▼
             ╭─┴─╮
             │ D │
             ╰───╯
        EXPECTED;

        self::assertSame($expected, $result, sprintf("Diamond labels must sit beside their own edges:\n%s", $result));
    }

    #[Test]
    public function widensOnlyTheConvergingChannelsWhoseLabelsNeedTheRoom(): void
    {
        // Labels of different widths, edges declared out of left-to-right order,
        // an unlabeled sibling, and one wider box: only the channel beside the
        // wide label gains columns.
        $graph = new Graph();
        $graph->addNode(new Node('src', 'src'));
        $graph->addNode(new Node('aa', 'middle-node'));
        $graph->addNode(new Node('bb', 'bb'));
        $graph->addNode(new Node('cc', 'cc'));
        $graph->addNode(new Node('dst', 'dst'));
        $graph->addEdge(new Edge('src', 'aa'));
        $graph->addEdge(new Edge('src', 'bb'));
        $graph->addEdge(new Edge('src', 'cc'));
        $graph->addEdge(new Edge('cc', 'dst', label: new Label('ww-9.9.9')));
        $graph->addEdge(new Edge('aa', 'dst', label: new Label('aa')));
        $graph->addEdge(new Edge('bb', 'dst'));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
                         ╭─────╮
                         │ src │
                         ╰──┬──╯
                            │
               ┌────────────┼───────┐
               ▼            ▼       ▼
        ╭──────┴──────╮  ╭──┴─╮  ╭──┴─╮
        │ middle-node │  │ bb │  │ cc │
        ╰──────┬──────╯  ╰──┬─╯  ╰──┬─╯
            aa │            │       │ ww-9.9.9
               │            │       │
               └────────────┼───────┘
                            ▼
                         ╭──┴──╮
                         │ dst │
                         ╰─────╯
        EXPECTED;

        self::assertSame($expected, $result, sprintf("Mixed-width converging labels must each sit beside their own edge:\n%s", $result));
    }

    #[Test]
    public function routesALongEdgeAroundTheChannelsClaimedByConvergingLabels(): void
    {
        // Two converging families plus a long edge threading through the same
        // gap: the channels widen per family and the long edge's lane must keep
        // out of the claimed spans instead of forcing a label away from its edge.
        $graph = new Graph();
        foreach (['src', 'a', 'b', 'c', 'd', 't1', 't2', 'z'] as $id) {
            $graph->addNode(new Node($id, $id));
        }
        $graph->addEdge(new Edge('src', 'z', label: new Label('direct')));
        $graph->addEdge(new Edge('src', 'a'));
        $graph->addEdge(new Edge('src', 'b'));
        $graph->addEdge(new Edge('src', 'c'));
        $graph->addEdge(new Edge('src', 'd'));
        $graph->addEdge(new Edge('a', 't1', label: new Label('wide-aaa')));
        $graph->addEdge(new Edge('b', 't1', label: new Label('wide-bbb')));
        $graph->addEdge(new Edge('c', 't2', label: new Label('wide-ccc')));
        $graph->addEdge(new Edge('d', 't2', label: new Label('wide-ddd')));
        $graph->addEdge(new Edge('t1', 'z'));
        $graph->addEdge(new Edge('t2', 'z'));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
                             ╭─────╮
                             │ src │
                             ╰──┬──╯
                                │
              ┌──────────┬──────┴────────────┬──────┬──────────┐ direct
              ▼          ▼                   ▼      ▼          │
            ╭─┴─╮      ╭─┴─╮               ╭─┴─╮  ╭─┴─╮        │
            │ a │      │ b │               │ c │  │ d │        │
            ╰─┬─╯      ╰─┬─╯               ╰─┬─╯  ╰─┬─╯        │
              │ wide-aaa │ wide-bbb wide-ccc │      │ wide-ddd │
              │          │                   │      │          │
              └──┬───────┘                   └──┬───┘          │
                 ▼                              ▼              │
              ╭──┴─╮                         ╭──┴─╮            │
              │ t1 │                         │ t2 │            │
              ╰──┬─╯                         ╰──┬─╯            │
                 │                              │              │
                 └──────────────────────────────┼──────────────┘
                                                ▼
                                              ╭─┴─╮
                                              │ z │
                                              ╰───╯
        EXPECTED;

        self::assertSame($expected, $result, sprintf("A long edge must route around claimed label channels:\n%s", $result));
    }

    #[Test]
    public function rendersALabeledSelfLoopEvenAfterAnUnlabeledOne(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addEdge(new Edge('A', 'A'));
        $graph->addEdge(new Edge('B', 'B', label: new Label('keep')));

        $result = AsciiDag::default()->render($graph);

        self::assertStringContainsString('keep', $result, sprintf("A labeled self-loop must render even when an unlabeled one precedes it:\n%s", $result));
    }

    #[Test]
    public function leftToRightSelfLoopKeepsALineSegmentBeforeTheNextNode(): void
    {
        // In left-to-right flow the loop and the outgoing edge both leave the
        // east side; the edge to the next node must still show a visible segment
        // after the loop junction rather than colliding into the arrowhead.
        $graph = new Graph();
        $graph->addNode(new Node('a', 'A'));
        $graph->addNode(new Node('b', 'B'));
        $graph->addEdge(new Edge('a', 'b'));
        $graph->addEdge(new Edge('a', 'a'));

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        $expected = <<<'EXPECTED'
        ╭───╮    ╭───╮
        │ A ├◀┬─▶┤ B │
        ╰─┬─╯ │  ╰───╯
          └───┘
        EXPECTED;

        self::assertSame($expected, $result, sprintf("The outgoing edge must keep a line segment after the self-loop:\n%s", $result));
    }

    #[Test]
    public function rendersLongEdgeSkippingLayerWithDummyRemoval(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Top'));
        $graph->addNode(new Node('B', 'Middle'));
        $graph->addNode(new Node('C', 'Bottom'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('A', 'C'));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
             ╭─────╮
             │ Top │
             ╰──┬──╯
                │
             ┌──┴───┐
             ▼      │
        ╭────┴───╮  │
        │ Middle │  │
        ╰────┬───╯  │
             │      │
             └──┬───┘
                ▼
           ╭────┴───╮
           │ Bottom │
           ╰────────╯
        EXPECTED;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function rendersClusterBorderAroundGroupMembers(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('push', 'Push'));
        $graph->addNode(new Node('lint', 'Lint'));
        $graph->addNode(new Node('test', 'Tests'));
        $graph->addNode(new Node('deploy', 'Deploy'));
        $graph->addEdge(new Edge('push', 'lint'));
        $graph->addEdge(new Edge('push', 'test'));
        $graph->addEdge(new Edge('lint', 'deploy'));
        $graph->addEdge(new Edge('test', 'deploy'));
        $graph->addGroup(new Group('quality', 'Quality', ['lint', 'test']));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
               ╭──────╮
               │ Push │
               ╰───┬──╯
              ┌────┴────┐
        ╔═════╪ Quality ╪═════╗
        ║     ▼         ▼     ║
        ║ ╭───┴──╮  ╭───┴───╮ ║
        ║ │ Lint │  │ Tests │ ║
        ║ ╰───┬──╯  ╰───┬───╯ ║
        ║     │         │     ║
        ╚═════╪═════════╪═════╝
              └────┬────┘
                   ▼
              ╭────┴───╮
              │ Deploy │
              ╰────────╯
        EXPECTED;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function keepsTheClusterBorderRectangularForAWideEmojiLabel(): void
    {
        // Emoji are two terminal columns wide. Measuring the label in code points
        // rather than display columns under-reserves the border, so the label runs
        // over the top-right corner. Both borders must stay the same width.
        $graph = new Graph();
        $graph->addNode(new Node('a', 'X'));
        $graph->addNode(new Node('b', 'Y'));
        $graph->addEdge(new Edge('a', 'b'));
        $graph->addGroup(new Group('cluster', '🚀🚀🚀🚀🚀', ['a', 'b']));

        $lines = explode("\n", AsciiDag::default()->render($graph));
        $top = $lines[0];
        $bottom = $lines[array_key_last($lines)];

        self::assertStringStartsWith('╔', $top);
        self::assertStringEndsWith('╗', $top);
        self::assertStringStartsWith('╚', $bottom);
        self::assertStringEndsWith('╝', $bottom);
        self::assertSame(mb_strwidth($bottom), mb_strwidth($top), 'Top and bottom cluster borders must align');
    }

    #[Test]
    public function graphWithoutGroupsRendersNoBorder(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'A'));
        $graph->addNode(new Node('b', 'B'));
        $graph->addEdge(new Edge('a', 'b'));

        $result = AsciiDag::default()->render($graph);

        self::assertStringNotContainsString('╔', $result, 'Without groups the renderer must draw no cluster border');
    }

    #[Test]
    public function edgesEnteringAClusterBendExactlyOneRowAboveTheBorder(): void
    {
        // With a wider rank gap the source sits several rows above the cluster, so
        // its exit row no longer lower-bounds the bend: the fan-out must bend on
        // the ceiling row (one row above the cluster's top border) with a straight
        // feeder above it, pinning the exact ceiling = topRow - 3.
        $graph = new Graph();
        $graph->addNode(new Node('push', 'Push'));
        $graph->addNode(new Node('lint', 'Lint'));
        $graph->addNode(new Node('test', 'Tests'));
        $graph->addEdge(new Edge('push', 'lint'));
        $graph->addEdge(new Edge('push', 'test'));
        $graph->addGroup(new Group('quality', 'Quality', ['lint', 'test']));

        $result = AsciiDag::builder()->rankSpacing(4)->build()->render($graph);

        $expected = <<<'EXPECTED'
               ╭──────╮
               │ Push │
               ╰───┬──╯
                   │
                   │
              ┌────┴────┐
        ╔═════╪ Quality ╪═════╗
        ║     ▼         ▼     ║
        ║ ╭───┴──╮  ╭───┴───╮ ║
        ║ │ Lint │  │ Tests │ ║
        ║ ╰──────╯  ╰───────╯ ║
        ║                     ║
        ╚═════════════════════╝
        EXPECTED;

        self::assertSame($expected, $result, sprintf("The cluster-entry bend must sit one row above the border:\n%s", $result));
    }

    #[Test]
    public function edgesEnteringAClusterDropThroughTheTopBorderIntoTheArrow(): void
    {
        // Push fans into Lint and Tests, both inside the Quality cluster. Each
        // edge must drop straight down through the cluster's top border — leaving
        // a connecting line directly above every arrowhead — instead of jogging
        // along the border row and leaving the arrows floating disconnected.
        $graph = new Graph();
        $graph->addNode(new Node('push', 'Push'));
        $graph->addNode(new Node('lint', 'Lint'));
        $graph->addNode(new Node('test', 'Tests'));
        $graph->addEdge(new Edge('push', 'lint'));
        $graph->addEdge(new Edge('push', 'test'));
        $graph->addGroup(new Group('quality', 'Quality', ['lint', 'test']));

        $lines = explode("\n", AsciiDag::default()->render($graph));

        foreach ($lines as $rowIndex => $line) {
            foreach ($this->columnsOf($line, '▼') as $column) {
                $cellAbove = mb_substr($lines[$rowIndex - 1], $column, 1);
                self::assertContains(
                    $cellAbove,
                    ['│', '╪'],
                    "The arrow at row {$rowIndex}, column {$column} must have a feeder line or crossing directly above it",
                );
            }
        }
    }

    /**
     * @return list<int>
     */
    private function columnsOf(string $line, string $needle): array
    {
        $columns = [];
        foreach (mb_str_split($line) as $column => $glyph) {
            if ($glyph === $needle) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    #[Test]
    public function rendersDiamondGraphWithMinimalCrossings(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addNode(new Node('D', 'D'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('B', 'D'));
        $graph->addEdge(new Edge('C', 'D'));

        $result = AsciiDag::default()->render($graph);

        self::assertStringContainsString('A', $result);
        self::assertStringContainsString('B', $result);
        self::assertStringContainsString('C', $result);
        self::assertStringContainsString('D', $result);
    }

    #[Test]
    public function rendersEdgeLabelOnSimpleEdge(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B', label: new Label('next')));

        $result = AsciiDag::default()->render($graph);

        self::assertStringContainsString('next', $result);
        self::assertStringContainsString('Start', $result);
        self::assertStringContainsString('End', $result);
    }

    #[Test]
    public function rendersHighlightedPathWithDoubleLines(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('app', 'my-app'));
        $graph->addNode(new Node('fw', 'laravel/framework'));
        $graph->addNode(new Node('http', 'guzzlehttp/guzzle'));
        $graph->addNode(new Node('log', 'monolog/monolog'));
        $graph->addNode(new Node('psr7', 'psr/http-message'));
        $graph->addNode(new Node('psr3', 'psr/log'));
        $graph->addNode(new Node('carbon', 'nesbot/carbon'));
        $graph->addEdge(new Edge('app', 'fw'));
        $graph->addEdge(new Edge('app', 'http'));
        $graph->addEdge(new Edge('fw', 'log'));
        $graph->addEdge(new Edge('fw', 'carbon'));
        $graph->addEdge(new Edge('http', 'psr7'));
        $graph->addEdge(new Edge('log', 'psr3'));
        $graph->highlightPath(['app', 'fw', 'log', 'psr3'], EdgeStrokeStyle::Double);

        $result = AsciiDag::default()->render($graph);

        self::assertStringContainsString('╦', $result, 'Exit connections on highlighted path use Double style');
        self::assertStringContainsString('╩', $result, 'Entry connections on highlighted path use Double style');
        self::assertStringContainsString('═', $result, 'Horizontal segments on highlighted path use Double style');
        self::assertStringContainsString('╔', $result, 'Corner segments on highlighted path use Double style');
        self::assertStringContainsString('┬', $result, 'Non-highlighted exit connections remain Solid');
        self::assertStringContainsString('┴', $result, 'Non-highlighted entry connections remain Solid');
    }

    #[Test]
    public function fanOutConnectsBoxesToTheChannelWithAVerticalConnector(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Hub'));
        $graph->addNode(new Node('B', 'Task 1'));
        $graph->addNode(new Node('C', 'Task 2'));
        $graph->addNode(new Node('D', 'Task 3'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('A', 'D'));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
                      ╭─────╮
                      │ Hub │
                      ╰──┬──╯
                         │
             ┌───────────┼───────────┐
             ▼           ▼           ▼
        ╭────┴───╮  ╭────┴───╮  ╭────┴───╮
        │ Task 1 │  │ Task 2 │  │ Task 3 │
        ╰────────╯  ╰────────╯  ╰────────╯
        EXPECTED;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function labeledBendingTransitionDoesNotStackTwoConnectorRows(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('push', 'Push'));
        $graph->addNode(new Node('lint', 'Lint'));
        $graph->addNode(new Node('test', 'Unit Tests'));
        $graph->addEdge(new Edge('push', 'lint', label: new Label('check')));
        $graph->addEdge(new Edge('push', 'test'));

        $result = AsciiDag::default()->render($graph);

        $lines = explode("\n", $result);
        foreach ($lines as $index => $line) {
            if (!isset($lines[$index + 1])) {
                continue;
            }
            $bothAreBareConnectors = 1 === preg_match('/^\s*│\s*$/u', $line)
                && 1 === preg_match('/^\s*│\s*$/u', $lines[$index + 1]);
            self::assertFalse($bothAreBareConnectors, sprintf(
                "Rows %d and %d are both bare connector rows — the label reservation must reuse the bend connector row:\n%s",
                $index,
                $index + 1,
                $result,
            ));
        }
    }

    #[Test]
    public function nearAlignedChainStaysCompactDespiteOffByOneCenters(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'First'));
        $graph->addNode(new Node('b', 'Second'));
        $graph->addNode(new Node('c', 'Third'));
        $graph->addNode(new Node('d', 'Fourth'));
        $graph->addEdge(new Edge('a', 'b'));
        $graph->addEdge(new Edge('b', 'c'));
        $graph->addEdge(new Edge('c', 'd'));
        $graph->addEdge(new Edge('a', 'd'));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
            ╭───────╮
            │ First │
            ╰───┬───╯
                │
             ┌──┴───┐
             ▼      │
        ╭────┴───╮  │
        │ Second │  │
        ╰────┬───╯  │
             │      │
             ▼      │
         ╭───┴───╮  │
         │ Third │  │
         ╰───┬───╯  │
             │      │
             └──┬───┘
                ▼
           ╭────┴───╮
           │ Fourth │
           ╰────────╯
        EXPECTED;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function passThroughChainWithSkipsRendersExactly(): void
    {
        // Asymmetric graph whose four Brandes-Köpf passes genuinely disagree, so
        // the exact coordinates pin the conflict marking, compaction, candidate
        // alignment and median balancing all at once.
        $graph = new Graph();
        $graph->addNode(new Node('a', 'AA'));
        $graph->addNode(new Node('b', 'B'));
        $graph->addNode(new Node('c', 'C'));
        $graph->addNode(new Node('z', 'Z'));
        $graph->addEdge(new Edge('a', 'b'));
        $graph->addEdge(new Edge('b', 'c'));
        $graph->addEdge(new Edge('c', 'z'));
        $graph->addEdge(new Edge('a', 'z'));
        $graph->addEdge(new Edge('b', 'z'));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
            ╭────╮
            │ AA │
            ╰──┬─╯
               │
            ┌──┴───┐
            ▼      │
          ╭─┴─╮    │
          │ B │    │
          ╰─┬─╯    │
            │      │
          ┌─┴──────┤
          ▼        │
        ╭─┴─╮      │
        │ C │      │
        ╰─┬─╯      │
          │        │
          └────┬───┘
               ▼
             ╭─┴─╮
             │ Z │
             ╰───╯
        EXPECTED;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function parallelPassThroughLanesKeepASeparatingColumn(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'AA'));
        $graph->addNode(new Node('b', 'B'));
        $graph->addNode(new Node('c', 'C'));
        $graph->addNode(new Node('z', 'Z'));
        $graph->addEdge(new Edge('a', 'b'));
        $graph->addEdge(new Edge('b', 'c'));
        $graph->addEdge(new Edge('c', 'z'));
        $graph->addEdge(new Edge('a', 'z'));
        $graph->addEdge(new Edge('b', 'z'));

        $result = AsciiDag::default()->render($graph);

        self::assertDoesNotMatchRegularExpression('/││/u', $result, sprintf(
            "Pass-through lanes must keep at least one empty column between them:\n%s",
            $result,
        ));
    }

    #[Test]
    public function lanesTowardsTheSameTargetShareOneTrunk(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'AA'));
        $graph->addNode(new Node('b', 'B'));
        $graph->addNode(new Node('c', 'C'));
        $graph->addNode(new Node('z', 'Z'));
        $graph->addEdge(new Edge('a', 'b'));
        $graph->addEdge(new Edge('b', 'c'));
        $graph->addEdge(new Edge('c', 'z'));
        $graph->addEdge(new Edge('a', 'z'));
        $graph->addEdge(new Edge('b', 'z'));

        $result = AsciiDag::default()->render($graph);

        $cRow = null;
        foreach (explode("\n", $result) as $line) {
            if (str_contains($line, ' C ')) {
                $cRow = $line;
            }
        }

        self::assertNotNull($cRow);
        self::assertSame(3, mb_substr_count($cRow, '│'), sprintf(
            "Both edges flow to Z and must share one trunk lane beside C (2 box borders + 1 lane):\n%s",
            $result,
        ));
    }

    #[Test]
    public function lanesFromTheSameSourceShareOneTrunk(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('r', 'Root'));
        $graph->addNode(new Node('a', 'A'));
        $graph->addNode(new Node('x', 'X'));
        $graph->addNode(new Node('z', 'Z'));
        $graph->addEdge(new Edge('r', 'a'));
        $graph->addEdge(new Edge('a', 'x'));
        $graph->addEdge(new Edge('x', 'z'));
        $graph->addEdge(new Edge('r', 'x'));
        $graph->addEdge(new Edge('r', 'z'));

        $result = AsciiDag::default()->render($graph);

        $aRow = null;
        foreach (explode("\n", $result) as $line) {
            if (str_contains($line, ' A ')) {
                $aRow = $line;
            }
        }

        self::assertNotNull($aRow);
        self::assertSame(3, mb_substr_count($aRow, '│'), sprintf(
            "Both edges leave Root and must share one trunk lane beside A (2 box borders + 1 lane):\n%s",
            $result,
        ));
    }

    #[Test]
    public function rendersFanOut(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Hub'));
        $graph->addNode(new Node('B', 'B'));
        $graph->addNode(new Node('C', 'C'));
        $graph->addNode(new Node('D', 'D'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('A', 'C'));
        $graph->addEdge(new Edge('A', 'D'));

        $result = AsciiDag::default()->render($graph);

        self::assertStringContainsString('Hub', $result);
        self::assertStringContainsString('B', $result);
        self::assertStringContainsString('C', $result);
        self::assertStringContainsString('D', $result);
    }

    #[Test]
    public function ansiRendersColoredPathWithEscapeCodes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->colorPath(['A', 'B'], AnsiColor::Red);

        $result = AsciiDag::builder()->ansi()->build()->render($graph);

        self::assertStringContainsString("\033[31m", $result, 'Output must contain red ANSI escape code');
        self::assertStringContainsString("\033[0m", $result, 'Output must contain reset escape code');
        self::assertStringContainsString('Start', $result);
        self::assertStringContainsString('End', $result);
    }

    #[Test]
    public function defaultRendersColoredPathWithoutEscapeCodes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->colorPath(['A', 'B'], AnsiColor::Red);

        $result = AsciiDag::default()->render($graph);

        self::assertStringNotContainsString("\033[", $result, 'PlainText output must not contain ANSI escape codes');
    }

    #[Test]
    public function rendersLinearChainLeftToRight(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        self::assertStringContainsString('Start', $result);
        self::assertStringContainsString('End', $result);
        self::assertStringContainsString('─', $result, 'Left-to-right mode must have horizontal edges');
        self::assertStringContainsString('▶', $result, 'Left-to-right mode must have rightward arrow');
    }

    #[Test]
    public function leftToRightLabelsAreNotTruncatedByBoxBorders(): void
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

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        self::assertStringContainsString('yes', $result,
            'Full label "yes" must be visible — not truncated by box borders');
    }

    #[Test]
    public function connectsEdgeToShorterNodeWhenLayerHasMixedHeights(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('root', 'Root'));
        $graph->addNode(new Node('tall', 'Tall', ['line 1', 'line 2', 'line 3']));
        $graph->addNode(new Node('short', 'Short'));
        $graph->addNode(new Node('sink', 'Sink'));
        $graph->addEdge(new Edge('root', 'tall'));
        $graph->addEdge(new Edge('root', 'short'));
        $graph->addEdge(new Edge('tall', 'sink'));
        $graph->addEdge(new Edge('short', 'sink'));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
              ╭──────╮
              │ Root │
              ╰───┬──╯
                  │
             ┌────┴─────┐
             ▼          ▼
        ╭────┴───╮  ╭───┴───╮
        │  Tall  │  │ Short │
        │ line 1 │  ╰───┬───╯
        │ line 2 │      │
        │ line 3 │      │
        ╰────┬───╯      │
             │          │
             └────┬─────┘
                  ▼
              ╭───┴──╮
              │ Sink │
              ╰──────╯
        EXPECTED;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function leftToRightLabelAppearsAdjacentToItsOwnEdgeLane(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('start', 'Review'));
        $graph->addNode(new Node('approve', 'Approve'));
        $graph->addNode(new Node('reject', 'Reject'));
        $graph->addNode(new Node('done', 'Done'));
        $graph->addEdge(new Edge('start', 'approve', label: new Label('yes')));
        $graph->addEdge(new Edge('start', 'reject'));
        $graph->addEdge(new Edge('approve', 'done'));
        $graph->addEdge(new Edge('reject', 'done'));

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        $labelLine = self::lineIndexContaining($result, 'yes');
        $approveLine = self::lineIndexContaining($result, 'Approve');
        $rejectLine = self::lineIndexContaining($result, 'Reject');

        self::assertLessThan(
            abs($labelLine - $rejectLine),
            abs($labelLine - $approveLine),
            'Label "yes" belongs to the Review->Approve edge and must render closer to the Approve lane than to the Reject lane',
        );
    }

    private static function lineIndexContaining(string $output, string $needle): int
    {
        foreach (explode("\n", $output) as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index;
            }
        }

        self::fail(sprintf('Output does not contain "%s"', $needle));
    }

    #[Test]
    public function cyclicGraphKeepsForwardChainStraight(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'Start'));
        $graph->addNode(new Node('b', 'Process'));
        $graph->addNode(new Node('c', 'End'));
        $graph->addEdge(new Edge('a', 'b'));
        $graph->addEdge(new Edge('b', 'c'));
        $graph->addEdge(new Edge('c', 'a'));

        $result = AsciiDag::default()->render($graph);

        $expected = <<<'EXPECTED'
         ╭───────╮
         │ Start ├◀╌╌┐
         ╰───┬───╯   ╎
             │       ╎
             ▼       ╎
        ╭────┴────╮  ╎
        │ Process │  ╎
        ╰────┬────╯  ╎
             │       ╎
             ▼       ╎
          ╭──┴──╮    ╎
          │ End ├╌╌╌╌┘
          ╰─────╯
        EXPECTED;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function leftToRightFeedbackLaneSitsDirectlyBelowTheBoxes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'Init'));
        $graph->addNode(new Node('b', 'Loop'));
        $graph->addNode(new Node('c', 'Done'));
        $graph->addEdge(new Edge('a', 'b'));
        $graph->addEdge(new Edge('b', 'c'));
        $graph->addEdge(new Edge('c', 'b'));

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        $expected = <<<'EXPECTED'
        ╭──────╮  ╭──────╮  ╭──────╮
        │ Init ├─▶┤ Loop ├─▶┤ Done │
        ╰──────╯  ╰───┬──╯  ╰───┬──╯
                      ▲         ╎
                      └╌╌╌╌╌╌╌╌╌┘
        EXPECTED;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function leftToRightTrunkPrefersTheRowThatKeepsARealSourceStraight(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'A'));
        $graph->addNode(new Node('b', 'B'));
        $graph->addNode(new Node('m', 'M'));
        $graph->addNode(new Node('z', 'Z'));
        $graph->addEdge(new Edge('a', 'm'));
        $graph->addEdge(new Edge('m', 'z'));
        $graph->addEdge(new Edge('a', 'z'));
        $graph->addEdge(new Edge('b', 'z'));

        // Fast layering keeps B at rank 0 as a far-left source; the default
        // network-simplex layering would pull B beside M to shorten b->z,
        // dissolving the long-trunk scenario this test exercises in the router.
        $result = AsciiDag::builder()->leftToRight()->quality(LayoutQuality::Fast)->build()->render($graph);

        $bRow = null;
        foreach (explode("\n", $result) as $line) {
            if (str_contains($line, ' B ')) {
                $bRow = $line;
            }
        }

        self::assertNotNull($bRow);
        self::assertMatchesRegularExpression('/─{4,}/u', $bRow, sprintf(
            "B's only edge can run straight (its row is unobstructed), so the shared trunk must sit on B's row:\n%s",
            $result,
        ));
    }

    #[Test]
    public function leftToRightTrunkScenarioRendersExactly(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'A'));
        $graph->addNode(new Node('b', 'B'));
        $graph->addNode(new Node('m', 'M'));
        $graph->addNode(new Node('z', 'Z'));
        $graph->addEdge(new Edge('a', 'm'));
        $graph->addEdge(new Edge('m', 'z'));
        $graph->addEdge(new Edge('a', 'z'));
        $graph->addEdge(new Edge('b', 'z'));

        // See leftToRightTrunkPrefersTheRowThatKeepsARealSourceStraight: Fast
        // layering preserves the far-left B source this exact-render asserts.
        $result = AsciiDag::builder()->leftToRight()->quality(LayoutQuality::Fast)->build()->render($graph);

        $expected = <<<'EXPECTED'
        ╭───╮   ╭───╮
        │ A ├─┬▶┤ M ├─┐
        ╰───╯ │ ╰───╯ │
              │       │ ╭───╮
              │       ├▶┤ Z │
        ╭───╮ │       │ ╰───╯
        │ B ├─┴───────┘
        ╰───╯
        EXPECTED;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function leftToRightSeparateTargetLanesRenderExactly(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('r', 'Root', ['info']));
        $graph->addNode(new Node('a', 'A'));
        $graph->addNode(new Node('x', 'X'));
        $graph->addNode(new Node('w', 'W'));
        $graph->addEdge(new Edge('r', 'a'));
        $graph->addEdge(new Edge('a', 'x'));
        $graph->addEdge(new Edge('x', 'w'));
        $graph->addEdge(new Edge('r', 'x'));
        $graph->addEdge(new Edge('a', 'w'));

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        $expected = <<<'EXPECTED'
                   ╭───╮
        ╭──────╮ ┌▶┤ A ├─┬───────┐
        │ Root │ │ ╰───╯ │       │ ╭───╮
        │ info ├─┴───────┤ ╭───╮ ├▶┤ W │
        ╰──────╯         └▶┤ X ├─┘ ╰───╯
                           ╰───╯
        EXPECTED;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function leftToRightChannelKeepsAConnectorGapFromSourceBorder(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('hub', 'Hub'));
        $graph->addNode(new Node('a', 'Task 1'));
        $graph->addNode(new Node('b', 'Task 2'));
        $graph->addNode(new Node('c', 'Task 3'));
        $graph->addEdge(new Edge('hub', 'a'));
        $graph->addEdge(new Edge('hub', 'b'));
        $graph->addEdge(new Edge('hub', 'c'));

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        self::assertDoesNotMatchRegularExpression('/├[^─▶]/u', $result, sprintf(
            "Every exit junction must be followed by a horizontal connector, not sit flush against the channel:\n%s",
            $result,
        ));
    }

    #[Test]
    public function leftToRightLanesTowardsTheSameTargetShareOneTrunk(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'A'));
        $graph->addNode(new Node('b', 'B'));
        $graph->addNode(new Node('m', 'M'));
        $graph->addNode(new Node('z', 'Z'));
        $graph->addEdge(new Edge('a', 'm'));
        $graph->addEdge(new Edge('m', 'z'));
        $graph->addEdge(new Edge('a', 'z'));
        $graph->addEdge(new Edge('b', 'z'));

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        $longLaneRows = 0;
        foreach (explode("\n", $result) as $line) {
            if (1 === preg_match('/─{5,}/u', $line)) {
                ++$longLaneRows;
            }
        }

        self::assertSame(1, $longLaneRows, sprintf(
            "Both edges flow to Z and must share one horizontal trunk lane (found %d long lane rows):\n%s",
            $longLaneRows,
            $result,
        ));
    }

    #[Test]
    public function rendersCyclicGraphWithDashedBackEdge(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'Process'));
        $graph->addNode(new Node('C', 'End'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('C', 'A'));

        $result = AsciiDag::default()->render($graph);

        self::assertStringContainsString('Start', $result);
        self::assertStringContainsString('Process', $result);
        self::assertStringContainsString('End', $result);
        self::assertMatchesRegularExpression('/╎|╌/u', $result, 'Back-edge must render with dashed style');
        self::assertStringContainsString('◀', $result, 'Top-to-bottom feedback edge must point into the original target from the side');
        self::assertStringNotContainsString('▲', $result, 'Feedback arrow must not be placed on an upward junction');
    }

    #[Test]
    public function rendersLeftToRightCyclicGraphWithBottomFeedbackArrow(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Draft'));
        $graph->addNode(new Node('B', 'Review'));
        $graph->addNode(new Node('C', 'Rework'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('C', 'A'));

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        self::assertMatchesRegularExpression('/╎|╌/u', $result, 'Back-edge must render with dashed style');
        self::assertStringContainsString('▲', $result, 'Left-to-right feedback edge must point into the original target from below');
    }

    #[Test]
    public function rendersLeftToRightTwoNodeCycleOnSeparateLanes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Init'));
        $graph->addNode(new Node('B', 'Loop'));
        $graph->addNode(new Node('C', 'Done'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->addEdge(new Edge('B', 'C'));
        $graph->addEdge(new Edge('C', 'B'));

        $result = AsciiDag::builder()->leftToRight()->build()->render($graph);

        self::assertMatchesRegularExpression('/╎|╌/u', $result, 'Adjacent back-edge must keep its dashed style');
        self::assertStringContainsString('▲', $result, 'Adjacent back-edge must point into the target through a bottom feedback lane');
        self::assertStringNotContainsString('◀▶', $result, 'Opposite arrows must not collapse onto one shared lane');
        self::assertStringNotContainsString('┴◀╌┴', $result, 'Feedback edge must not splice into the bottom border between adjacent nodes');
    }
}
