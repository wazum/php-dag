<?php

declare(strict_types=1);

namespace PhpDag\Layout;

final readonly class ParallelBendLaneReserver implements Processor
{
    public function __construct(
        private FlowDirection $flowDirection,
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        $graph->insertGapLanes(EdgePorts::of($graph, $this->flowDirection)->extraLanesByGap, $this->flowDirection);
    }
}
