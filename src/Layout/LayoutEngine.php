<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Graph\Graph;

final class LayoutEngine
{
    public function __construct(
        private readonly Pipeline $pipeline,
    ) {
    }

    public function layout(Graph $graph): LayoutGraph
    {
        $layoutGraph = LayoutGraph::fromGraph($graph);
        $this->pipeline->execute($layoutGraph);

        return $layoutGraph;
    }
}
