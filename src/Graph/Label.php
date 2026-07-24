<?php

declare(strict_types=1);

namespace PhpDag\Graph;

use InvalidArgumentException;

final readonly class Label
{
    public function __construct(
        public string $text,
        public LabelPosition $position = LabelPosition::Middle,
    ) {
        if (ControlCharacters::present($this->text)) {
            throw new InvalidArgumentException('Label text must be valid UTF-8 without control characters');
        }
    }

    public function width(): int
    {
        return mb_strwidth($this->text);
    }
}
