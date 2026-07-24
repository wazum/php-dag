<?php

declare(strict_types=1);

namespace PhpDag\Graph;

use InvalidArgumentException;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\EdgeStrokeStyle;

final readonly class Edge
{
    public function __construct(
        public string $sourceId,
        public string $targetId,
        public EdgeStrokeStyle $edgeStrokeStyle = EdgeStrokeStyle::Solid,
        public int $weight = 1,
        public int $minLength = 1,
        public ?Label $label = null,
        public ?AnsiColor $color = null,
    ) {
        if (ControlCharacters::present($this->sourceId) || ControlCharacters::present($this->targetId)) {
            throw new InvalidArgumentException('Edge endpoint ids must be valid UTF-8 without control characters');
        }

        if ($this->weight < 1) {
            throw new InvalidArgumentException('Weight must be at least 1');
        }

        if ($this->minLength < 1) {
            throw new InvalidArgumentException('Minimum length must be at least 1');
        }
    }

    public function withStrokeStyle(EdgeStrokeStyle $edgeStrokeStyle): self
    {
        return new self(
            $this->sourceId,
            $this->targetId,
            $edgeStrokeStyle,
            $this->weight,
            $this->minLength,
            $this->label,
            $this->color,
        );
    }

    public function withColor(AnsiColor $color): self
    {
        return new self(
            $this->sourceId,
            $this->targetId,
            $this->edgeStrokeStyle,
            $this->weight,
            $this->minLength,
            $this->label,
            $color,
        );
    }
}
