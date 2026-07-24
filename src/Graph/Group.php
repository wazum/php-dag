<?php

declare(strict_types=1);

namespace PhpDag\Graph;

use InvalidArgumentException;

final readonly class Group
{
    /** @param list<string> $nodeIds */
    public function __construct(
        public string $id,
        public string $label,
        public array $nodeIds,
    ) {
        if ('' === $this->id) {
            throw new InvalidArgumentException('Group id must not be empty');
        }

        if (ControlCharacters::present($this->id)) {
            throw new InvalidArgumentException('Group id must be valid UTF-8 without control characters');
        }

        if (ControlCharacters::present($this->label)) {
            throw new InvalidArgumentException('Group label must be valid UTF-8 without control characters');
        }

        if ([] === $this->nodeIds) {
            throw new InvalidArgumentException('Group must contain at least one node');
        }

        foreach ($this->nodeIds as $nodeId) {
            if (ControlCharacters::present($nodeId)) {
                throw new InvalidArgumentException('Group member ids must be valid UTF-8 without control characters');
            }
        }

        if (count($this->nodeIds) !== count(array_unique($this->nodeIds))) {
            throw new InvalidArgumentException('Group must not contain duplicate node ids');
        }
    }
}
