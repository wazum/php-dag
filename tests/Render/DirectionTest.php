<?php

declare(strict_types=1);

namespace PhpDag\Tests\Render;

use PhpDag\Render\Direction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DirectionTest extends TestCase
{
    #[Test]
    public function constantsAreCorrectBitmaskValues(): void
    {
        self::assertSame(1, Direction::UP);
        self::assertSame(2, Direction::RIGHT);
        self::assertSame(4, Direction::DOWN);
        self::assertSame(8, Direction::LEFT);
        self::assertSame(15, Direction::INTERSECTION);
    }

    #[Test]
    public function combinationsAreComposable(): void
    {
        self::assertSame(5, Direction::UP | Direction::DOWN);
        self::assertSame(10, Direction::LEFT | Direction::RIGHT);
        self::assertSame(15, Direction::UP | Direction::RIGHT | Direction::DOWN | Direction::LEFT);
        self::assertSame(Direction::INTERSECTION, Direction::UP | Direction::RIGHT | Direction::DOWN | Direction::LEFT);
    }

    #[Test]
    public function bitsDoNotOverlap(): void
    {
        $directions = [Direction::UP, Direction::RIGHT, Direction::DOWN, Direction::LEFT];

        foreach ($directions as $index => $first) {
            foreach ($directions as $otherIndex => $second) {
                if ($index !== $otherIndex) {
                    self::assertSame(0, $first & $second, "Direction bits overlap at index {$index} and {$otherIndex}");
                }
            }
        }
    }
}
