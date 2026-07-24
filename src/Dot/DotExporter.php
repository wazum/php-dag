<?php

declare(strict_types=1);

namespace PhpDag\Dot;

use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Style\EdgeStrokeStyle;

final readonly class DotExporter
{
    /**
     * Carries a group's original id through the round trip. Cluster names are
     * indexed (cluster_0, cluster_1, …) to stay Graphviz clusters and never
     * collide, so the real id — which may or may not start with "cluster" —
     * rides along in this private attribute for the parser to restore.
     */
    public const GROUP_ID_ATTRIBUTE = 'phpdag_id';

    public function export(Graph $graph): string
    {
        $lines = ['digraph {'];

        // Cluster members must be declared inside their subgraph before any edge
        // references them, so the parser assigns membership to the right cluster.
        $emitted = [];
        foreach ($graph->groups() as $index => $group) {
            $lines[] = sprintf('    subgraph "cluster_%d" {', $index);
            $lines[] = sprintf('        %s=%s;', self::GROUP_ID_ATTRIBUTE, $this->quote($group->id));
            $lines[] = sprintf('        label=%s;', $this->quote($group->label));
            foreach ($group->nodeIds as $nodeId) {
                if (isset($emitted[$nodeId])) {
                    continue;
                }
                /** @infection-ignore-all membership is probed with isset(), which is true for both true and false */
                $emitted[$nodeId] = true;
                $lines[] = '    '.$this->nodeLine($graph->getNode($nodeId));
            }
            $lines[] = '    }';
        }

        foreach ($graph->nodes() as $node) {
            if (isset($emitted[$node->id])) {
                continue;
            }
            $lines[] = $this->nodeLine($node);
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

    private function nodeLine(Node $node): string
    {
        // Each content line is escaped independently, then joined by the DOT
        // newline control sequence \n — which must stay unescaped.
        $label = implode('\n', array_map($this->escape(...), [$node->title, ...$node->body]));

        return sprintf('    %s [label="%s"];', $this->quote($node->id), $label);
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
