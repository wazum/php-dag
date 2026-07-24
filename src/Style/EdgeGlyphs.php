<?php

declare(strict_types=1);

namespace PhpDag\Style;

use InvalidArgumentException;
use PhpDag\Render\Direction;

final readonly class EdgeGlyphs
{
    /**
     * @param array<int, string> $junctionMap
     * @param array<int, string> $arrowMap
     */
    public function __construct(
        private array $junctionMap,
        private array $arrowMap,
        private string $crossingCharacter = ')',
    ) {
    }

    public function crossingCharacter(): string
    {
        return $this->crossingCharacter;
    }

    public function junctionFor(int $mask): string
    {
        return $this->junctionMap[$mask & 0b1111];
    }

    public function arrowFor(int $direction): string
    {
        $index = match ($direction) {
            Direction::UP => 0,
            Direction::RIGHT => 1,
            Direction::DOWN => 2,
            Direction::LEFT => 3,
            default => throw new InvalidArgumentException(sprintf('Invalid direction: %d', $direction)),
        };

        return $this->arrowMap[$index];
    }

    public function vertical(): string
    {
        return $this->junctionFor(Direction::UP | Direction::DOWN);
    }

    public function horizontal(): string
    {
        return $this->junctionFor(Direction::LEFT | Direction::RIGHT);
    }
}
