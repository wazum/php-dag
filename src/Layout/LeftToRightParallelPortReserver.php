<?php

declare(strict_types=1);

namespace PhpDag\Layout;

/**
 * Grows the boxes at both ends of a parallel (multi-) edge group so each edge
 * gets its own port row on the facing side, the way ELK's PORTS node-size
 * constraint enlarges a node to fit its ports. A single-line box is only three
 * rows tall — one usable interior row — so without this the parallel lanes would
 * collapse onto the centre row. Hosting N edges needs N port rows with a one-row
 * gap (2N−1 interior rows) plus two border rows, i.e. a box height of 2N+1.
 *
 * Left-to-right only: in top-to-bottom flow parallel edges spread across the box
 * width, which the label already provides.
 */
final readonly class LeftToRightParallelPortReserver implements Processor
{
    public function process(LayoutGraph $graph): void
    {
        $groupSizes = [];
        foreach ($graph->edges() as $edge) {
            // Reversed (feedback) edges are drawn as a separate back lane by
            // FeedbackEdgeRouter, so they never count as parallel forward edges
            // even when reversal makes them share a pair with a real edge.
            if ($edge->reversed) {
                continue;
            }
            $key = $edge->sourceId()."\0".$edge->targetId();
            $groupSizes[$key] = ($groupSizes[$key] ?? 0) + 1;
        }

        $maxParallel = [];
        foreach ($graph->edges() as $edge) {
            if ($edge->reversed) {
                continue;
            }
            $count = $groupSizes[$edge->sourceId()."\0".$edge->targetId()];
            if ($count < 2) {
                continue;
            }
            foreach ([$edge->sourceId(), $edge->targetId()] as $nodeId) {
                /** @infection-ignore-all the ?? base is only used on first sight of a node, where count (>= 2 here) always wins the max, so shifting the base by one is not observable */
                $maxParallel[$nodeId] = max($maxParallel[$nodeId] ?? 0, $count);
            }
        }

        foreach ($maxParallel as $nodeId => $count) {
            $graph->getLayoutNode($nodeId)->minBoxHeight = 2 * $count + 1;
        }
    }
}
