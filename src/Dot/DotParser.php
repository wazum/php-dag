<?php

declare(strict_types=1);

namespace PhpDag\Dot;

use PhpDag\Graph\ControlCharacters;
use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Group;
use PhpDag\Graph\Label;
use PhpDag\Graph\Node;
use PhpDag\Layout\FlowDirection;
use PhpDag\Style\EdgeStrokeStyle;

final class DotParser
{
    private const KIND_SYMBOL = 'symbol';
    private const KIND_IDENTIFIER = 'identifier';

    /** @var list<array{kind: string, value: string, offset: int}> */
    private array $tokens = [];
    private int $position = 0;
    private string $source = '';

    /**
     * Keyed by node id; PHP coerces numeric-string ids ("2") to int keys, hence array-key.
     *
     * @var array<array-key, array<string, string>>
     */
    private array $nodeDefinitions = [];

    /** @var list<array{source: string, target: string, attributes: array<string, string>}> */
    private array $edgeDefinitions = [];

    /** @var array<string, string> */
    private array $graphAttributes = [];

    /** @var array<string, string> */
    private array $nodeDefaults = [];

    /** @var array<string, string> */
    private array $edgeDefaults = [];

    /** @var list<string> cluster ids in declaration order */
    private array $groupOrder = [];

    /** @var array<string, string> cluster id => display label */
    private array $groupLabels = [];

    /** @var array<string, list<string>> cluster id => member node ids */
    private array $groupMembers = [];

    /** @var array<string, string> cluster name => original group id carried by DotExporter::GROUP_ID_ATTRIBUTE */
    private array $groupIdOverrides = [];

    /** @var array<string, true> node id => already assigned to a cluster */
    private array $assignedNodes = [];

    /** @var list<string> stack of open cluster ids (innermost last) */
    private array $clusterStack = [];
    private bool $strict = false;

    public function parse(string $dot): Graph
    {
        $this->source = $dot;
        $this->tokens = $this->tokenize($dot);
        $this->position = 0;
        $this->nodeDefinitions = [];
        $this->edgeDefinitions = [];
        $this->graphAttributes = [];
        $this->nodeDefaults = [];
        $this->edgeDefaults = [];
        $this->groupOrder = [];
        $this->groupLabels = [];
        $this->groupMembers = [];
        $this->groupIdOverrides = [];
        $this->assignedNodes = [];
        $this->clusterStack = [];
        $this->strict = false;

        $keyword = $this->advanceIdentifier();
        if ('strict' === strtolower($keyword)) {
            $this->strict = true;
            $keyword = $this->advanceIdentifier();
        }
        if ('digraph' !== strtolower($keyword)) {
            throw $this->syntaxError(sprintf('Only directed graphs (digraph) are supported, got "%s"', ControlCharacters::escape($keyword)), 0);
        }
        if (!$this->peekIsSymbol('{')) {
            $this->advance();
        }
        $this->expectSymbol('{');

        while (!$this->peekIsSymbol('}')) {
            /** @infection-ignore-all removing the call leaves the token stream stuck before '}', so the loop spins forever; only the process timeout can detect it */
            $this->parseStatement();
        }
        $this->expectSymbol('}');

        $trailing = $this->tokens[$this->position] ?? null;
        if (null !== $trailing) {
            throw $this->syntaxError(sprintf('Unexpected token "%s" after end of graph', ControlCharacters::escape($trailing['value'])), $trailing['offset']);
        }

        return $this->buildGraph();
    }

    /**
     * Graph-level attributes (e.g. rankdir) collected by the most recent parse() call.
     *
     * @return array<string, string>
     */
    public function graphAttributes(): array
    {
        return $this->graphAttributes;
    }

    /**
     * Only the flow axis is preserved, not its polarity: RL collapses to LR and
     * BT to TB, because the layout engine has no reversed-axis mode yet.
     */
    public function flowDirection(): FlowDirection
    {
        return match (strtoupper($this->graphAttributes['rankdir'] ?? '')) {
            'LR', 'RL' => FlowDirection::LeftToRight,
            default => FlowDirection::TopToBottom,
        };
    }

