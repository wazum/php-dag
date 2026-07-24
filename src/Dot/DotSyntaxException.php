<?php

declare(strict_types=1);

namespace PhpDag\Dot;

use InvalidArgumentException;

final class DotSyntaxException extends InvalidArgumentException
{
    public function __construct(
        string $reason,
        public readonly int $sourceLine,
        public readonly int $sourceColumn,
    ) {
        parent::__construct(sprintf('%s at line %d, column %d', $reason, $sourceLine, $sourceColumn));
    }
}
