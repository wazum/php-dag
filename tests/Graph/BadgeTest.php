<?php

declare(strict_types=1);

namespace PhpDag\Tests\Graph;

use InvalidArgumentException;
use PhpDag\Graph\Badge;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BadgeTest extends TestCase
{
    #[Test]
    public function rejectsEmptyText(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Badge('');
    }

    #[Test]
    public function rejectsTextLongerThanThreeCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Badge('ABCD');
    }

    #[Test]
    public function rejectsControlCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Badge("A\x1B");
    }

    #[Test]
    public function constructsWithValidText(): void
    {
        $badge = new Badge('★');

        self::assertSame('★', $badge->text);
    }

    #[Test]
    public function widthReturnsSingleCharLength(): void
    {
        $badge = new Badge('A');

        self::assertSame(1, $badge->width());
    }

    #[Test]
    public function widthHandlesMultibyteCharacters(): void
    {
        self::assertSame(1, (new Badge('★'))->width());
        self::assertSame(2, (new Badge('★✓'))->width());
        self::assertSame(3, (new Badge('⚠️✓'))->width());
    }

    #[Test]
    public function widthUsesVisualWidthForWideCharacters(): void
    {
        self::assertSame(4, (new Badge('文字'))->width());
    }
}
