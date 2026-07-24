<?php

declare(strict_types=1);

namespace PhpDag\Graph;

use InvalidArgumentException;

final readonly class Badge
{
    public function __construct(
        public string $text,
    ) {
        if ('' === $this->text) {
            throw new InvalidArgumentException('Badge text must not be empty');
        }

        if (ControlCharacters::present($this->text)) {
            throw new InvalidArgumentException('Badge text must be valid UTF-8 without control characters');
        }

        if (mb_strlen($this->text) > 3) {
            throw new InvalidArgumentException('Badge text must not exceed 3 characters');
        }
    }

    public function width(): int
    {
        return mb_strwidth($this->text);
    }
}
