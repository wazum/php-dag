<?php

declare(strict_types=1);

namespace PhpDag\Layout;

final readonly class CrossingMinimizer implements Processor
{
    public function __construct(
        private CrossingMinimization $strategy = new MedianOrdering(),
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        $this->strategy->minimize($graph);
    }
}
