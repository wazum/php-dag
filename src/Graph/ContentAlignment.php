<?php

declare(strict_types=1);

namespace PhpDag\Graph;

enum ContentAlignment
{
    case Left;
    case Center;
    case Right;

    public function padType(): int
    {
        return match ($this) {
            self::Left => STR_PAD_RIGHT,
            self::Center => STR_PAD_BOTH,
            self::Right => STR_PAD_LEFT,
        };
    }
}
