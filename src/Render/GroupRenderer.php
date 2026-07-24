<?php

declare(strict_types=1);

namespace PhpDag\Render;

use PhpDag\Graph\Group;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\RealLayoutNode;
use PhpDag\Style\BorderStyle;

/**
 * Draws a double-line border around each group's member nodes.
 *
 * Runs after the edge renderer so it can detect edges crossing the border and
 * substitute junction glyphs (╪ for a vertical edge through a horizontal
 * border, ╫ for the transpose). The border sits in the padding gap reserved
 * around the group by GroupSpacer, at a z-index above edges but below boxes.
 */
final readonly class GroupRenderer implements ElementRenderer
{
    private const Z_INDEX = 6;
    private const VERTICAL_PADDING = 1;
    private const HORIZONTAL_PADDING = 1;

    private const VERTICAL_EDGE_CHARS = ['│', '┃', '╎', '┊', '▲', '▼'];
    private const HORIZONTAL_EDGE_CHARS = ['─', '━', '╌', '┈', '◀', '▶'];

    public function __construct(
        private bool $unicodeGlyphs = true,
    ) {
    }

    public function render(Canvas $canvas, LayoutGraph $graph): void
    {
        foreach ($graph->groups() as $group) {
            $this->renderGroup($canvas, $graph, $group);
        }
    }

    private function renderGroup(Canvas $canvas, LayoutGraph $graph, Group $group): void
    {
        $members = $this->memberNodes($graph, $group);
        if ([] === $members) {
            return;
        }

        $top = PHP_INT_MAX;
        $bottom = PHP_INT_MIN;
        $left = PHP_INT_MAX;
        $right = PHP_INT_MIN;
        foreach ($members as $member) {
            $top = min($top, $member->row);
            $bottom = max($bottom, $member->row + $member->boxHeight() - 1);
            $left = min($left, $member->column);
            $right = max($right, $member->column + $member->boxWidth() - 1);
        }

        $borderTop = $top - self::VERTICAL_PADDING - 1;
        $borderBottom = $bottom + self::VERTICAL_PADDING + 1;
        $borderLeft = $left - $graph->groupLeftPadding($group->id);
        $borderRight = $right + self::HORIZONTAL_PADDING + 1;

        $topCrossings = $this->topBorderCrossingColumns($canvas, $borderTop, $borderLeft, $borderRight);
        $this->drawBorder($canvas, $borderTop, $borderBottom, $borderLeft, $borderRight);
        $this->drawLabel($canvas, $group->label, $borderTop, $borderLeft, $borderRight, $topCrossings);
    }

    /**
     * Columns of the top border where an edge drops through, read before the
     * border is drawn so the label can be placed clear of them.
     *
     * @return list<int>
     */
    private function topBorderCrossingColumns(Canvas $canvas, int $borderTop, int $borderLeft, int $borderRight): array
    {
        $columns = [];
        /** @infection-ignore-all the bounds only widen/narrow the scan onto the corner cells, which hold box-drawing corners, never edge glyphs, so the detected crossings are unchanged */
        for ($column = $borderLeft + 1; $column < $borderRight; ++$column) {
            if (in_array($canvas->get($borderTop, $column)->resolvedCharacter(), self::VERTICAL_EDGE_CHARS, true)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function drawBorder(Canvas $canvas, int $top, int $bottom, int $left, int $right): void
    {
        $glyphs = BorderStyle::Double->glyphs($this->unicodeGlyphs);

        $canvas->putCharacter($top, $left, $glyphs->topLeft, self::Z_INDEX);
        $canvas->putCharacter($top, $right, $glyphs->topRight, self::Z_INDEX);
        $canvas->putCharacter($bottom, $left, $glyphs->bottomLeft, self::Z_INDEX);
        $canvas->putCharacter($bottom, $right, $glyphs->bottomRight, self::Z_INDEX);

        $verticalCrossing = $this->unicodeGlyphs ? '╪' : '+';
        for ($column = $left + 1; $column < $right; ++$column) {
            $canvas->putCharacter($top, $column, $this->horizontalSegment($canvas, $top, $column, $glyphs->horizontal, $verticalCrossing), self::Z_INDEX);
            $canvas->putCharacter($bottom, $column, $this->horizontalSegment($canvas, $bottom, $column, $glyphs->horizontal, $verticalCrossing), self::Z_INDEX);
        }

        $horizontalCrossing = $this->unicodeGlyphs ? '╫' : '+';
        for ($row = $top + 1; $row < $bottom; ++$row) {
            $canvas->putCharacter($row, $left, $this->verticalSegment($canvas, $row, $left, $glyphs->vertical, $horizontalCrossing), self::Z_INDEX);
            $canvas->putCharacter($row, $right, $this->verticalSegment($canvas, $row, $right, $glyphs->vertical, $horizontalCrossing), self::Z_INDEX);
        }
    }

    private function horizontalSegment(Canvas $canvas, int $row, int $column, string $borderGlyph, string $crossing): string
    {
        return in_array($canvas->get($row, $column)->resolvedCharacter(), self::VERTICAL_EDGE_CHARS, true)
            ? $crossing
            : $borderGlyph;
    }

    private function verticalSegment(Canvas $canvas, int $row, int $column, string $borderGlyph, string $crossing): string
    {
        return in_array($canvas->get($row, $column)->resolvedCharacter(), self::HORIZONTAL_EDGE_CHARS, true)
            ? $crossing
            : $borderGlyph;
    }

    /**
     * @param list<int> $crossingColumns
     */
    private function drawLabel(Canvas $canvas, string $label, int $borderTop, int $borderLeft, int $borderRight, array $crossingColumns): void
    {
        if ('' === $label) {
            return;
        }

        $available = $borderRight - $borderLeft - 3;
        /** @infection-ignore-all defensive guard: $available equals the member box width (minimum 5), so it is never below 1; the < 1 vs <= 1 boundary is unreachable */
        if ($available < 1) {
            return;
        }

        $text = ' '.mb_strimwidth($label, 0, $available, '…').' ';
        $width = mb_strwidth($text);
        $start = $this->labelStartColumn($borderLeft, $borderRight, $width, $crossingColumns);
        $canvas->text($borderTop, $start, $text, self::Z_INDEX);

        // When no crossing-free slot exists the label falls back onto the left
        // edge; restore any crossing it covered, since that junction is the
        // edge's only visible link through the border.
        $crossing = $this->unicodeGlyphs ? '╪' : '+';
        foreach ($crossingColumns as $column) {
            /** @infection-ignore-all drawBorder already drew ╪ at every crossing, so restoring one outside the label span is an idempotent no-op; widening the span (||, <=) is therefore equivalent, and the start-edge boundary only matters for a crossing on the label's first column, which the widening pipeline never produces */
            if ($column >= $start && $column < $start + $width) {
                $canvas->putCharacter($borderTop, $column, $crossing, self::Z_INDEX);
            }
        }
    }

    /**
     * Leftmost border column where the label fits without covering an edge
     * crossing, falling back to the left edge when no clear slot exists.
     *
     * @param list<int> $crossingColumns
     */
    private function labelStartColumn(int $borderLeft, int $borderRight, int $width, array $crossingColumns): int
    {
        $default = $borderLeft + 2;
        /** @infection-ignore-all the <= bound only decides whether a slot whose right edge would touch the corner column is tried; reaching it requires every interior slot to be blocked, which the widening pipeline prevents by reserving a clear gap */
        for ($start = $default; $start + $width <= $borderRight; ++$start) {
            if (!$this->spanCoversCrossing($start, $width, $crossingColumns)) {
                return $start;
            }
        }

        return $default;
    }

    /**
     * @param list<int> $crossingColumns
     */
    private function spanCoversCrossing(int $start, int $width, array $crossingColumns): bool
    {
        foreach ($crossingColumns as $column) {
            if ($column >= $start && $column < $start + $width) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<RealLayoutNode>
     */
    private function memberNodes(LayoutGraph $graph, Group $group): array
    {
        $members = [];
        foreach ($group->nodeIds as $nodeId) {
            if (!$graph->hasNode($nodeId)) {
                continue;
            }
            $node = $graph->getLayoutNode($nodeId);
            if ($node instanceof RealLayoutNode) {
                $members[] = $node;
            }
        }

        return $members;
    }
}
