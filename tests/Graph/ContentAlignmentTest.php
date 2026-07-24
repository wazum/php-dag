<?php

declare(strict_types=1);

namespace PhpDag\Tests\Graph;

use PhpDag\Graph\ContentAlignment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentAlignmentTest extends TestCase
{
    /** @return iterable<string, array{ContentAlignment, int}> */
    public static function padTypeProvider(): iterable
    {
        yield 'Left pads right' => [ContentAlignment::Left, STR_PAD_RIGHT];
        yield 'Center pads both' => [ContentAlignment::Center, STR_PAD_BOTH];
        yield 'Right pads left' => [ContentAlignment::Right, STR_PAD_LEFT];
    }

    #[Test]
    #[DataProvider('padTypeProvider')]
    public function padTypeReturnsCorrectConstant(ContentAlignment $alignment, int $expectedPadType): void
    {
        self::assertSame($expectedPadType, $alignment->padType());
    }
}
