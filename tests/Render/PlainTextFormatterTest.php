<?php

declare(strict_types=1);

namespace PhpDag\Tests\Render;

use PhpDag\Render\Canvas;
use PhpDag\Render\PlainTextFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlainTextFormatterTest extends TestCase
{
    #[Test]
    public function rendersContentPlacedLeftOfTheOriginShiftedIntoView(): void
    {
        $canvas = new Canvas();
        $canvas->text(0, 0, 'B', 10);
        $canvas->text(0, -3, 'hi', 8);

        // 'hi' occupies columns -3..-2, leaving -1 empty before 'B' at 0; the
        // whole row shifts right by 3 so nothing is lost off the left edge.
        self::assertSame('hi B', (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function rendersContentPlacedAboveTheOriginShiftedIntoView(): void
    {
        $canvas = new Canvas();
        $canvas->text(0, 0, 'B', 10);
        $canvas->text(-2, 0, 'T', 8);

        self::assertSame("T\n\nB", (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function dropsLeadingBlankRowsButKeepsInteriorOnes(): void
    {
        // Row 0 is written but renders empty (a lone space); real content is on
        // rows 2 and 4. The leading blank row must be dropped, the interior one
        // between the two content rows kept.
        $canvas = new Canvas();
        $canvas->putCharacter(0, 0, ' ', 5);
        $canvas->text(2, 0, 'X', 5);
        $canvas->text(4, 0, 'Y', 5);

        self::assertSame("X\n\nY", (new PlainTextFormatter())->format($canvas));
    }
}
