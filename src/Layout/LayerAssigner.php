<?php

declare(strict_types=1);

namespace PhpDag\Layout;

final readonly class LayerAssigner implements Processor
{
    public function __construct(
        private LayerAssignment $strategy = new LongestPathLayering(),
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        $layers = $this->strategy->assign($graph);
        foreach ($layers as $nodeId => $layer) {
            $graph->getLayoutNode(strval($nodeId))->layer = $layer;
        }
        $graph->buildLayerIndex();
    }
}
