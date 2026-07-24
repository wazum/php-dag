<?php

declare(strict_types=1);

namespace PhpDag\Tests\Render;

use PhpDag\Render\Canvas;
use PhpDag\Render\Direction;
use PhpDag\Render\PlainTextFormatter;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\BorderStyle;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CanvasTest extends TestCase
{
    #[Test]
    public function asciiModeCanvasResolvesJunctionsWithAsciiGlyphs(): void
    {
        $canvas = new Canvas(unicodeGlyphs: false);

        $canvas->markEdgePassthrough(row: 0, column: 0, bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('|', $canvas->get(0, 0)->resolvedCharacter());
    }

    #[Test]
    public function getReturnsDefaultCellForEmptyPosition(): void
    {
        $canvas = new Canvas();

        $cell = $canvas->get(row: 0, column: 0);

        self::assertSame(' ', $cell->resolvedCharacter());
    }

    #[Test]
    public function putSetsCharacterAtPosition(): void
    {
        $canvas = new Canvas();

        $canvas->putCharacter(row: 2, column: 3, character: 'X', zIndex: 10);

        self::assertSame('X', $canvas->get(row: 2, column: 3)->resolvedCharacter());
    }

    #[Test]
    public function markEdgePassthroughAcceptsEdgeId(): void
    {
        $canvas = new Canvas();

        $canvas->markEdgePassthrough(row: 1, column: 1, bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('│', $canvas->get(row: 1, column: 1)->resolvedCharacter());
    }

    #[Test]
    public function markEdgePassthroughSetsBitsAtPosition(): void
    {
        $canvas = new Canvas();

        $canvas->markEdgePassthrough(row: 1, column: 1, bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('│', $canvas->get(row: 1, column: 1)->resolvedCharacter());
    }

    #[Test]
    public function overlappingDirectionsMerge(): void
    {
        $canvas = new Canvas();

        $canvas->markEdgePassthrough(row: 1, column: 1, bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $canvas->markEdgePassthrough(row: 1, column: 1, bits: Direction::LEFT | Direction::RIGHT, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('┼', $canvas->get(row: 1, column: 1)->resolvedCharacter());
    }

    #[Test]
    public function horizontalLineDrawsLeftRightBits(): void
    {
        $canvas = new Canvas();

        $canvas->horizontalLine(row: 0, startColumn: 1, endColumn: 3, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('─', $canvas->get(row: 0, column: 1)->resolvedCharacter());
        self::assertSame('─', $canvas->get(row: 0, column: 2)->resolvedCharacter());
        self::assertSame('─', $canvas->get(row: 0, column: 3)->resolvedCharacter());
    }

    #[Test]
    public function verticalLineDrawsUpDownBits(): void
    {
        $canvas = new Canvas();

        $canvas->verticalLine(column: 0, startRow: 1, endRow: 3, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('│', $canvas->get(row: 1, column: 0)->resolvedCharacter());
        self::assertSame('│', $canvas->get(row: 2, column: 0)->resolvedCharacter());
        self::assertSame('│', $canvas->get(row: 3, column: 0)->resolvedCharacter());
    }

    #[Test]
    public function crossingLinesProduceCrossMask(): void
    {
        $canvas = new Canvas();

        $canvas->horizontalLine(row: 2, startColumn: 0, endColumn: 4, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $canvas->verticalLine(column: 2, startRow: 0, endRow: 4, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('┼', $canvas->get(row: 2, column: 2)->resolvedCharacter());
    }

    #[Test]
    public function boxDrawsWithBorderGlyphs(): void
    {
        $canvas = new Canvas();
        $borderGlyphs = BorderStyle::Rounded->glyphs();

        $canvas->box(row: 0, column: 0, width: 4, height: 3, glyphs: $borderGlyphs, zIndex: 10);

        self::assertSame('╭', $canvas->get(row: 0, column: 0)->resolvedCharacter());
        self::assertSame('─', $canvas->get(row: 0, column: 1)->resolvedCharacter());
        self::assertSame('─', $canvas->get(row: 0, column: 2)->resolvedCharacter());
        self::assertSame('╮', $canvas->get(row: 0, column: 3)->resolvedCharacter());
        self::assertSame('│', $canvas->get(row: 1, column: 0)->resolvedCharacter());
        self::assertSame('│', $canvas->get(row: 1, column: 3)->resolvedCharacter());
        self::assertSame('╰', $canvas->get(row: 2, column: 0)->resolvedCharacter());
        self::assertSame('─', $canvas->get(row: 2, column: 1)->resolvedCharacter());
        self::assertSame('─', $canvas->get(row: 2, column: 2)->resolvedCharacter());
        self::assertSame('╯', $canvas->get(row: 2, column: 3)->resolvedCharacter());
    }

    #[Test]
    public function textWritesCharactersLeftToRight(): void
    {
        $canvas = new Canvas();

        $canvas->text(row: 0, column: 1, text: 'Hi', zIndex: 8);

        self::assertSame('H', $canvas->get(row: 0, column: 1)->resolvedCharacter());
        self::assertSame('i', $canvas->get(row: 0, column: 2)->resolvedCharacter());
    }

    #[Test]
    public function textHandlesMultibyteCharacters(): void
    {
        $canvas = new Canvas();

        $canvas->text(row: 0, column: 0, text: '日本', zIndex: 1);

        self::assertSame(4, $canvas->width());
        self::assertSame('日本', (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function toStringResolvesDirectionsViaGlyphs(): void
    {
        $canvas = new Canvas();

        $canvas->horizontalLine(row: 1, startColumn: 0, endColumn: 2, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $canvas->verticalLine(column: 1, startRow: 0, endRow: 2, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        $expected = <<<'TEXT'
         │
        ─┼─
         │
        TEXT;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function toStringRightTrimsTrailingSpaces(): void
    {
        $canvas = new Canvas();

        $canvas->putCharacter(row: 0, column: 0, character: 'A', zIndex: 1);
        $canvas->putCharacter(row: 0, column: 5, character: 'B', zIndex: 1);

        self::assertSame('A    B', (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function toStringStripsTrailingEmptyLines(): void
    {
        $canvas = new Canvas();

        $canvas->putCharacter(row: 0, column: 0, character: 'A', zIndex: 1);
        $canvas->putCharacter(row: 2, column: 5, character: ' ', zIndex: 1);

        self::assertSame('A', (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function crossingLinesWithSameEdgeIdProduceJunction(): void
    {
        $canvas = new Canvas();

        $canvas->horizontalLine(row: 2, startColumn: 0, endColumn: 4, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $canvas->verticalLine(column: 2, startRow: 0, endRow: 4, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('┼', $canvas->get(row: 2, column: 2)->resolvedCharacter());
    }

    #[Test]
    public function crossingLinesWithDifferentEdgeIdsMergeBitsIntoJunction(): void
    {
        $canvas = new Canvas();

        $canvas->horizontalLine(row: 2, startColumn: 0, endColumn: 4, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $canvas->verticalLine(column: 2, startRow: 0, endRow: 4, edgeId: 2, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('┼', $canvas->get(row: 2, column: 2)->resolvedCharacter());
    }

    #[Test]
    public function horizontalAndVerticalLineAcceptEdgeId(): void
    {
        $canvas = new Canvas();

        $canvas->horizontalLine(row: 0, startColumn: 0, endColumn: 2, edgeId: 42, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $canvas->verticalLine(column: 4, startRow: 0, endRow: 2, edgeId: 99, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('─', $canvas->get(row: 0, column: 1)->resolvedCharacter());
        self::assertSame('│', $canvas->get(row: 1, column: 4)->resolvedCharacter());
    }

    #[Test]
    public function widthAndHeightReflectSparseContent(): void
    {
        $canvas = new Canvas();

        $canvas->putCharacter(row: 3, column: 7, character: 'X', zIndex: 1);

        self::assertSame(8, $canvas->width());
        self::assertSame(4, $canvas->height());
    }

    #[Test]
    public function emptyCanvasHasZeroDimensions(): void
    {
        $canvas = new Canvas();

        self::assertSame(0, $canvas->width());
        self::assertSame(0, $canvas->height());
    }

    #[Test]
    public function badgeRendersOnBorderedBoxViaTextOverlay(): void
    {
        $canvas = new Canvas();
        $borderGlyphs = BorderStyle::Rounded->glyphs();

        $canvas->box(row: 0, column: 0, width: 10, height: 3, glyphs: $borderGlyphs, zIndex: 10);
        $canvas->text(row: 0, column: 8, text: '★', zIndex: 10);
        $canvas->text(row: 1, column: 2, text: 'Client', zIndex: 10);

        $expected = <<<'TEXT'
        ╭───────★╮
        │ Client │
        ╰────────╯
        TEXT;

        self::assertSame($expected, (new PlainTextFormatter())->format($canvas));
    }

    #[Test]
    public function cellAtReturnsTheCellWithoutMaterializingMissingOnes(): void
    {
        $canvas = new Canvas();
        $canvas->putCharacter(row: 1, column: 2, character: 'X', zIndex: 1);

        self::assertSame('X', $canvas->cellAt(1, 2)?->resolvedCharacter());
        // Reading an empty cell returns null and must not create it, so the
        // canvas stays sparse (formatters scan the whole area cheaply).
        self::assertNull($canvas->cellAt(5, 5));
        self::assertSame(3, $canvas->width());
        self::assertSame(2, $canvas->height());
    }

    #[Test]
    public function textAdvancesByVisualWidthForWideCharacters(): void
    {
        $canvas = new Canvas();

        $canvas->text(row: 0, column: 0, text: '日本語', zIndex: 1);

        self::assertSame('日本語', (new PlainTextFormatter())->format($canvas));
        self::assertSame(6, $canvas->width());
    }

    #[Test]
    public function putCharacterPassesColorToCell(): void
    {
        $canvas = new Canvas();

        $canvas->putCharacter(0, 0, 'X', 10, AnsiColor::Red);

        self::assertSame(AnsiColor::Red, $canvas->get(0, 0)->resolvedColor());
    }

    #[Test]
    public function markEdgePassthroughPassesColorToCell(): void
    {
        $canvas = new Canvas();

        $canvas->markEdgePassthrough(0, 0, Direction::DOWN, 1, EdgeStrokeStyle::Solid, 5, AnsiColor::Blue);

        self::assertSame(AnsiColor::Blue, $canvas->get(0, 0)->resolvedColor());
    }

    #[Test]
    public function horizontalLinePassesColorToCell(): void
    {
        $canvas = new Canvas();

        $canvas->horizontalLine(0, 0, 2, 1, EdgeStrokeStyle::Solid, 5, AnsiColor::Green);

        self::assertSame(AnsiColor::Green, $canvas->get(0, 1)->resolvedColor());
    }

    #[Test]
    public function verticalLinePassesColorToCell(): void
    {
        $canvas = new Canvas();

        $canvas->verticalLine(0, 0, 2, 1, EdgeStrokeStyle::Solid, 5, AnsiColor::Yellow);

        self::assertSame(AnsiColor::Yellow, $canvas->get(1, 0)->resolvedColor());
    }

    #[Test]
    public function boxPassesColorToCell(): void
    {
        $canvas = new Canvas();

        $canvas->box(0, 0, 4, 3, BorderStyle::Rounded->glyphs(), 10, AnsiColor::Cyan);

        self::assertSame(AnsiColor::Cyan, $canvas->get(0, 0)->resolvedColor());
        self::assertSame(AnsiColor::Cyan, $canvas->get(0, 1)->resolvedColor());
        self::assertSame(AnsiColor::Cyan, $canvas->get(1, 0)->resolvedColor());
    }

    #[Test]
    public function textPassesColorToCell(): void
    {
        $canvas = new Canvas();

        $canvas->text(0, 0, 'Hi', 8, AnsiColor::Magenta);

        self::assertSame(AnsiColor::Magenta, $canvas->get(0, 0)->resolvedColor());
        self::assertSame(AnsiColor::Magenta, $canvas->get(0, 1)->resolvedColor());
    }

    #[Test]
    public function colorDefaultsToNullWhenNotProvided(): void
    {
        $canvas = new Canvas();

        $canvas->putCharacter(0, 0, 'X', 10);

        self::assertNull($canvas->get(0, 0)->resolvedColor());
    }
}
