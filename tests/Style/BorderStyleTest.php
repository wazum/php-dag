<?php

declare(strict_types=1);

namespace PhpDag\Tests\Style;

use PhpDag\Style\BorderGlyphs;
use PhpDag\Style\BorderStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BorderStyleTest extends TestCase
{
    /**
     * @return iterable<string, array{BorderStyle, string, string, string, string, string, string}>
     */
    public static function unicodeGlyphsProvider(): iterable
    {
        yield 'Rounded' => [BorderStyle::Rounded, '╭', '╮', '╰', '╯', '─', '│'];
        yield 'Solid' => [BorderStyle::Solid, '┌', '┐', '└', '┘', '─', '│'];
        yield 'Double' => [BorderStyle::Double, '╔', '╗', '╚', '╝', '═', '║'];
        yield 'Dashed' => [BorderStyle::Dashed, '┌', '┐', '└', '┘', '╌', '╎'];
        yield 'Dotted' => [BorderStyle::Dotted, '┌', '┐', '└', '┘', '┈', '┊'];
    }

    #[Test]
    #[DataProvider('unicodeGlyphsProvider')]
    public function unicodeGlyphsAreCorrect(
        BorderStyle $style,
        string $topLeft,
        string $topRight,
        string $bottomLeft,
        string $bottomRight,
        string $horizontal,
        string $vertical,
    ): void {
        $glyphs = $style->glyphs(unicode: true);

        self::assertInstanceOf(BorderGlyphs::class, $glyphs);
        self::assertSame($topLeft, $glyphs->topLeft);
        self::assertSame($topRight, $glyphs->topRight);
        self::assertSame($bottomLeft, $glyphs->bottomLeft);
        self::assertSame($bottomRight, $glyphs->bottomRight);
        self::assertSame($horizontal, $glyphs->horizontal);
        self::assertSame($vertical, $glyphs->vertical);
    }

    /** @return iterable<string, array{BorderStyle}> */
    public static function visibleStylesProvider(): iterable
    {
        foreach (BorderStyle::cases() as $style) {
            if (BorderStyle::None !== $style) {
                yield $style->name => [$style];
            }
        }
    }

    #[Test]
    #[DataProvider('visibleStylesProvider')]
    public function allStylesReturnAsciiFallback(BorderStyle $style): void
    {
        $glyphs = $style->glyphs(unicode: false);

        self::assertSame('+', $glyphs->topLeft);
        self::assertSame('+', $glyphs->topRight);
        self::assertSame('+', $glyphs->bottomLeft);
        self::assertSame('+', $glyphs->bottomRight);
        self::assertSame('-', $glyphs->horizontal);
        self::assertSame('|', $glyphs->vertical);
    }

    #[Test]
    public function glyphsDefaultsToUnicode(): void
    {
        $glyphs = BorderStyle::Rounded->glyphs();

        self::assertSame('╭', $glyphs->topLeft);
    }

    #[Test]
    #[DataProvider('visibleStylesProvider')]
    public function visibleStylesHaveThicknessOne(BorderStyle $style): void
    {
        self::assertSame(1, $style->thickness());
    }

    #[Test]
    public function noneHasThicknessZero(): void
    {
        self::assertSame(0, BorderStyle::None->thickness());
    }

    #[Test]
    public function noneReturnsEmptyGlyphs(): void
    {
        $glyphs = BorderStyle::None->glyphs();

        self::assertSame('', $glyphs->topLeft);
        self::assertSame('', $glyphs->topRight);
        self::assertSame('', $glyphs->bottomLeft);
        self::assertSame('', $glyphs->bottomRight);
        self::assertSame('', $glyphs->horizontal);
        self::assertSame('', $glyphs->vertical);
    }

    #[Test]
    public function borderedStylesReturnZeroBadgeExtraWidth(): void
    {
        self::assertSame(0, BorderStyle::Rounded->badgeExtraWidth(1));
        self::assertSame(0, BorderStyle::Solid->badgeExtraWidth(2));
        self::assertSame(0, BorderStyle::Double->badgeExtraWidth(3));
    }

    #[Test]
    public function noneReturnsBadgeExtraWidthIncludingParentheses(): void
    {
        self::assertSame(4, BorderStyle::None->badgeExtraWidth(1));
        self::assertSame(6, BorderStyle::None->badgeExtraWidth(3));
    }

    #[Test]
    public function zeroBadgeWidthReturnsZeroForAllStyles(): void
    {
        foreach (BorderStyle::cases() as $style) {
            self::assertSame(0, $style->badgeExtraWidth(0), sprintf('%s should return 0 for zero badge width', $style->name));
        }
    }
}
