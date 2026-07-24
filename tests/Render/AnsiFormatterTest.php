<?php

declare(strict_types=1);

namespace PhpDag\Tests\Render;

use PhpDag\Render\AnsiFormatter;
use PhpDag\Render\Canvas;
use PhpDag\Render\OutputFormatter;
use PhpDag\Render\PlainTextFormatter;
use PhpDag\Style\AnsiColor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnsiFormatterTest extends TestCase
{
    #[Test]
    public function implementsOutputFormatterInterface(): void
    {
        self::assertInstanceOf(OutputFormatter::class, new AnsiFormatter());
    }

    #[Test]
    public function formatsUncoloredCanvasIdenticallyToPlainText(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(0, 0, 'A', 10);
        $canvas->putCharacter(0, 1, 'B', 10);
        $canvas->putCharacter(1, 0, 'C', 10);

        $plain = (new PlainTextFormatter())->format($canvas);
        $ansi = (new AnsiFormatter())->format($canvas);

        self::assertSame($plain, $ansi);
    }

    #[Test]
    public function stripsLeadingBlankLinesLikePlainTextFormatter(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(2, 0, 'A', 10);

        $plain = (new PlainTextFormatter())->format($canvas);
        $ansi = (new AnsiFormatter())->format($canvas);

        self::assertSame($plain, $ansi);
    }

    #[Test]
    public function wrapsColoredCellInEscapeCodes(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(0, 0, 'X', 10, AnsiColor::Red);

        $result = (new AnsiFormatter())->format($canvas);

        self::assertSame("\033[31mX\033[0m", $result);
    }

    #[Test]
    public function batchesAdjacentSameColorCells(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(0, 0, 'A', 10, AnsiColor::Red);
        $canvas->putCharacter(0, 1, 'B', 10, AnsiColor::Red);
        $canvas->putCharacter(0, 2, 'C', 10, AnsiColor::Red);

        $result = (new AnsiFormatter())->format($canvas);

        self::assertSame("\033[31mABC\033[0m", $result);
    }

    #[Test]
    public function handlesColorTransitions(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(0, 0, 'R', 10, AnsiColor::Red);
        $canvas->putCharacter(0, 1, 'G', 10, AnsiColor::Green);
        $canvas->putCharacter(0, 2, 'N', 10);

        $result = (new AnsiFormatter())->format($canvas);

        self::assertSame("\033[31mR\033[0m\033[32mG\033[0mN", $result);
    }

    #[Test]
    public function returnsEmptyStringForEmptyCanvas(): void
    {
        $canvas = new Canvas();

        $result = (new AnsiFormatter())->format($canvas);

        self::assertSame('', $result);
    }

    #[Test]
    public function stripsTrailingColoredSpaces(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(0, 0, 'A', 10, AnsiColor::Red);
        $canvas->putCharacter(0, 1, ' ', 10, AnsiColor::Red);
        $canvas->putCharacter(0, 2, ' ', 10, AnsiColor::Red);

        $result = (new AnsiFormatter())->format($canvas);

        self::assertSame("\033[31mA\033[0m", $result);
    }

    #[Test]
    public function handlesMultipleRowsWithColors(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(0, 0, 'A', 10, AnsiColor::Blue);
        $canvas->putCharacter(0, 1, 'B', 10);
        $canvas->putCharacter(1, 0, 'C', 10);
        $canvas->putCharacter(1, 1, 'D', 10, AnsiColor::Cyan);

        $result = (new AnsiFormatter())->format($canvas);

        self::assertSame("\033[34mA\033[0mB\nC\033[36mD\033[0m", $result);
    }

    #[Test]
    public function stripsTrailingEmptyRows(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(0, 0, 'A', 10, AnsiColor::Red);
        $canvas->putCharacter(1, 0, ' ', 10);
        $canvas->putCharacter(2, 0, ' ', 10);

        $result = (new AnsiFormatter())->format($canvas);

        self::assertSame("\033[31mA\033[0m", $result);
    }
}
