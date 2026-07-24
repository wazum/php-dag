<?php

declare(strict_types=1);

namespace PhpDag\Render;

final class Direction
{
    public const UP = 1;
    public const RIGHT = 2;
    public const DOWN = 4;
    public const LEFT = 8;
    public const INTERSECTION = 15;
}
