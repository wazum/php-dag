<?php

declare(strict_types=1);

namespace PhpDag\Layout;

final readonly class NodePositioner implements Processor
{
    public function __construct(
        private NodePositioning $strategy = new BrandesKopfPositioning(),
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        $this->strategy->position($graph);
    }
}
