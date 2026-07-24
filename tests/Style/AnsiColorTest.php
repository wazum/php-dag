<?php

declare(strict_types=1);

namespace PhpDag\Tests\Style;

use PhpDag\Style\AnsiColor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnsiColorTest extends TestCase
{
    #[Test]
    public function redHasCorrectEscapeCode(): void
    {
        self::assertSame("\033[31m", AnsiColor::Red->escapeCode());
    }

    #[Test]
    public function brightCyanHasCorrectEscapeCode(): void
    {
        self::assertSame("\033[96m", AnsiColor::BrightCyan->escapeCode());
    }

    #[Test]
    public function resetCodeIsCorrect(): void
    {
        self::assertSame("\033[0m", AnsiColor::resetCode());
    }
}
