<?php

declare(strict_types=1);

namespace PhpDag\Render;

use PhpDag\Graph\Node;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\RealLayoutNode;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\BorderStyle;

final readonly class BoxRenderer implements ElementRenderer
{
    private const Z_INDEX = 10;

    public function __construct(
        private bool $unicodeGlyphs = true,
    ) {
    }

    public function render(Canvas $canvas, LayoutGraph $graph): void
    {
        foreach ($graph->nodeIds() as $nodeId) {
            $layoutNode = $graph->getLayoutNode($nodeId);
            if ($layoutNode instanceof RealLayoutNode) {
                $this->renderNode($canvas, $layoutNode->node, $layoutNode->row, $layoutNode->column, $layoutNode->node->color, $layoutNode->boxHeight());
            }
        }
    }

    private function renderNode(Canvas $canvas, Node $node, int $row, int $column, ?AnsiColor $color, int $boxHeight): void
    {
        $contentColumn = $column + $node->style->borderStyle->thickness() + Node::HORIZONTAL_PADDING;
        // Centre the content vertically when the box was grown beyond its natural
        // height to host edge ports (left-to-right parallel edges).
        $verticalOffset = intdiv($boxHeight - $node->boxHeight(), 2);
        $contentRow = $row + $node->style->borderStyle->thickness() + $verticalOffset;
        $contentWidth = $node->contentWidth();

        $this->paintFootprint($canvas, $node, $row, $column, $boxHeight);
        $this->renderBorder($canvas, $node, $row, $column, $color, $boxHeight);
        $this->renderBadge($canvas, $node, $row, $column, $color);
        $this->renderTitle($canvas, $node, $contentRow, $contentColumn, $contentWidth, $color);
        $this->renderBody($canvas, $node, $contentRow + 1, $contentColumn, $contentWidth, $color);
    }

    /**
     * Blanks the full box area at box z-index so lower-z elements (edge
     * segments routed underneath) cannot bleed through padding cells.
     */
    private function paintFootprint(Canvas $canvas, Node $node, int $row, int $column, int $boxHeight): void
    {
        for ($footprintRow = $row; $footprintRow < $row + $boxHeight; ++$footprintRow) {
            for ($footprintColumn = $column; $footprintColumn < $column + $node->boxWidth(); ++$footprintColumn) {
                $canvas->putCharacter($footprintRow, $footprintColumn, ' ', self::Z_INDEX);
            }
        }
    }

    private function renderBorder(Canvas $canvas, Node $node, int $row, int $column, ?AnsiColor $color, int $boxHeight): void
    {
        if (BorderStyle::None === $node->style->borderStyle) {
            return;
        }

        $canvas->box(
            $row,
            $column,
            $node->boxWidth(),
            $boxHeight,
            glyphs: $node->style->borderStyle->glyphs($this->unicodeGlyphs),
            zIndex: self::Z_INDEX,
            color: $color,
        );
    }

    private function renderBadge(Canvas $canvas, Node $node, int $row, int $column, ?AnsiColor $color): void
    {
        $badge = $node->style->badge;
        if (null === $badge) {
            return;
        }

        if (BorderStyle::None === $node->style->borderStyle) {
            $badgeColumn = $column + Node::HORIZONTAL_PADDING + $node->contentWidth();
            $canvas->text($row, $badgeColumn, sprintf(' (%s)', $badge->text), self::Z_INDEX, $color);

            return;
        }

        $lastColumn = $column + $node->boxWidth() - 1;
        $canvas->text($row, $lastColumn - $badge->width(), $badge->text, self::Z_INDEX, $color);
    }

    private function renderTitle(Canvas $canvas, Node $node, int $row, int $column, int $contentWidth, ?AnsiColor $color): void
    {
        $title = $this->padContent($node->title, $contentWidth, $node->style->titleAlignment->padType());
        $canvas->text($row, $column, $title, self::Z_INDEX, $color);
    }

    private function renderBody(Canvas $canvas, Node $node, int $startRow, int $column, int $contentWidth, ?AnsiColor $color): void
    {
        $separatorOffset = ($node->style->titleBodySeparator && [] !== $node->body) ? 1 : 0;

        foreach ($node->body as $index => $line) {
            $body = $this->padContent($line, $contentWidth, $node->style->bodyAlignment->padType());
            $canvas->text($startRow + $separatorOffset + $index, $column, $body, self::Z_INDEX, $color);
        }
    }

    private function padContent(string $text, int $width, int $padType): string
    {
        return str_pad($text, $width - mb_strwidth($text) + strlen($text), ' ', $padType);
    }
}
