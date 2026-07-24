<?php

declare(strict_types=1);

namespace PhpDag\Style;

final readonly class BorderGlyphs
{
    public function __construct(
        public string $topLeft,
        public string $topRight,
        public string $bottomLeft,
        public string $bottomRight,
        public string $horizontal,
        public string $vertical,
    ) {
    }
}
