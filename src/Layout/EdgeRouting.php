<?php

declare(strict_types=1);

namespace PhpDag\Layout;

interface EdgeRouting
{
    public function route(LayoutGraph $graph): void;
}
