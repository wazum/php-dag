<?php

declare(strict_types=1);

namespace PhpDag\Render;

use PhpDag\Layout\LayoutGraph;

final class Renderer
{
    /** @param list<ElementRenderer> $elementRenderers */
    public function __construct(
        private readonly array $elementRenderers,
        private readonly OutputFormatter $formatter,
        private readonly bool $unicodeGlyphs = true,
    ) {
    }

    public function render(LayoutGraph $graph): string
    {
        return $this->formatter->format($this->draw($graph));
    }

    /** Runs every element renderer onto a fresh canvas, without formatting it to text. */
    public function draw(LayoutGraph $graph): Canvas
    {
        $canvas = new Canvas($this->unicodeGlyphs);
        foreach ($this->elementRenderers as $renderer) {
            $renderer->render($canvas, $graph);
        }

        return $canvas;
    }

    public function formatter(): OutputFormatter
    {
        return $this->formatter;
    }
}