    private function parseStatement(): void
    {
        if ($this->peekIsSymbol('{')) {
            /** @infection-ignore-all removing the call never consumes the '{', so the caller's statement loop re-enters forever; only the process timeout can detect it */
            $this->parseSubgraphBody(null);
            $this->skipOptionalSemicolon();

            return;
        }

        $identifier = $this->advanceIdentifier();
        $this->skipOptionalPort();

        if ('subgraph' === strtolower($identifier)) {
            $subgraphName = null;
            if (!$this->peekIsSymbol('{')) {
                $subgraphName = $this->advanceIdentifier();
            }
            $this->parseSubgraphBody($subgraphName);
            $this->skipOptionalSemicolon();

            return;
        }

        if ($this->peekIsSymbol('=')) {
            $this->advance();
            $value = $this->advanceIdentifier();
            $openCluster = end($this->clusterStack);
            $lowerIdentifier = strtolower($identifier);
            if (false !== $openCluster && 'label' === $lowerIdentifier) {
                $this->groupLabels[$openCluster] = $value;
            } elseif (false !== $openCluster && DotExporter::GROUP_ID_ATTRIBUTE === $lowerIdentifier) {
                $this->groupIdOverrides[$openCluster] = $value;
            } else {
                $this->graphAttributes[$lowerIdentifier] = $value;
            }
        } elseif ($this->peekIsSymbol('[') && in_array(strtolower($identifier), ['graph', 'node', 'edge'], true)) {
            $this->applyDefaultAttributes(strtolower($identifier), $this->parseOptionalAttributes());
        } elseif ($this->peekIsSymbol('->')) {
            $this->parseEdgeStatement($identifier);
        } else {
            $this->defineNode($identifier, $this->parseOptionalAttributes());
        }

        $this->skipOptionalSemicolon();
    }

    private function skipOptionalSemicolon(): void
    {
        if ($this->peekIsSymbol(';')) {
            $this->advance();
        }
    }

    /** Ports (a:port, a:port:compass) are parsed and discarded; they carry no meaning for ASCII output. */
    private function skipOptionalPort(): void
    {
        while ($this->peekIsSymbol(':')) {
            $this->advance();
            $this->advanceIdentifier();
        }
    }

    private function parseSubgraphBody(?string $name): void
    {
        $this->expectSymbol('{');

        $savedNodeDefaults = $this->nodeDefaults;
        $savedEdgeDefaults = $this->edgeDefaults;

        $isCluster = null !== $name && str_starts_with($name, 'cluster');
        if ($isCluster) {
            $this->openCluster($name);
        }

        while (!$this->peekIsSymbol('}')) {
            /** @infection-ignore-all removing the call leaves the token stream stuck before '}', so the loop spins forever; only the process timeout can detect it */
            $this->parseStatement();
        }
        $this->expectSymbol('}');

        if ($isCluster) {
            array_pop($this->clusterStack);
        }

        $this->nodeDefaults = $savedNodeDefaults;
        $this->edgeDefaults = $savedEdgeDefaults;
    }

    /**
     * Registers (or re-opens) a Graphviz cluster and makes it the innermost
     * collector. The default label strips the "cluster" prefix; an in-body
     * "label = ..." overrides it.
     */
    private function openCluster(string $name): void
    {
        if (!isset($this->groupMembers[$name])) {
            $this->groupOrder[] = $name;
            $this->groupMembers[$name] = [];
            $this->groupLabels[$name] = ltrim(substr($name, strlen('cluster')), '_- ');
        }

        $this->clusterStack[] = $name;
    }

    /** @param array<string, string> $attributes */
    private function applyDefaultAttributes(string $target, array $attributes): void
    {
        match ($target) {
            'graph' => $this->graphAttributes = array_merge($this->graphAttributes, $attributes),
            'node' => $this->nodeDefaults = array_merge($this->nodeDefaults, $attributes),
            default => $this->edgeDefaults = array_merge($this->edgeDefaults, $attributes),
        };
    }

    private function parseEdgeStatement(string $sourceId): void
    {
        $this->defineNode($sourceId, []);

        $targetIds = [];
        while ($this->peekIsSymbol('->')) {
            $this->advance();
            $targetId = $this->advanceIdentifier();
            $this->skipOptionalPort();
            $this->defineNode($targetId, []);
            $targetIds[] = $targetId;
        }

        $attributes = $this->parseOptionalAttributes();

        $previousId = $sourceId;
        foreach ($targetIds as $targetId) {
            $this->edgeDefinitions[] = [
                'source' => $previousId,
                'target' => $targetId,
                'attributes' => array_merge($this->edgeDefaults, $attributes),
            ];
            $previousId = $targetId;
        }
    }

    /** @return array<string, string> */
    private function parseOptionalAttributes(): array
    {
        $attributes = [];
        while ($this->peekIsSymbol('[')) {
            $this->advance();
            while (!$this->peekIsSymbol(']')) {
                $name = $this->advanceIdentifier();
                $this->expectSymbol('=');
                $attributes[$name] = $this->advanceIdentifier();

                if ($this->peekIsSymbol(',') || $this->peekIsSymbol(';')) {
                    $this->advance();
                }
            }
            $this->expectSymbol(']');
        }

        return $attributes;
    }

