<?php

declare(strict_types=1);

namespace PhpDag\Layout;

/**
 * Grows each box that ends a parallel (multi-) edge group until every edge on
 * its busiest side gets its own port, the way ELK's PORTS node-size constraint
 * enlarges a node to fit its ports. A side needs one port per parallel edge
 * plus one shared trunk for all its other edges; ports sit one cell apart, so
 * hosting N ports takes 2N−1 interior cells plus the two border cells.
 */
final readonly class ParallelPortReserver implements Processor
{
    public function __construct(
        private FlowDirection $flowDirection,
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        foreach (EdgePorts::portCounts($graph) as $nodeId => $ports) {
            $node = $graph->getLayoutNode(strval($nodeId));
            if (FlowDirection::LeftToRight === $this->flowDirection) {
                $node->minBoxHeight = 2 * $ports + 1;
            } elseif ($node instanceof RealLayoutNode) {
                $node->minBoxWidth = 2 * $ports + 1;
            }
        }
    }
}
