<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Layout\FlowDirection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FlowDirectionTest extends TestCase
{
    #[Test]
    public function hasTwoExactCases(): void
    {
        $cases = FlowDirection::cases();
        self::assertCount(2, $cases);
        self::assertSame('TopToBottom', FlowDirection::TopToBottom->name);
        self::assertSame('LeftToRight', FlowDirection::LeftToRight->name);
    }
}
