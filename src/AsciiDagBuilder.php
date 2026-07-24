<?php

declare(strict_types=1);

namespace PhpDag;

use PhpDag\Layout\BrandesKopfPositioning;
use PhpDag\Layout\ClusterMemberCentering;
use PhpDag\Layout\CrossingMinimizer;
use PhpDag\Layout\CycleBreaker;
use PhpDag\Layout\DepthFirstOrdering;
use PhpDag\Layout\DummyNodeInserter;
use PhpDag\Layout\DummyNodeRemover;
use PhpDag\Layout\EdgeRouter;
use PhpDag\Layout\FeedbackEdgeRouter;
use PhpDag\Layout\FlowDirection;
use PhpDag\Layout\ForeignNodeEvictor;
use PhpDag\Layout\GroupOrdering;
use PhpDag\Layout\GroupSpacer;
use PhpDag\Layout\HorizontalCompactor;
use PhpDag\Layout\LabelReserver;
use PhpDag\Layout\LayerAssigner;
use PhpDag\Layout\LayoutEngine;
use PhpDag\Layout\LayoutQuality;
use PhpDag\Layout\LeftToRightLabelReserver;
use PhpDag\Layout\LeftToRightParallelPortReserver;
use PhpDag\Layout\LeftToRightPositioning;
use PhpDag\Layout\LeftToRightRouting;
use PhpDag\Layout\NodePositioner;
use PhpDag\Layout\Pipeline;
use PhpDag\Layout\SelfLoopRouter;
use PhpDag\Layout\VerticalCompactor;
use PhpDag\Render\AnsiFormatter;
use PhpDag\Render\BoxRenderer;
use PhpDag\Render\EdgeRenderer;
use PhpDag\Render\GroupRenderer;
use PhpDag\Render\LabelRenderer;
use PhpDag\Render\PlainTextFormatter;
use PhpDag\Render\Renderer;

final class AsciiDagBuilder
{
    private FlowDirection $direction = FlowDirection::TopToBottom;
    private bool $ansi = false;
    private bool $unicodeGlyphs = true;
    private int $nodeSpacing = 2;
    private int $rankSpacing = 2;
    private LayoutQuality $quality = LayoutQuality::Standard;
    private ?Pipeline $customPipeline = null;
    private ?Renderer $customRenderer = null;

    /** Crossing-minimization effort: Fast trades crossings for speed, Quality spends extra sweeps. */
    public function quality(LayoutQuality $quality): self
    {
        $this->quality = $quality;

        return $this;
    }

    /** Minimum empty columns (rows in left-to-right flow) between sibling boxes. */
    public function nodeSpacing(int $spacing): self
    {
        $this->nodeSpacing = max(1, $spacing);

        return $this;
    }

    /** Minimum empty rows (columns in left-to-right flow) between layers. */
    public function rankSpacing(int $spacing): self
    {
        $this->rankSpacing = max(2, $spacing);

        return $this;
    }

    public function ansi(): self
    {
        $this->ansi = true;

        return $this;
    }

    public function asciiGlyphs(): self
    {
        $this->unicodeGlyphs = false;

        return $this;
    }

    public function unicodeGlyphs(): self
    {
        $this->unicodeGlyphs = true;

        return $this;
    }

    public function plainText(): self
    {
        $this->ansi = false;

        return $this;
    }

    public function topToBottom(): self
    {
        $this->direction = FlowDirection::TopToBottom;

        return $this;
    }

    public function leftToRight(): self
    {
        $this->direction = FlowDirection::LeftToRight;

        return $this;
    }

    public function withPipeline(Pipeline $pipeline): self
    {
        $this->customPipeline = $pipeline;

        return $this;
    }

    public function withRenderer(Renderer $renderer): self
    {
        $this->customRenderer = $renderer;

        return $this;
    }

