<?php

declare(strict_types=1);

namespace PhpDag\Render;

use PhpDag\Layout\LayoutGraph;

interface ElementRenderer
{
    public function render(Canvas $canvas, LayoutGraph $graph): void;
}
