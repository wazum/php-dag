<?php

declare(strict_types=1);

namespace PhpDag\Layout;

interface Processor
{
    public function process(LayoutGraph $graph): void;
}
