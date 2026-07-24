<?php

declare(strict_types=1);

namespace PhpDag\Layout;

interface CrossingMinimization
{
    public function minimize(LayoutGraph $graph): void;
}
