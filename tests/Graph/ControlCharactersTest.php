<?php

declare(strict_types=1);

namespace PhpDag\Tests\Graph;

use PhpDag\Graph\ControlCharacters;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ControlCharactersTest extends TestCase
{
    #[Test]
    public function detectsC0AndC1ControlsAndInvalidUtf8(): void
    {
        self::assertFalse(ControlCharacters::present('safe 日本語'));
        self::assertTrue(ControlCharacters::present("line\nbreak"));
        self::assertTrue(ControlCharacters::present("\u{009B}31m"));
        self::assertTrue(ControlCharacters::present("\xFF"));
    }

    #[Test]
    public function stripsControlsAndScrubsInvalidUtf8(): void
    {
        $stripped = ControlCharacters::strip("safe\x1B\u{009B}\xFFtext");

        self::assertFalse(ControlCharacters::present($stripped));
        self::assertStringStartsWith('safe', $stripped);
        self::assertStringEndsWith('text', $stripped);
    }

    #[Test]
    public function escapesControlsForTerminalFacingDiagnostics(): void
    {
        self::assertSame('bad\\u{001B}\\u{009B}', ControlCharacters::escape("bad\x1B\u{009B}"));
    }
}
