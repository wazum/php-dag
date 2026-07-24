<?php

declare(strict_types=1);

namespace PhpDag\Tests\Render;

use InvalidArgumentException;
use PhpDag\Render\Cell;
use PhpDag\Render\Direction;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CellTest extends TestCase
{
    #[Test]
    public function newCellHasSpaceAndZeroDirectionMask(): void
    {
        $cell = new Cell();

        self::assertSame(' ', $cell->resolvedCharacter());
    }

    #[Test]
    public function putCharacterSetsCharAndClearsDirectionMask(): void
    {
        $cell = new Cell();

        $cell->putCharacter(character: 'X', zIndex: 10);

        self::assertSame('X', $cell->resolvedCharacter());
    }

    #[Test]
    public function resolvesJunctionWithAsciiGlyphsWhenUnicodeIsDisabled(): void
    {
        $cell = new Cell(unicodeGlyphs: false);

        $cell->markEdgePassthrough(Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('|', $cell->resolvedCharacter());
    }

    #[Test]
    public function markEdgePassthroughSetsMaskAndClearsCharacter(): void
    {
        $cell = new Cell();

        $cell->markEdgePassthrough(bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('│', $cell->resolvedCharacter());
    }

    #[Test]
    public function singleEdgeResolvesToJunction(): void
    {
        $cell = new Cell();

        $cell->markEdgePassthrough(bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('│', $cell->resolvedCharacter());
    }

    #[Test]
    public function markEdgePassthroughAccumulatesBitsAtSameZ(): void
    {
        $cell = new Cell();

        $cell->markEdgePassthrough(bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $cell->markEdgePassthrough(bits: Direction::LEFT | Direction::RIGHT, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('┼', $cell->resolvedCharacter());
    }

    #[Test]
    public function markEdgePassthroughAtHigherZReplacesLowerZ(): void
    {
        $cell = new Cell();

        $cell->markEdgePassthrough(bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $cell->markEdgePassthrough(bits: Direction::LEFT | Direction::RIGHT, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 10);

        self::assertSame('─', $cell->resolvedCharacter());
    }

    #[Test]
    public function markEdgePassthroughAtLowerZIsIgnored(): void
    {
        $cell = new Cell();

        $cell->markEdgePassthrough(bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 10);
        $cell->markEdgePassthrough(bits: Direction::LEFT | Direction::RIGHT, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('│', $cell->resolvedCharacter());
    }

    #[Test]
    public function putCharacterOverridesDirectionAtHigherZ(): void
    {
        $cell = new Cell();

        $cell->markEdgePassthrough(bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $cell->putCharacter(character: '┌', zIndex: 10);

        self::assertSame('┌', $cell->resolvedCharacter());
    }

    #[Test]
    public function putCharacterOverridesDirectionAtSameZ(): void
    {
        $cell = new Cell();

        $cell->markEdgePassthrough(bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $cell->putCharacter(character: 'A', zIndex: 5);

        self::assertSame('A', $cell->resolvedCharacter());
    }

    #[Test]
    public function putCharacterAtLowerZIsIgnored(): void
    {
        $cell = new Cell();

        $cell->markEdgePassthrough(bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 10);
        $cell->putCharacter(character: 'X', zIndex: 5);

        self::assertSame('│', $cell->resolvedCharacter());
    }

    #[Test]
    public function putCharacterClearsEdgeLayers(): void
    {
        $cell = new Cell();

        $cell->markEdgePassthrough(bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $cell->markEdgePassthrough(bits: Direction::LEFT | Direction::RIGHT, edgeId: 2, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $cell->putCharacter(character: 'X', zIndex: 10);

        self::assertSame('X', $cell->resolvedCharacter());
    }

    #[Test]
    public function higherZReplacesAllEdgeLayers(): void
    {
        $cell = new Cell();

        $cell->markEdgePassthrough(bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $cell->markEdgePassthrough(bits: Direction::LEFT | Direction::RIGHT, edgeId: 2, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 10);

        self::assertSame('─', $cell->resolvedCharacter());
    }

    #[Test]
    public function differentEdgesMergeBitsIntoJunction(): void
    {
        $cell = new Cell();

        $cell->markEdgePassthrough(bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $cell->markEdgePassthrough(bits: Direction::LEFT | Direction::RIGHT, edgeId: 2, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('┼', $cell->resolvedCharacter());
    }

    #[Test]
    public function threeEdgesMergeBitsIntoJunction(): void
    {
        $cell = new Cell();

        $cell->markEdgePassthrough(bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $cell->markEdgePassthrough(bits: Direction::LEFT | Direction::RIGHT, edgeId: 2, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
        $cell->markEdgePassthrough(bits: Direction::UP | Direction::RIGHT, edgeId: 3, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);

        self::assertSame('┼', $cell->resolvedCharacter());
    }

    #[Test]
    public function singleEdgeResolvesUsingItsOwnStrokeStyle(): void
    {
        $cell = new Cell();

        $cell->markEdgePassthrough(bits: Direction::UP | Direction::DOWN, edgeId: 1, strokeStyle: EdgeStrokeStyle::Dashed, zIndex: 5);

        self::assertSame('╎', $cell->resolvedCharacter());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidBitmaskProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'high bits set' => [0b10000];
        yield 'garbage' => [255];
    }

    #[Test]
    public function putCharacterStoresColor(): void
    {
        $cell = new Cell();
        $cell->putCharacter('X', 10, AnsiColor::Red);

        self::assertSame(AnsiColor::Red, $cell->resolvedColor());
    }

    #[Test]
    public function edgePassthroughStoresColor(): void
    {
        $cell = new Cell();
        $cell->markEdgePassthrough(Direction::DOWN, 1, EdgeStrokeStyle::Solid, 5, AnsiColor::Green);

        self::assertSame(AnsiColor::Green, $cell->resolvedColor());
    }

    #[Test]
    public function resolvedColorIsNullByDefault(): void
    {
        $cell = new Cell();
        self::assertNull($cell->resolvedColor());
    }

    #[Test]
    public function resolvedColorIsNullWhenNoColorSet(): void
    {
        $cell = new Cell();
        $cell->putCharacter('X', 10);
        self::assertNull($cell->resolvedColor());
    }

    #[Test]
    public function wouldAcceptWriteReturnsFalseWhenZIndexIsLowerThanExisting(): void
    {
        $cell = new Cell();
        $cell->putCharacter('╰', 10);

        self::assertFalse($cell->wouldAcceptWrite(8));
    }

    #[Test]
    public function wouldAcceptWriteReturnsTrueWhenZIndexEqualsExisting(): void
    {
        $cell = new Cell();
        $cell->putCharacter('╰', 10);

        self::assertTrue($cell->wouldAcceptWrite(10));
    }

    #[Test]
    public function edgePassthroughUpdatesColorForSameEdge(): void
    {
        $cell = new Cell();
        $cell->markEdgePassthrough(Direction::UP, 1, EdgeStrokeStyle::Solid, 5, AnsiColor::Red);
        $cell->markEdgePassthrough(Direction::DOWN, 1, EdgeStrokeStyle::Solid, 5, AnsiColor::Cyan);

        self::assertSame(AnsiColor::Cyan, $cell->resolvedColor());
    }

    #[Test]
    public function crossingWithEqualStrokePriorityKeepsFirstEdgeColor(): void
    {
        $cell = new Cell();
        $cell->markEdgePassthrough(Direction::UP | Direction::DOWN, 1, EdgeStrokeStyle::Solid, 5, AnsiColor::Red);
        $cell->markEdgePassthrough(Direction::LEFT | Direction::RIGHT, 2, EdgeStrokeStyle::Solid, 5, AnsiColor::Cyan);

        self::assertSame(AnsiColor::Red, $cell->resolvedColor());
    }

    #[Test]
    public function coloredEdgeOverridesUncoloredEdgeAtEqualPriority(): void
    {
        $cell = new Cell();
        $cell->markEdgePassthrough(Direction::UP | Direction::DOWN, 1, EdgeStrokeStyle::Solid, 5);
        $cell->markEdgePassthrough(Direction::LEFT | Direction::RIGHT, 2, EdgeStrokeStyle::Dashed, 5, AnsiColor::Red);

        self::assertSame(AnsiColor::Red, $cell->resolvedColor());
    }

    #[Test]
    public function lowerPriorityColoredEdgeDoesNotOverrideHigherPriorityUncoloredEdge(): void
    {
        $cell = new Cell();
        $cell->markEdgePassthrough(Direction::UP | Direction::DOWN, 1, EdgeStrokeStyle::Heavy, 5);
        $cell->markEdgePassthrough(Direction::LEFT | Direction::RIGHT, 2, EdgeStrokeStyle::Solid, 5, AnsiColor::Red);

        self::assertNull($cell->resolvedColor());
    }

    #[Test]
    public function higherStrokePriorityColorWinsAtCrossing(): void
    {
        $cell = new Cell();
        $cell->markEdgePassthrough(Direction::UP | Direction::DOWN, 1, EdgeStrokeStyle::Solid, 5, AnsiColor::Red);
        $cell->markEdgePassthrough(Direction::LEFT | Direction::RIGHT, 2, EdgeStrokeStyle::Heavy, 5, AnsiColor::Cyan);

        self::assertSame(AnsiColor::Cyan, $cell->resolvedColor());
    }

    #[Test]
    #[DataProvider('invalidBitmaskProvider')]
    public function markEdgePassthroughRejectsInvalidBitmask(int $bits): void
    {
        $cell = new Cell();

        $this->expectException(InvalidArgumentException::class);
        $cell->markEdgePassthrough(bits: $bits, edgeId: 1, strokeStyle: EdgeStrokeStyle::Solid, zIndex: 5);
    }
}
