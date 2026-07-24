<?php

declare(strict_types=1);

namespace PhpDag\Tests\Graph;

use InvalidArgumentException;
use PhpDag\Graph\Edge;
use PhpDag\Graph\Label;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EdgeTest extends TestCase
{
    #[Test]
    public function constructsWithSourceAndTarget(): void
    {
        $edge = new Edge('A', 'B');

        self::assertSame('A', $edge->sourceId);
        self::assertSame('B', $edge->targetId);
        self::assertSame(EdgeStrokeStyle::Solid, $edge->edgeStrokeStyle);
        self::assertSame(1, $edge->weight);
        self::assertSame(1, $edge->minLength);
    }

    #[Test]
    public function constructsWithAllParameters(): void
    {
        $edge = new Edge('X', 'Y', EdgeStrokeStyle::Heavy, 3, 2);

        self::assertSame('X', $edge->sourceId);
        self::assertSame('Y', $edge->targetId);
        self::assertSame(EdgeStrokeStyle::Heavy, $edge->edgeStrokeStyle);
        self::assertSame(3, $edge->weight);
        self::assertSame(2, $edge->minLength);
    }

    #[Test]
    public function allowsSelfLoop(): void
    {
        $edge = new Edge('A', 'A');

        self::assertSame('A', $edge->sourceId);
        self::assertSame('A', $edge->targetId);
    }

    #[Test]
    public function rejectsControlCharactersInEndpointIds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Edge('A', "B\0");
    }

    #[Test]
    public function rejectsZeroWeight(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Edge('A', 'B', weight: 0);
    }

    #[Test]
    public function rejectsZeroMinLength(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Edge('A', 'B', minLength: 0);
    }

    #[Test]
    public function acceptsOptionalLabel(): void
    {
        $label = new Label('yes');
        $edge = new Edge('A', 'B', label: $label);
        self::assertSame($label, $edge->label);
    }

    #[Test]
    public function defaultsToNoLabel(): void
    {
        $edge = new Edge('A', 'B');
        self::assertNull($edge->label);
    }

    #[Test]
    public function withStrokeStyleReturnsNewEdgeWithUpdatedStyle(): void
    {
        $label = new Label('yes');
        $edge = new Edge('A', 'B', EdgeStrokeStyle::Solid, 2, 3, $label);

        $heavy = $edge->withStrokeStyle(EdgeStrokeStyle::Heavy);

        self::assertNotSame($edge, $heavy);
        self::assertSame('A', $heavy->sourceId);
        self::assertSame('B', $heavy->targetId);
        self::assertSame(EdgeStrokeStyle::Heavy, $heavy->edgeStrokeStyle);
        self::assertSame(2, $heavy->weight);
        self::assertSame(3, $heavy->minLength);
        self::assertSame($label, $heavy->label);
    }

    #[Test]
    public function withColorReturnsNewEdgeWithColor(): void
    {
        $edge = new Edge('A', 'B');
        $colored = $edge->withColor(AnsiColor::Red);

        self::assertNull($edge->color);
        self::assertSame(AnsiColor::Red, $colored->color);
        self::assertSame('A', $colored->sourceId);
        self::assertSame('B', $colored->targetId);
    }
}
