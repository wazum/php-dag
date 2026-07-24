<?php

declare(strict_types=1);

namespace PhpDag;

use PhpDag\Graph\Graph;
use PhpDag\Layout\LayoutEngine;
use PhpDag\Render\Renderer;

final class AsciiDag
{
    private function __construct(
        private readonly LayoutEngine $engine,
        private readonly Renderer $renderer,
    ) {
    }

    /** @internal Used by AsciiDagBuilder */
    public static function fromComponents(LayoutEngine $engine, Renderer $renderer): self
    {
        return new self($engine, $renderer);
    }

    public static function builder(): AsciiDagBuilder
    {
        return new AsciiDagBuilder();
    }

    public static function default(): self
    {
        return self::builder()->build();
    }

    public function render(Graph $graph): string
    {
        return $this->layout($graph)->render();
    }

    public function layout(Graph $graph): LayoutResult
    {
        $canvas = $this->renderer->draw($this->engine->layout($graph));

        return new LayoutResult($canvas, $this->renderer->formatter());
    }
}
