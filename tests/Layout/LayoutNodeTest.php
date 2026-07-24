<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Node;
use PhpDag\Layout\RealLayoutNode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LayoutNodeTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $node = new Node('A', 'Alpha');
        $layoutNode = new RealLayoutNode(id: 'A', node: $node);

        self::assertSame('A', $layoutNode->id);
        self::assertSame($node, $layoutNode->node);
        self::assertSame(-1, $layoutNode->layer);
        self::assertSame(0, $layoutNode->row);
        self::assertSame(0, $layoutNode->column);
    }

    #[Test]
    public function delegatesBoxDimensionsToNode(): void
    {
        $node = new Node('A', 'Alpha');
        $layoutNode = new RealLayoutNode(id: 'A', node: $node);

        self::assertSame($node->boxWidth(), $layoutNode->boxWidth());
        self::assertSame($node->boxHeight(), $layoutNode->boxHeight());
    }

    #[Test]
    public function boxHeightGrowsToTheMinimumWhenSet(): void
    {
        $node = new Node('A', 'Alpha');
        $layoutNode = new RealLayoutNode(id: 'A', node: $node);

        $layoutNode->minBoxHeight = $node->boxHeight() + 2;

        self::assertSame($node->boxHeight() + 2, $layoutNode->boxHeight());
    }

    #[Test]
    public function boxHeightKeepsTheNaturalHeightWhenTheMinimumIsSmaller(): void
    {
        $node = new Node('A', 'Alpha');
        $layoutNode = new RealLayoutNode(id: 'A', node: $node);

        $layoutNode->minBoxHeight = 1;

        self::assertSame($node->boxHeight(), $layoutNode->boxHeight());
    }
}
