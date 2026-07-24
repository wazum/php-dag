<?php

declare(strict_types=1);

namespace PhpDag\Tests\Graph;

use PhpDag\Graph\Badge;
use PhpDag\Graph\ContentAlignment;
use PhpDag\Graph\NodeStyle;
use PhpDag\Style\BorderStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NodeStyleTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $style = new NodeStyle();

        self::assertSame(BorderStyle::Rounded, $style->borderStyle);
        self::assertSame(ContentAlignment::Center, $style->titleAlignment);
        self::assertSame(ContentAlignment::Left, $style->bodyAlignment);
        self::assertFalse($style->titleBodySeparator);
    }

    #[Test]
    public function badgeDefaultsToNull(): void
    {
        $style = new NodeStyle();

        self::assertNull($style->badge);
    }

    #[Test]
    public function constructsWithCustomValues(): void
    {
        $badge = new Badge('★');
        $style = new NodeStyle(
            borderStyle: BorderStyle::Double,
            badge: $badge,
            titleAlignment: ContentAlignment::Left,
            bodyAlignment: ContentAlignment::Right,
            titleBodySeparator: true,
        );

        self::assertSame(BorderStyle::Double, $style->borderStyle);
        self::assertSame($badge, $style->badge);
        self::assertSame(ContentAlignment::Left, $style->titleAlignment);
        self::assertSame(ContentAlignment::Right, $style->bodyAlignment);
        self::assertTrue($style->titleBodySeparator);
    }
}
