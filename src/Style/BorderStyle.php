<?php

declare(strict_types=1);

namespace PhpDag\Style;

enum BorderStyle
{
    case None;
    case Rounded;
    case Solid;
    case Double;
    case Dashed;
    case Dotted;

    public function thickness(): int
    {
        return match ($this) {
            self::None => 0,
            default => 1,
        };
    }

    public function glyphs(bool $unicode = true): BorderGlyphs
    {
        if (self::None === $this) {
            return new BorderGlyphs('', '', '', '', '', '');
        }

        if (!$unicode) {
            return new BorderGlyphs('+', '+', '+', '+', '-', '|');
        }

        return match ($this) {
            self::Rounded => new BorderGlyphs('╭', '╮', '╰', '╯', '─', '│'),
            self::Solid => new BorderGlyphs('┌', '┐', '└', '┘', '─', '│'),
            self::Double => new BorderGlyphs('╔', '╗', '╚', '╝', '═', '║'),
            self::Dashed => new BorderGlyphs('┌', '┐', '└', '┘', '╌', '╎'),
            self::Dotted => new BorderGlyphs('┌', '┐', '└', '┘', '┈', '┊'),
        };
    }

    /**
     * Bordered styles render badges on the frame (no extra space).
     * None renders badges inline as " (badge)" — the 3 accounts for space + parentheses.
     */
    public function badgeExtraWidth(int $badgeWidth): int
    {
        if (0 === $badgeWidth) {
            return 0;
        }

        return match ($this) {
            self::None => 3 + $badgeWidth,
            default => 0,
        };
    }
}
