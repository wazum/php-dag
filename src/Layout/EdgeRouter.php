<?php

declare(strict_types=1);

namespace PhpDag\Layout;

final readonly class EdgeRouter implements Processor
{
    public function __construct(
        private EdgeRouting $strategy = new ChainAwareRouting(),
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        $this->strategy->route($graph);
    }
}
