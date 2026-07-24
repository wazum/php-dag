<?php

declare(strict_types=1);

namespace PhpDag\Layout;

interface NodePositioning
{
    public function position(LayoutGraph $graph): void;
}
