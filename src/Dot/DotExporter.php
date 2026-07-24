<?php

declare(strict_types=1);

namespace PhpDag\Dot;

use PhpDag\Graph\Graph;
use PhpDag\Style\EdgeStrokeStyle;

final readonly class DotExporter
{
    public function export(Graph $graph): string
    {
        $lines = ['digraph {'];

        foreach ($graph->nodes() as $node) {
            // Each content line is escaped independently, then joined by the DOT
            // newline control sequence \n — which must stay unescaped.
            $label = implode('\n', array_map($this->escape(...), [$node->title, ...$node->body]));
            $lines[] = sprintf('    %s [label="%s"];', $this->quote($node->id), $label);
        }

        foreach ([...$graph->edges(), ...$graph->selfLoops()] as $edge) {
            $attributes = [];
            if (null !== $edge->label) {
                $attributes[] = sprintf('label=%s', $this->quote($edge->label->text));
            }
            $dotStyle = $this->dotStyleFor($edge->edgeStrokeStyle);
            if (null !== $dotStyle) {
                $attributes[] = sprintf('style=%s', $dotStyle);
            }

            $lines[] = sprintf(
                '    %s -> %s%s;',
                $this->quote($edge->sourceId),
                $this->quote($edge->targetId),
                [] === $attributes ? '' : ' ['.implode(', ', $attributes).']',
            );
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    private function quote(string $value): string
    {
        return '"'.$this->escape($value).'"';
    }

    /** Escape backslashes before quotes so a trailing backslash cannot swallow the closing quote. */
    private function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    private function dotStyleFor(EdgeStrokeStyle $strokeStyle): ?string
    {
        return match ($strokeStyle) {
            EdgeStrokeStyle::Dashed => 'dashed',
            EdgeStrokeStyle::Dotted => 'dotted',
            EdgeStrokeStyle::Heavy, EdgeStrokeStyle::Double => 'bold',
            EdgeStrokeStyle::Solid => null,
        };
    }
}
