<?php

declare(strict_types=1);

namespace PhpDag\Style;

enum EdgeStrokeStyle
{
    case Solid;
    case Heavy;
    case Dashed;
    case Dotted;
    case Double;

    public function priority(): int
    {
        return match ($this) {
            self::Solid, self::Dashed, self::Dotted => 0,
            self::Heavy, self::Double => 1,
        };
    }

    public function glyphs(bool $unicode = true): EdgeGlyphs
    {
        if (!$unicode) {
            return new EdgeGlyphs(
                [' ', '|', '-', '+', '|', '|', '+', '+', '-', '+', '-', '+', '+', '+', '+', '+'],
                ['^', '>', 'v', '<'],
            );
        }

        return match ($this) {
            self::Solid => new EdgeGlyphs(
                [' ', '│', '─', '└', '│', '│', '┌', '├', '─', '┘', '─', '┴', '┐', '┤', '┬', '┼'],
                ['▲', '▶', '▼', '◀'],
            ),
            self::Heavy => new EdgeGlyphs(
                [' ', '┃', '━', '┗', '┃', '┃', '┏', '┣', '━', '┛', '━', '┻', '┓', '┫', '┳', '╋'],
                ['▲', '▶', '▼', '◀'],
            ),
            self::Dashed => new EdgeGlyphs(
                [' ', '╎', '╌', '└', '╎', '╎', '┌', '├', '╌', '┘', '╌', '┴', '┐', '┤', '┬', '┼'],
                ['▲', '▶', '▼', '◀'],
            ),
            self::Dotted => new EdgeGlyphs(
                [' ', '┊', '┈', '└', '┊', '┊', '┌', '├', '┈', '┘', '┈', '┴', '┐', '┤', '┬', '┼'],
                ['▲', '▶', '▼', '◀'],
            ),
            self::Double => new EdgeGlyphs(
                [' ', '║', '═', '╚', '║', '║', '╔', '╠', '═', '╝', '═', '╩', '╗', '╣', '╦', '╬'],
                ['▲', '▶', '▼', '◀'],
            ),
        };
    }
}
