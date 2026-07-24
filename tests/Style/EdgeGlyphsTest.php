<?php

declare(strict_types=1);

namespace PhpDag\Tests\Style;

use InvalidArgumentException;
use PhpDag\Render\Direction;
use PhpDag\Style\EdgeGlyphs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EdgeGlyphsTest extends TestCase
{
    private static function createSolidGlyphs(): EdgeGlyphs
    {
        return new EdgeGlyphs(
            [' ', '│', '─', '└', '│', '│', '┌', '├', '─', '┘', '─', '┴', '┐', '┤', '┬', '┼'],
            ['▲', '▶', '▼', '◀'],
        );
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function maskToCharacterProvider(): iterable
    {
        $expected = [
            0 => ' ', 1 => '│', 2 => '─', 3 => '└', 4 => '│', 5 => '│',
            6 => '┌', 7 => '├', 8 => '─', 9 => '┘', 10 => '─', 11 => '┴',
            12 => '┐', 13 => '┤', 14 => '┬', 15 => '┼',
        ];

        foreach ($expected as $mask => $character) {
            yield "mask {$mask}" => [$mask, $character];
        }
    }

    #[Test]
    #[DataProvider('maskToCharacterProvider')]
    public function resolveReturnsCorrectCharacterForEachMask(int $mask, string $expectedCharacter): void
    {
        $glyphs = self::createSolidGlyphs();

        self::assertSame($expectedCharacter, $glyphs->junctionFor($mask));
    }

    #[Test]
    public function resolveMasksHighBitsToFourBitRange(): void
    {
        $glyphs = self::createSolidGlyphs();

        self::assertSame($glyphs->junctionFor(0b0001), $glyphs->junctionFor(0b10001));
        self::assertSame($glyphs->junctionFor(0b1111), $glyphs->junctionFor(0b11111));
    }

    #[Test]
    public function arrowReturnsCorrectCharacterForDirection(): void
    {
        $glyphs = self::createSolidGlyphs();

        self::assertSame('▲', $glyphs->arrowFor(Direction::UP));
        self::assertSame('▶', $glyphs->arrowFor(Direction::RIGHT));
        self::assertSame('▼', $glyphs->arrowFor(Direction::DOWN));
        self::assertSame('◀', $glyphs->arrowFor(Direction::LEFT));
    }

    #[Test]
    public function verticalAndHorizontalConvenienceMethods(): void
    {
        $glyphs = self::createSolidGlyphs();

        self::assertSame('│', $glyphs->vertical());
        self::assertSame('─', $glyphs->horizontal());
    }

    #[Test]
    public function arrowForRejectsInvalidDirection(): void
    {
        $glyphs = self::createSolidGlyphs();

        $this->expectException(InvalidArgumentException::class);
        $glyphs->arrowFor(99);
    }

    #[Test]
    public function crossingCharacterReturnsHopByDefault(): void
    {
        $glyphs = self::createSolidGlyphs();

        self::assertSame(')', $glyphs->crossingCharacter());
    }
}
