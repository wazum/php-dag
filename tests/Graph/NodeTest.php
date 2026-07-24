<?php

declare(strict_types=1);

namespace PhpDag\Tests\Graph;

use InvalidArgumentException;
use PhpDag\Graph\Badge;
use PhpDag\Graph\Node;
use PhpDag\Graph\NodeStyle;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\BorderStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NodeTest extends TestCase
{
    #[Test]
    public function constructsWithTitleOnly(): void
    {
        $node = new Node('A', 'Hello');

        self::assertSame('A', $node->id);
        self::assertSame('Hello', $node->title);
        self::assertSame([], $node->body);
        self::assertInstanceOf(NodeStyle::class, $node->style);
    }

    #[Test]
    public function constructsWithTitleBodyAndStyle(): void
    {
        $style = new NodeStyle(borderStyle: BorderStyle::Double, titleBodySeparator: true);
        $node = new Node('X', 'Title', ['line1', 'line2'], $style);

        self::assertSame('X', $node->id);
        self::assertSame('Title', $node->title);
        self::assertSame(['line1', 'line2'], $node->body);
        self::assertSame($style, $node->style);
    }

    #[Test]
    public function rejectsEmptyTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Node('A', '');
    }

    #[Test]
    public function rejectsControlCharactersInId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Node("a\0b", 'Title');
    }

    #[Test]
    public function rejectsControlCharactersInTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Node('a', "safe\nbroken");
    }

    #[Test]
    public function rejectsControlCharactersInBody(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Node('a', 'Title', ['fine', "line\twith tab"]);
    }

    #[Test]
    public function rejectsInvalidUtf8(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Node('a', "broken\xFF");
    }

    /** @return iterable<string, array{Node, int}> */
    public static function contentWidthProvider(): iterable
    {
        yield 'title only' => [new Node('A', 'Hello'), 5];
        yield 'body wider than title' => [new Node('A', 'Hi', ['much longer line']), 16];
        yield 'max across title and body' => [new Node('A', 'short', ['much longer']), 11];
        yield 'wide characters use visual width' => [new Node('A', '日本語'), 6];
    }

    #[Test]
    #[DataProvider('contentWidthProvider')]
    public function contentWidthReturnsVisualWidth(Node $node, int $expected): void
    {
        self::assertSame($expected, $node->contentWidth());
    }

    /** @return iterable<string, array{Node, int}> */
    public static function contentHeightProvider(): iterable
    {
        yield 'title only' => [new Node('A', 'Hello'), 1];
        yield 'title and body without separator' => [new Node('A', 'Title', ['line1', 'line2']), 3];
        yield 'title and body with separator' => [new Node('A', 'Title', ['line1', 'line2'], new NodeStyle(titleBodySeparator: true)), 4];
        yield 'separator ignored when body is empty' => [new Node('A', 'Title', [], new NodeStyle(titleBodySeparator: true)), 1];
    }

    #[Test]
    #[DataProvider('contentHeightProvider')]
    public function contentHeightReturnsCorrectValue(Node $node, int $expected): void
    {
        self::assertSame($expected, $node->contentHeight());
    }

    #[Test]
    public function boxWidthAddsBorderAndPadding(): void
    {
        $node = new Node('A', 'Test');

        self::assertSame(8, $node->boxWidth());
    }

    #[Test]
    public function boxHeightAddsBorders(): void
    {
        $node = new Node('A', 'Title', ['body']);

        self::assertSame(4, $node->boxHeight());
    }

    #[Test]
    public function boxSizingWithNoBorderOmitsBorderThickness(): void
    {
        $node = new Node('A', 'Test', [], new NodeStyle(borderStyle: BorderStyle::None));

        self::assertSame(6, $node->boxWidth());
        self::assertSame(1, $node->boxHeight());
    }

    #[Test]
    public function boxWidthWithBadgeViaBorderStyle(): void
    {
        $style = new NodeStyle(badge: new Badge('★'));
        $node = new Node('A', 'Client', [], $style);

        $withoutBadge = new Node('A', 'Client');
        self::assertSame($withoutBadge->boxWidth(), $node->boxWidth());
    }

    #[Test]
    public function boxWidthWidensForBorderlessNodeWithBadge(): void
    {
        $style = new NodeStyle(borderStyle: BorderStyle::None, badge: new Badge('★'));
        $node = new Node('A', 'Test', [], $style);

        self::assertSame(10, $node->boxWidth());
    }

    #[Test]
    public function boxHeightUnchangedWithBadge(): void
    {
        $withoutBadge = new Node('A', 'Title', ['body']);
        $style = new NodeStyle(badge: new Badge('★'));
        $withBadge = new Node('A', 'Title', ['body'], $style);

        self::assertSame($withoutBadge->boxHeight(), $withBadge->boxHeight());
    }

    #[Test]
    public function withColorReturnsNewNodeWithColor(): void
    {
        $node = new Node('A', 'Alpha');
        $colored = $node->withColor(AnsiColor::Blue);

        self::assertNull($node->color);
        self::assertSame(AnsiColor::Blue, $colored->color);
        self::assertSame('A', $colored->id);
        self::assertSame('Alpha', $colored->title);
    }
}
