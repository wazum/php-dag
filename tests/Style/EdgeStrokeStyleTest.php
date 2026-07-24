<?php

declare(strict_types=1);

namespace PhpDag\Tests\Style;

use PhpDag\Render\Direction;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EdgeStrokeStyleTest extends TestCase
{
    #[Test]
    public function solidUnicodeResolvesAllSixteenMasks(): void
    {
        $glyphs = EdgeStrokeStyle::Solid->glyphs();

        $expected = [' ', '│', '─', '└', '│', '│', '┌', '├', '─', '┘', '─', '┴', '┐', '┤', '┬', '┼'];

        for ($mask = 0; $mask <= 15; ++$mask) {
            self::assertSame($expected[$mask], $glyphs->junctionFor($mask), "Mask {$mask} mismatch");
        }
    }

    #[Test]
    public function heavyUnicodeResolvesAllSixteenMasks(): void
    {
        $glyphs = EdgeStrokeStyle::Heavy->glyphs();

        $expected = [' ', '┃', '━', '┗', '┃', '┃', '┏', '┣', '━', '┛', '━', '┻', '┓', '┫', '┳', '╋'];

        for ($mask = 0; $mask <= 15; ++$mask) {
            self::assertSame($expected[$mask], $glyphs->junctionFor($mask), "Mask {$mask} mismatch");
        }
    }

    #[Test]
    public function dashedUsesDashedLinesAndLightJunctions(): void
    {
        $glyphs = EdgeStrokeStyle::Dashed->glyphs();

        $expected = [' ', '╎', '╌', '└', '╎', '╎', '┌', '├', '╌', '┘', '╌', '┴', '┐', '┤', '┬', '┼'];

        for ($mask = 0; $mask <= 15; ++$mask) {
            self::assertSame($expected[$mask], $glyphs->junctionFor($mask), "Mask {$mask} mismatch");
        }
    }

    #[Test]
    public function dottedUsesDottedLinesAndLightJunctions(): void
    {
        $glyphs = EdgeStrokeStyle::Dotted->glyphs();

        $expected = [' ', '┊', '┈', '└', '┊', '┊', '┌', '├', '┈', '┘', '┈', '┴', '┐', '┤', '┬', '┼'];

        for ($mask = 0; $mask <= 15; ++$mask) {
            self::assertSame($expected[$mask], $glyphs->junctionFor($mask), "Mask {$mask} mismatch");
        }
    }

    #[Test]
    public function doubleUsesDoubleLineJunctions(): void
    {
        $glyphs = EdgeStrokeStyle::Double->glyphs();

        $expected = [' ', '║', '═', '╚', '║', '║', '╔', '╠', '═', '╝', '═', '╩', '╗', '╣', '╦', '╬'];

        for ($mask = 0; $mask <= 15; ++$mask) {
            self::assertSame($expected[$mask], $glyphs->junctionFor($mask), "Mask {$mask} mismatch");
        }
    }

    #[Test]
    public function strongStrokesOutrankLightStrokesByOnePriorityTier(): void
    {
        self::assertSame(0, EdgeStrokeStyle::Solid->priority());
        self::assertSame(0, EdgeStrokeStyle::Dashed->priority());
        self::assertSame(0, EdgeStrokeStyle::Dotted->priority());
        self::assertSame(1, EdgeStrokeStyle::Heavy->priority());
        self::assertSame(1, EdgeStrokeStyle::Double->priority());
    }

    /**
     * @return iterable<string, array{EdgeStrokeStyle}>
     */
    public static function allStylesProvider(): iterable
    {
        foreach (EdgeStrokeStyle::cases() as $style) {
            yield $style->name => [$style];
        }
    }

    #[Test]
    #[DataProvider('allStylesProvider')]
    public function allStylesReturnAsciiFallback(EdgeStrokeStyle $style): void
    {
        $glyphs = $style->glyphs(unicode: false);

        $expected = [' ', '|', '-', '+', '|', '|', '+', '+', '-', '+', '-', '+', '+', '+', '+', '+'];

        for ($mask = 0; $mask <= 15; ++$mask) {
            self::assertSame($expected[$mask], $glyphs->junctionFor($mask), "Mask {$mask} mismatch for {$style->name}");
        }
    }

    #[Test]
    #[DataProvider('allStylesProvider')]
    public function allStylesReturnCorrectUnicodeArrows(EdgeStrokeStyle $style): void
    {
        $glyphs = $style->glyphs();

        self::assertSame('▲', $glyphs->arrowFor(Direction::UP));
        self::assertSame('▶', $glyphs->arrowFor(Direction::RIGHT));
        self::assertSame('▼', $glyphs->arrowFor(Direction::DOWN));
        self::assertSame('◀', $glyphs->arrowFor(Direction::LEFT));
    }

    #[Test]
    #[DataProvider('allStylesProvider')]
    public function allStylesReturnCorrectAsciiArrows(EdgeStrokeStyle $style): void
    {
        $glyphs = $style->glyphs(unicode: false);

        self::assertSame('^', $glyphs->arrowFor(Direction::UP));
        self::assertSame('>', $glyphs->arrowFor(Direction::RIGHT));
        self::assertSame('v', $glyphs->arrowFor(Direction::DOWN));
        self::assertSame('<', $glyphs->arrowFor(Direction::LEFT));
    }
}
