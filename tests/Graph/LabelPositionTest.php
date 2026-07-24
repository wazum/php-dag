<?php

declare(strict_types=1);

namespace PhpDag\Tests\Graph;

use PhpDag\Graph\LabelPosition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LabelPositionTest extends TestCase
{
    #[Test]
    public function hasThreeCases(): void
    {
        $cases = LabelPosition::cases();
        self::assertCount(3, $cases);
        self::assertSame('Source', LabelPosition::Source->name);
        self::assertSame('Middle', LabelPosition::Middle->name);
        self::assertSame('Target', LabelPosition::Target->name);
    }
}
