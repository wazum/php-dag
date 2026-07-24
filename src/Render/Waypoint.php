<?php

declare(strict_types=1);

namespace PhpDag\Render;

final readonly class Waypoint
{
    public function __construct(
        public int $row,
        public int $column,
    ) {
    }
}
