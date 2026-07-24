<?php

declare(strict_types=1);

namespace PhpDag\Dot;

use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Style\EdgeStrokeStyle;

final readonly class DotExporter
{
    public function export(Graph $graph): string
    {
        $lines = ['digraph {'];

        // Cluster members must be declared inside their subgraph before any edge
        // references them, so the parser assigns membership to the right cluster.
        $emitted = [];
        foreach ($graph->groups() as $group) {
            $lines[] = sprintf('    subgraph %s {', $this->quote($this->clusterName($group->id)));
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

    /** Prefix ids that aren't already Graphviz clusters so the parser treats the subgraph as a group. */
    private function clusterName(string $groupId): string
    {
        return str_starts_with($groupId, 'cluster') ? $groupId : 'cluster_'.$groupId;
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
