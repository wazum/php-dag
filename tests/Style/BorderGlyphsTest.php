<?php

declare(strict_types=1);

namespace PhpDag\Tests\Style;

use PhpDag\Style\BorderGlyphs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BorderGlyphsTest extends TestCase
{
    #[Test]
    public function constructsWithAllSixFields(): void
    {
        $glyphs = new BorderGlyphs(
            topLeft: '╭',
            topRight: '╮',
            bottomLeft: '╰',
            bottomRight: '╯',
            horizontal: '─',
            vertical: '│',
        );

        self::assertSame('╭', $glyphs->topLeft);
        self::assertSame('╮', $glyphs->topRight);
        self::assertSame('╰', $glyphs->bottomLeft);
        self::assertSame('╯', $glyphs->bottomRight);
        self::assertSame('─', $glyphs->horizontal);
        self::assertSame('│', $glyphs->vertical);
    }
}