    /** @param array<string, string> $attributes */
    private function defineNode(string $id, array $attributes): void
    {
        $this->nodeDefinitions[$id] = array_merge($this->nodeDefinitions[$id] ?? $this->nodeDefaults, $attributes);

        $innermostCluster = end($this->clusterStack);
        if (false !== $innermostCluster && !isset($this->assignedNodes[$id])) {
            /** @infection-ignore-all the value is only read via isset(), which is true for both true and false */
            $this->assignedNodes[$id] = true;
            $this->groupMembers[$innermostCluster][] = $id;
        }
    }

    private function buildGraph(): Graph
    {
        $graph = new Graph();

        foreach ($this->nodeDefinitions as $id => $attributes) {
            // A DOT file is untrusted input: strip control characters (a raw ESC/CSI
            // byte would otherwise reach the terminal, a stray newline would break the
            // canvas) while keeping the "\n" line breaks that drive multi-line labels.
            $labelLines = array_map(
                ControlCharacters::strip(...),
                explode("\n", $attributes['label'] ?? (string) $id),
            );
            $graph->addNode(new Node((string) $id, $labelLines[0], array_slice($labelLines, 1)));
        }

        $edgeDefinitions = $this->strict ? $this->coalescedStrictEdgeDefinitions() : $this->edgeDefinitions;
        foreach ($edgeDefinitions as $definition) {
            $label = isset($definition['attributes']['label']) ? new Label(ControlCharacters::strip($definition['attributes']['label'])) : null;
            $graph->addEdge(new Edge(
                $definition['source'],
                $definition['target'],
                edgeStrokeStyle: $this->edgeStrokeStyleFor($definition['attributes']['style'] ?? null),
                label: $label,
            ));
        }

        foreach ($this->groupOrder as $groupId) {
            $members = $this->groupMembers[$groupId];
            if ([] === $members) {
                continue;
            }
            $id = isset($this->groupIdOverrides[$groupId])
                ? ControlCharacters::strip($this->groupIdOverrides[$groupId])
                : $groupId;
            $graph->addGroup(new Group($id, ControlCharacters::strip($this->groupLabels[$groupId]), $members));
        }

        return $graph;
    }

    /** @return list<array{source: string, target: string, attributes: array<string, string>}> */
    private function coalescedStrictEdgeDefinitions(): array
    {
        /** @var list<array{source: string, target: string, attributes: array<string, string>}> $definitions */
        $definitions = [];
        /** @var array<string, int> $definitionIndexByEndpoints */
        $definitionIndexByEndpoints = [];
        foreach ($this->edgeDefinitions as $definition) {
            $edgeKey = $definition['source']."\0".$definition['target'];
            if (isset($definitionIndexByEndpoints[$edgeKey])) {
                $definitionIndex = $definitionIndexByEndpoints[$edgeKey];
                $existingDefinition = $definitions[$definitionIndex];
                $existingDefinition['attributes'] = array_merge(
                    $existingDefinition['attributes'],
                    $definition['attributes'],
                );
                $definitions[$definitionIndex] = $existingDefinition;

                continue;
            }

            $definitionIndexByEndpoints[$edgeKey] = count($definitions);
            $definitions[] = $definition;
        }

        return $definitions;
    }

    private function htmlLabelToText(string $html): string
    {
        $withLineBreaks = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;

        return html_entity_decode(strip_tags($withLineBreaks), ENT_QUOTES | ENT_HTML5);
    }

    private function edgeStrokeStyleFor(?string $dotStyle): EdgeStrokeStyle
    {
        return match ($dotStyle) {
            'dashed' => EdgeStrokeStyle::Dashed,
            'dotted' => EdgeStrokeStyle::Dotted,
            'bold' => EdgeStrokeStyle::Heavy,
            default => EdgeStrokeStyle::Solid,
        };
    }

    private function expectSymbol(string $value): void
    {
        $token = $this->advance();
        if (self::KIND_SYMBOL !== $token['kind'] || $token['value'] !== $value) {
            throw $this->syntaxError(sprintf('Expected "%s", got "%s"', $value, ControlCharacters::escape($token['value'])), $token['offset']);
        }
    }

    private function advanceIdentifier(): string
    {
        $token = $this->advance();
        if (self::KIND_IDENTIFIER !== $token['kind']) {
            throw $this->syntaxError(sprintf('Expected identifier, got "%s"', ControlCharacters::escape($token['value'])), $token['offset']);
        }

        return $token['value'];
    }

    /** @return array{kind: string, value: string, offset: int} */
    private function advance(): array
    {
        if (!isset($this->tokens[$this->position])) {
            throw $this->syntaxError('Unexpected end of DOT input', strlen($this->source));
        }

        return $this->tokens[$this->position++];
    }

    private function peekIsSymbol(string $value): bool
    {
        $token = $this->tokens[$this->position] ?? null;

        return null !== $token && self::KIND_SYMBOL === $token['kind'] && $token['value'] === $value;
    }

