<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Layout\DummyLayoutNode;
use PhpDag\Layout\LayoutNode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DummyLayoutNodeTest extends TestCase
{
    #[Test]
    public function extendsLayoutNode(): void
    {
        $dummy = new DummyLayoutNode('__dummy_A_B_1', 'A', 'B');
        self::assertInstanceOf(LayoutNode::class, $dummy);
    }

    #[Test]
    public function hasFixedUnitDimensions(): void
    {
        $dummy = new DummyLayoutNode('__dummy_A_B_1', 'A', 'B');
        self::assertSame(1, $dummy->boxWidth());
        self::assertSame(1, $dummy->boxHeight());
    }

    #[Test]
    public function defaultsToNotReversedOriginalEdge(): void
    {
        $dummy = new DummyLayoutNode('__dummy_A_B_1', 'A', 'B');
        self::assertFalse($dummy->originalEdgeReversed);
    }

    #[Test]
    public function storesOriginalEdgeEndpoints(): void
    {
        $dummy = new DummyLayoutNode('__dummy_X_Y_2', 'X', 'Y');
        self::assertSame('__dummy_X_Y_2', $dummy->id);
        self::assertSame('X', $dummy->originalEdgeSourceId);
        self::assertSame('Y', $dummy->originalEdgeTargetId);
    }
}
