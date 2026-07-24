<?php

declare(strict_types=1);

namespace PhpDag\Tests\Render;

use PhpDag\Render\Waypoint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WaypointTest extends TestCase
{
    #[Test]
    public function constructsWithRowAndColumn(): void
    {
        $waypoint = new Waypoint(row: 3, column: 7);

        self::assertSame(3, $waypoint->row);
        self::assertSame(7, $waypoint->column);
    }
}
