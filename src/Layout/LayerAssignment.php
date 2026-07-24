<?php

declare(strict_types=1);

namespace PhpDag\Layout;

interface LayerAssignment
{
    /** @return array<string, int> nodeId => layer (0 = sources/top) */
    public function assign(LayoutGraph $graph): array;
}
