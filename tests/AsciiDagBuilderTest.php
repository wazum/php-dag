<?php

declare(strict_types=1);

namespace PhpDag\Tests;

use PhpDag\AsciiDag;
use PhpDag\AsciiDagBuilder;
use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Label;
use PhpDag\Graph\Node;
use PhpDag\Layout\ClusterMemberCentering;
use PhpDag\Layout\CrossingMinimizer;
use PhpDag\Layout\DepthFirstOrdering;
use PhpDag\Layout\ForeignNodeEvictor;
use PhpDag\Layout\GroupOrdering;
use PhpDag\Layout\GroupSpacer;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\LayoutQuality;
use PhpDag\Layout\Pipeline;
use PhpDag\Layout\Processor;
use PhpDag\Layout\VerticalCompactor;
use PhpDag\Render\BoxRenderer;
use PhpDag\Render\PlainTextFormatter;
use PhpDag\Render\Renderer;
use PhpDag\Style\AnsiColor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class AsciiDagBuilderTest extends TestCase
{
    #[Test]
    public function asciiGlyphsRendersPureAsciiOutput(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));

        $result = AsciiDag::builder()->asciiGlyphs()->build()->render($graph);

        self::assertMatchesRegularExpression('/^[\x20-\x7E\n]+$/', $result, 'ASCII mode output must contain only printable ASCII characters');

        $expected = <<<'EXPECTED'
        +-------+
        | Start |
        +---+---+
            |
            v
         +--+--+
         | End |
         +-----+
        EXPECTED;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function defaultPipelineMinimizesEdgeCrossings(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Alpha'));
        $graph->addNode(new Node('B', 'Beta'));
        $graph->addNode(new Node('C', 'Gamma'));
        $graph->addNode(new Node('D', 'Delta'));
        $graph->addEdge(new Edge('A', 'D'));
        $graph->addEdge(new Edge('B', 'C'));

        $result = (new AsciiDagBuilder())->build()->render($graph);

        $expected = <<<'EXPECTED'
        ╭───────╮  ╭──────╮
        │ Alpha │  │ Beta │
        ╰───┬───╯  ╰───┬──╯
            │          │
            ▼          ▼
        ╭───┴───╮  ╭───┴───╮
        │ Delta │  │ Gamma │
        ╰───────╯  ╰───────╯
        EXPECTED;

        self::assertSame($expected, $result, 'Crossing minimization must reorder the second layer so the edges run straight down');
    }

    #[Test]
    public function defaultPipelineReservesGapRowForEdgeLabels(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B', label: new Label('approved')));

        $result = (new AsciiDagBuilder())->build()->render($graph);

        $expected = <<<'EXPECTED'
        ╭───────╮
        │ Start │
        ╰───┬───╯
            │
            │ approved
            ▼
         ╭──┴──╮
         │ End │
         ╰─────╯
        EXPECTED;

        self::assertSame($expected, $result, 'Label reservation must insert an extra gap row for the edge label');
    }

    #[Test]
    public function leftToRightPipelineReservesColumnsForEdgeLabels(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B', label: new Label('approved')));

        $result = (new AsciiDagBuilder())->leftToRight()->build()->render($graph);

        $expected = <<<'EXPECTED'
        ╭───────╮   ╭─────╮
        │ Start ├──▶┤ End │
        ╰───────╯   ╰─────╯
                  approved
        EXPECTED;

        self::assertSame($expected, $result, 'Label reservation must widen the gap column for the edge label');
    }

    #[Test]
    public function topToBottomPipelineWiresSeedCrossingMinAndClusterProcessors(): void
    {
        $pipeline = AsciiDag::builder()->defaultPipeline();

        self::assertTrue($pipeline->contains(DepthFirstOrdering::class), 'DFS seed must precede crossing minimisation');
        self::assertTrue($pipeline->contains(CrossingMinimizer::class));
        self::assertTrue($pipeline->contains(GroupOrdering::class));
        self::assertTrue($pipeline->contains(ForeignNodeEvictor::class));
        self::assertTrue($pipeline->contains(ClusterMemberCentering::class));
    }

    #[Test]
    public function leftToRightPipelineWiresSeedCrossingMinAndClusterProcessors(): void
    {
        $pipeline = AsciiDag::builder()->leftToRight()->defaultPipeline();

        self::assertTrue($pipeline->contains(DepthFirstOrdering::class));
        self::assertTrue($pipeline->contains(CrossingMinimizer::class));
        self::assertTrue($pipeline->contains(ForeignNodeEvictor::class));
        self::assertTrue($pipeline->contains(ClusterMemberCentering::class));
        self::assertTrue($pipeline->contains(GroupSpacer::class));
    }

    #[Test]
    public function defaultPipelineCanBeCustomizedAndPassedBack(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));

        $pipeline = AsciiDag::builder()->defaultPipeline();
        $pipeline->insertAfter(VerticalCompactor::class, new class implements Processor {
            public function process(LayoutGraph $layoutGraph): void
            {
                $layoutGraph->getLayoutNode('B')->row += 2;
            }
        });

        $customized = AsciiDag::builder()->withPipeline($pipeline)->build()->render($graph);
        $standard = AsciiDag::default()->render($graph);

        self::assertNotSame($standard, $customized, 'Customized pipeline must affect rendering');
        self::assertStringContainsString('End', $customized);
    }

    #[Test]
    public function nodeSpacingWidensTheGapBetweenSiblingBoxes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('hub', 'Hub'));
        $graph->addNode(new Node('a', 'A'));
        $graph->addNode(new Node('b', 'B'));
        $graph->addEdge(new Edge('hub', 'a'));
        $graph->addEdge(new Edge('hub', 'b'));

        $result = AsciiDag::builder()->nodeSpacing(6)->build()->render($graph);

        self::assertStringContainsString('╮      ╭', $result, 'Sibling boxes must be separated by six columns');
    }

    #[Test]
    public function nodeSpacingZeroIsFlooredSoSiblingsStaySeparated(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('hub', 'Hub'));
        $graph->addNode(new Node('a', 'A'));
        $graph->addNode(new Node('b', 'B'));
        $graph->addEdge(new Edge('hub', 'a'));
        $graph->addEdge(new Edge('hub', 'b'));

        $result = AsciiDag::builder()->nodeSpacing(0)->build()->render($graph);

        self::assertStringContainsString('│ A │ │ B │', $result, 'Node spacing floors at 1, so sibling boxes never touch');
    }

    #[Test]
    public function nodeSpacingOneGivesExactlyOneColumnGap(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('hub', 'Hub'));
        $graph->addNode(new Node('a', 'A'));
        $graph->addNode(new Node('b', 'B'));
        $graph->addEdge(new Edge('hub', 'a'));
        $graph->addEdge(new Edge('hub', 'b'));

        $result = AsciiDag::builder()->nodeSpacing(1)->build()->render($graph);

        self::assertStringContainsString('│ A │ │ B │', $result);
        self::assertStringNotContainsString('│ A │  │ B │', $result, 'Spacing 1 is one column, not the floored 2');
    }

    #[Test]
    public function rankSpacingBelowFloorClampsToTwo(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('hub', 'Hub'));
        $graph->addNode(new Node('a', 'A'));
        $graph->addNode(new Node('b', 'B'));
        $graph->addEdge(new Edge('hub', 'a'));
        $graph->addEdge(new Edge('hub', 'b'));

        $result = AsciiDag::builder()->rankSpacing(1)->build()->render($graph);

        $expected = <<<'EXPECTED'
          ╭─────╮
          │ Hub │
          ╰──┬──╯
             │
          ┌──┴───┐
          ▼      ▼
        ╭─┴─╮  ╭─┴─╮
        │ A │  │ B │
        ╰───╯  ╰───╯
        EXPECTED;

        self::assertSame($expected, $result, 'rankSpacing below the floor of 2 must clamp to 2');
    }

    #[Test]
    public function rankSpacingDeepensTheGapBetweenLayers(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'Start'));
        $graph->addNode(new Node('b', 'End'));
        $graph->addEdge(new Edge('a', 'b'));

        $result = AsciiDag::builder()->rankSpacing(4)->build()->render($graph);

        $expected = <<<'EXPECTED'
        ╭───────╮
        │ Start │
        ╰───┬───╯
            │
            │
            │
            ▼
         ╭──┴──╮
         │ End │
         ╰─────╯
        EXPECTED;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function qualityPresetsAllRenderTheDiamondCorrectly(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'Root'));
        $graph->addNode(new Node('b', 'Left'));
        $graph->addNode(new Node('c', 'Right'));
        $graph->addNode(new Node('d', 'Sink'));
        $graph->addEdge(new Edge('a', 'b'));
        $graph->addEdge(new Edge('a', 'c'));
        $graph->addEdge(new Edge('b', 'd'));
        $graph->addEdge(new Edge('c', 'd'));

        $standard = AsciiDag::default()->render($graph);

        foreach ([LayoutQuality::Fast, LayoutQuality::Standard, LayoutQuality::Quality] as $quality) {
            $result = AsciiDag::builder()->quality($quality)->build()->render($graph);
            self::assertSame($standard, $result, sprintf('Preset %s must solve the trivial diamond identically', $quality->name));
        }
    }

    #[Test]
    public function buildProducesDefaultTopToBottomPlainText(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));

        $builder = new AsciiDagBuilder();
        $dag = $builder->build();
        $result = $dag->render($graph);

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
    public function topToBottomExplicitlyProducesSameAsDefault(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));

        $defaultResult = (new AsciiDagBuilder())->build()->render($graph);
        $explicitResult = (new AsciiDagBuilder())->topToBottom()->build()->render($graph);

        self::assertSame($defaultResult, $explicitResult);
    }

    #[Test]
    public function leftToRightProducesHorizontalLayout(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));

        $dag = (new AsciiDagBuilder())->leftToRight()->build();
        $result = $dag->render($graph);

        self::assertStringContainsString('─', $result, 'Left-to-right must have horizontal edges');
        self::assertStringContainsString('▶', $result, 'Left-to-right must have rightward arrow');
    }

    #[Test]
    public function ansiProducesEscapeCodes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->colorPath(['A', 'B'], AnsiColor::Red);

        $dag = (new AsciiDagBuilder())->ansi()->build();
        $result = $dag->render($graph);

        self::assertStringContainsString("\033[31m", $result, 'ANSI mode must produce escape codes');
        self::assertStringContainsString("\033[0m", $result, 'ANSI mode must produce reset codes');
    }

    #[Test]
    public function plainTextProducesNoEscapeCodes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->colorPath(['A', 'B'], AnsiColor::Red);

        $dag = (new AsciiDagBuilder())->plainText()->build();
        $result = $dag->render($graph);

        self::assertStringNotContainsString("\033[", $result, 'PlainText mode must not contain ANSI codes');
    }

    #[Test]
    public function leftToRightAnsiCombinesBothAxes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));
        $graph->colorPath(['A', 'B'], AnsiColor::Red);

        $dag = (new AsciiDagBuilder())
            ->leftToRight()
            ->ansi()
            ->build();
        $result = $dag->render($graph);

        self::assertStringContainsString('─', $result, 'Must have horizontal edges');
        self::assertStringContainsString('▶', $result, 'Must have rightward arrow');
        self::assertStringContainsString("\033[31m", $result, 'Must have ANSI escape codes');
    }

    #[Test]
    public function withPipelineUsesCustomPipeline(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));

        $emptyPipeline = new Pipeline();
        $dag = (new AsciiDagBuilder())
            ->withPipeline($emptyPipeline)
            ->build();
        $result = $dag->render($graph);

        $defaultResult = (new AsciiDagBuilder())->build()->render($graph);
        self::assertNotSame($defaultResult, $result, 'Custom empty pipeline produces different output than default');
    }

    #[Test]
    public function withRendererUsesCustomRenderer(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));

        $rendererWithBoxOnly = new Renderer(
            [new BoxRenderer()],
            new PlainTextFormatter(),
        );
        $dag = (new AsciiDagBuilder())
            ->withRenderer($rendererWithBoxOnly)
            ->build();
        $result = $dag->render($graph);

        self::assertStringContainsString('Start', $result, 'Custom renderer with BoxRenderer renders node titles');
        self::assertStringNotContainsString("\u{25BC}", $result, 'Custom renderer without EdgeRenderer omits arrows');
    }

    #[Test]
    public function asciiDagBuilderFactoryReturnsBuilder(): void
    {
        $builder = AsciiDag::builder();

        self::assertInstanceOf(AsciiDagBuilder::class, $builder);
    }

    #[Test]
    public function defaultMatchesBuilderBuild(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));

        $defaultResult = AsciiDag::default()->render($graph);
        $builderResult = AsciiDag::builder()->build()->render($graph);

        self::assertSame($defaultResult, $builderResult);
    }

    #[Test]
    public function buildIsIdempotent(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));

        $builder = (new AsciiDagBuilder())->leftToRight()->ansi();
        $firstResult = $builder->build()->render($graph);
        $secondResult = $builder->build()->render($graph);

        self::assertSame($firstResult, $secondResult, 'Calling build() twice on the same builder must produce identical output');
    }

    #[Test]
    public function defaultTopToBottomPipelineIncludesVerticalCompactor(): void
    {
        $builder = new AsciiDagBuilder();
        $reflection = new ReflectionMethod($builder, 'buildDefaultPipeline');
        /** @var Pipeline $pipeline */
        $pipeline = $reflection->invoke($builder);
        $pipelineReflection = new ReflectionProperty(Pipeline::class, 'processors');
        /** @var list<Processor> $processors */
        $processors = $pipelineReflection->getValue($pipeline);

        $hasVerticalCompactor = false;
        foreach ($processors as $processor) {
            if ($processor instanceof VerticalCompactor) {
                $hasVerticalCompactor = true;
                break;
            }
        }

        self::assertTrue($hasVerticalCompactor, 'Default TB pipeline must include VerticalCompactor');
    }

    #[Test]
    public function withPipelineAndRendererCombined(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'Start'));
        $graph->addNode(new Node('B', 'End'));
        $graph->addEdge(new Edge('A', 'B'));

        $customPipeline = new Pipeline();
        $customRenderer = new Renderer(
            [new BoxRenderer()],
            new PlainTextFormatter(),
        );

        $dag = (new AsciiDagBuilder())
            ->withPipeline($customPipeline)
            ->withRenderer($customRenderer)
            ->build();
        $result = $dag->render($graph);

        $defaultResult = (new AsciiDagBuilder())->build()->render($graph);
        self::assertNotSame($defaultResult, $result, 'Custom pipeline + renderer must differ from default');
        self::assertStringNotContainsString("\u{25BC}", $result, 'Custom renderer without EdgeRenderer omits arrows');
    }
}