    public function build(): AsciiDag
    {
        return AsciiDag::fromComponents(
            new LayoutEngine($this->customPipeline ?? $this->buildDefaultPipeline()),
            $this->customRenderer ?? $this->buildDefaultRenderer(),
        );
    }

    public function defaultPipeline(): Pipeline
    {
        return $this->buildDefaultPipeline();
    }

    private function buildDefaultPipeline(): Pipeline
    {
        $pipeline = new Pipeline();
        $pipeline->add(new CycleBreaker());
        $pipeline->add(new LayerAssigner($this->quality->layerAssignment()));
        $pipeline->add(new DummyNodeInserter());
        $pipeline->add(new DepthFirstOrdering());
        $pipeline->add(new CrossingMinimizer($this->quality->crossingMinimization()));
        $pipeline->add(new GroupOrdering());

        match ($this->direction) {
            FlowDirection::TopToBottom => $this->addTopToBottomProcessors($pipeline),
            FlowDirection::LeftToRight => $this->addLeftToRightProcessors($pipeline),
        };

        $pipeline->add(new DummyNodeRemover());
        $pipeline->add(new FeedbackEdgeRouter($this->direction));
        $pipeline->add(new SelfLoopRouter());

        return $pipeline;
    }

    private function buildDefaultRenderer(): Renderer
    {
        $elementRenderers = match ($this->direction) {
            FlowDirection::TopToBottom => [
                new EdgeRenderer(unicodeGlyphs: $this->unicodeGlyphs),
                new GroupRenderer($this->unicodeGlyphs),
                new LabelRenderer(),
                new BoxRenderer($this->unicodeGlyphs),
            ],
            FlowDirection::LeftToRight => [
                new BoxRenderer($this->unicodeGlyphs),
                new EdgeRenderer(FlowDirection::LeftToRight, $this->unicodeGlyphs),
                new GroupRenderer($this->unicodeGlyphs),
                new LabelRenderer(FlowDirection::LeftToRight),
            ],
        };

        $formatter = $this->ansi ? new AnsiFormatter() : new PlainTextFormatter();

        return new Renderer($elementRenderers, $formatter, $this->unicodeGlyphs);
    }

    private function addTopToBottomProcessors(Pipeline $pipeline): void
    {
        $pipeline->add(new NodePositioner(new BrandesKopfPositioning(
            horizontalSpacing: $this->nodeSpacing,
            /** @infection-ignore-all the +1 seeds extra gap that VerticalCompactor reclaims down to the required spacing, so +1 vs +2 produces identical output */
            verticalSpacing: $this->rankSpacing + 1,
        )));
        $pipeline->add(new ForeignNodeEvictor());
        $pipeline->add(new ClusterMemberCentering());
        $pipeline->add(new GroupSpacer());
        // After the cluster ring is reserved, reclaim the excess so the border
        // replaces the rank gap rather than stacking on top of it.
        $pipeline->add(new VerticalCompactor($this->rankSpacing));
        $pipeline->add(new LabelReserver());
        $pipeline->add(new EdgeRouter());
    }

    private function addLeftToRightProcessors(Pipeline $pipeline): void
    {
        $pipeline->add(new LeftToRightParallelPortReserver());
        $pipeline->add(new NodePositioner(new LeftToRightPositioning(
            /** @infection-ignore-all the +1 seeds extra gap that HorizontalCompactor reclaims down to the required spacing, so +1 vs +2 produces identical output */
            horizontalSpacing: $this->rankSpacing + 1,
            verticalSpacing: $this->nodeSpacing,
        )));
        $pipeline->add(new ForeignNodeEvictor(FlowDirection::LeftToRight));
        $pipeline->add(new ClusterMemberCentering(FlowDirection::LeftToRight));
        $pipeline->add(new GroupSpacer(FlowDirection::LeftToRight));
        $pipeline->add(new HorizontalCompactor($this->rankSpacing));
        $pipeline->add(new LeftToRightLabelReserver());
        $pipeline->add(new EdgeRouter(new LeftToRightRouting()));
    }
}