    private function syntaxError(string $reason, int $offset): DotSyntaxException
    {
        $consumed = substr($this->source, 0, $offset);
        $line = substr_count($consumed, "\n") + 1;
        $lastNewline = strrpos($consumed, "\n");
        $column = false === $lastNewline ? $offset + 1 : $offset - $lastNewline;

        return new DotSyntaxException($reason, $line, $column);
    }

    /** @return list<array{kind: string, value: string, offset: int}> */
    private function tokenize(string $dot): array
    {
        $tokens = [];
        $length = strlen($dot);
        $offset = 0;

        while ($offset < $length) {
            $character = $dot[$offset];

            if (1 === preg_match('/\s/', $character)) {
                ++$offset;
                continue;
            }

            if ('#' === $character || ('/' === $character && '/' === ($dot[$offset + 1] ?? ''))) {
                $newlinePosition = strpos($dot, "\n", $offset);
                $offset = false === $newlinePosition ? $length : $newlinePosition + 1;
                continue;
            }

            if ('/' === $character && '*' === ($dot[$offset + 1] ?? '')) {
                $endPosition = strpos($dot, '*/', $offset + 2);
                if (false === $endPosition) {
                    throw $this->syntaxError('Unterminated block comment in DOT input', $offset);
                }
                $offset = $endPosition + 2;
                continue;
            }

            if ('"' === $character) {
                $stringStart = $offset;
                $value = '';
                ++$offset;
                while ($offset < $length && '"' !== $dot[$offset]) {
                    if ('\\' === $dot[$offset] && '\\' === ($dot[$offset + 1] ?? '')) {
                        $value .= '\\';
                        $offset += 2;
                        continue;
                    }
                    if ('\\' === $dot[$offset] && '"' === ($dot[$offset + 1] ?? '')) {
                        $value .= '"';
                        $offset += 2;
                        continue;
                    }
                    if ('\\' === $dot[$offset] && in_array($dot[$offset + 1] ?? '', ['n', 'l', 'r'], true)) {
                        $value .= "\n";
                        $offset += 2;
                        continue;
                    }
                    $value .= $dot[$offset];
                    ++$offset;
                }
                if ($offset >= $length) {
                    throw $this->syntaxError('Unterminated quoted string in DOT input', $stringStart);
                }
                ++$offset;
                $tokens[] = ['kind' => self::KIND_IDENTIFIER, 'value' => $value, 'offset' => $stringStart];
                continue;
            }

            if ('<' === $character) {
                $depth = 0;
                $start = $offset;
                do {
                    if ($offset >= $length) {
                        throw $this->syntaxError('Unterminated HTML-like label in DOT input', $start);
                    }
                    if ('<' === $dot[$offset]) {
                        ++$depth;
                    } elseif ('>' === $dot[$offset]) {
                        --$depth;
                    }
                    ++$offset;
                } while ($depth > 0);

                $innerHtml = substr($dot, $start + 1, $offset - $start - 2);
                $tokens[] = ['kind' => self::KIND_IDENTIFIER, 'value' => $this->htmlLabelToText($innerHtml), 'offset' => $start];
                continue;
            }

            if ('-' === $character && in_array($dot[$offset + 1] ?? '', ['>', '-'], true)) {
                $tokens[] = ['kind' => self::KIND_SYMBOL, 'value' => '-'.$dot[$offset + 1], 'offset' => $offset];
                $offset += 2;
                continue;
            }

            // Negative numerals (e.g. margin=-0.1) are valid DOT identifiers; the edge
            // operators "->"/"--" are handled above, so a "-" here starts a number.
            if ('-' === $character && 1 === preg_match('/-(?:\.[0-9]+|[0-9]+(?:\.[0-9]*)?)/A', $dot, $matches, 0, $offset)) {
                $tokens[] = ['kind' => self::KIND_IDENTIFIER, 'value' => $matches[0], 'offset' => $offset];
                $offset += strlen($matches[0]);
                continue;
            }

            if (1 === preg_match('/[{}\[\];=,:]/', $character)) {
                $tokens[] = ['kind' => self::KIND_SYMBOL, 'value' => $character, 'offset' => $offset];
                ++$offset;
                continue;
            }

            if (1 === preg_match('/[A-Za-z0-9_.\x80-\xff]+/A', $dot, $matches, 0, $offset)) {
                $tokens[] = ['kind' => self::KIND_IDENTIFIER, 'value' => $matches[0], 'offset' => $offset];
                $offset += strlen($matches[0]);
                continue;
            }

            /** @infection-ignore-all dropping the throw never advances $offset, so the tokenizer loops forever on the unexpected character; only the process timeout can detect it */
            throw $this->syntaxError(sprintf('Unexpected character "%s" in DOT input', ControlCharacters::escape($character)), $offset);
        }

        return $tokens;
    }
}
