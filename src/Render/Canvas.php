<?php

declare(strict_types=1);

namespace PhpDag\Render;

use PhpDag\Style\AnsiColor;
use PhpDag\Style\BorderGlyphs;
use PhpDag\Style\EdgeStrokeStyle;

final class Canvas
{
    /** @var array<int, array<int, Cell>> */
    private array $cells = [];

    public function __construct(
        private readonly bool $unicodeGlyphs = true,
    ) {
    }

    public function get(int $row, int $column): Cell
    {
        return $this->cells[$row][$column] ??= new Cell($this->unicodeGlyphs);
    }

    /** Read-only access that never materializes a cell — for formatters scanning the whole canvas. */
    public function cellAt(int $row, int $column): ?Cell
    {
        return $this->cells[$row][$column] ?? null;
    }

    /** @infection-ignore-all Default zIndex is always overridden by callers */
    public function putCharacter(int $row, int $column, string $character, int $zIndex = 0, ?AnsiColor $color = null): void
    {
        $this->get($row, $column)->putCharacter($character, $zIndex, $color);
    }

    /** @infection-ignore-all Default zIndex is always overridden by callers */
    public function markEdgePassthrough(int $row, int $column, int $bits, int $edgeId, EdgeStrokeStyle $strokeStyle, int $zIndex = 0, ?AnsiColor $color = null): void
    {
        $this->get($row, $column)->markEdgePassthrough($bits, $edgeId, $strokeStyle, $zIndex, $color);
    }

    /** @infection-ignore-all Default zIndex is always overridden by callers */
    public function horizontalLine(int $row, int $startColumn, int $endColumn, int $edgeId, EdgeStrokeStyle $strokeStyle, int $zIndex = 0, ?AnsiColor $color = null): void
    {
        for ($column = $startColumn; $column <= $endColumn; ++$column) {
            $this->markEdgePassthrough($row, $column, Direction::LEFT | Direction::RIGHT, $edgeId, $strokeStyle, $zIndex, $color);
        }
    }

    /** @infection-ignore-all Default zIndex is always overridden by callers */
    public function verticalLine(int $column, int $startRow, int $endRow, int $edgeId, EdgeStrokeStyle $strokeStyle, int $zIndex = 0, ?AnsiColor $color = null): void
    {
        for ($row = $startRow; $row <= $endRow; ++$row) {
            $this->markEdgePassthrough($row, $column, Direction::UP | Direction::DOWN, $edgeId, $strokeStyle, $zIndex, $color);
        }
    }

    /** @infection-ignore-all Default zIndex is always overridden by callers */
    public function box(int $row, int $column, int $width, int $height, BorderGlyphs $glyphs, int $zIndex = 0, ?AnsiColor $color = null): void
    {
        $lastColumn = $column + $width - 1;
        $lastRow = $row + $height - 1;

        $this->putCharacter($row, $column, $glyphs->topLeft, $zIndex, $color);
        $this->putCharacter($row, $lastColumn, $glyphs->topRight, $zIndex, $color);
        $this->putCharacter($lastRow, $column, $glyphs->bottomLeft, $zIndex, $color);
        $this->putCharacter($lastRow, $lastColumn, $glyphs->bottomRight, $zIndex, $color);

        for ($borderColumn = $column + 1; $borderColumn < $lastColumn; ++$borderColumn) {
            $this->putCharacter($row, $borderColumn, $glyphs->horizontal, $zIndex, $color);
            $this->putCharacter($lastRow, $borderColumn, $glyphs->horizontal, $zIndex, $color);
        }

        for ($borderRow = $row + 1; $borderRow < $lastRow; ++$borderRow) {
            $this->putCharacter($borderRow, $column, $glyphs->vertical, $zIndex, $color);
            $this->putCharacter($borderRow, $lastColumn, $glyphs->vertical, $zIndex, $color);
        }
    }

    /** @infection-ignore-all Default zIndex is always overridden by callers */
    public function text(int $row, int $column, string $text, int $zIndex = 0, ?AnsiColor $color = null): void
    {
        $offset = 0;
        foreach (mb_str_split($text) as $character) {
            $this->putCharacter($row, $column + $offset, $character, $zIndex, $color);
            $characterWidth = mb_strwidth($character);
            for ($continuation = 1; $continuation < $characterWidth; ++$continuation) {
                $this->putCharacter($row, $column + $offset + $continuation, '', $zIndex, $color);
            }
            $offset += $characterWidth;
        }
    }

    public function width(): int
    {
        if ([] === $this->cells) {
            return 0;
        }

        /** @infection-ignore-all Minimum column index is 0, max() makes any value ≤ 0 equivalent */
        $maxColumn = 0;
        foreach ($this->cells as $columns) {
            /** @psalm-suppress ArgumentTypeCoercion */
            $maxColumn = max($maxColumn, max(array_keys($columns))); // @phpstan-ignore argument.type
        }

        return $maxColumn + 1;
    }

    public function height(): int
    {
        if ([] === $this->cells) {
            return 0;
        }

        return max(array_keys($this->cells)) + 1;
    }

    /** Lowest written row index (0 when empty); may be negative once content is placed above the origin. Leading empty rows are trimmed by the formatter, so this need not be clamped to 0. */
    public function firstRow(): int
    {
        if ([] === $this->cells) {
            return 0;
        }

        return min(array_keys($this->cells));
    }

    /** Lowest written column index (0 when empty); may be negative once content is placed left of the origin. */
    public function firstColumn(): int
    {
        $minColumn = 0;
        foreach ($this->cells as $columns) {
            /** @psalm-suppress ArgumentTypeCoercion */
            $minColumn = min($minColumn, min(array_keys($columns))); // @phpstan-ignore argument.type
        }

        return $minColumn;
    }
}
