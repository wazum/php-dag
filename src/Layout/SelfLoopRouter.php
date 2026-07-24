<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Render\Waypoint;

/**
 * Routes self-loops (an edge from a node back to itself) as a compact side loop
 * — the degenerate case of a feedback edge, drawn the way Graphviz/dagre draw
 * one: it leaves the bottom of the box, drops one row, runs out to a lane just
 * past the right edge, climbs to the box's vertical centre and re-enters the
 * east side with an arrowhead. The positioners reserve the lane's footprint so
 * neighbours never overlap it.
 */
final readonly class SelfLoopRouter implements Processor
{
    public function process(LayoutGraph $graph): void
    {
        foreach ($graph->selfLoops() as $loop) {
            $node = $graph->getLayoutNode($loop->edge->sourceId);
            $width = $node->boxWidth();
            $height = $node->boxHeight();
            $laneColumn = $node->column + $width + 1;
            $centerRow = $node->row + intdiv($height, 2);

            $loop->sourcePort = EdgePort::South;
            $loop->targetPort = EdgePort::East;
            $loop->waypoints = [
                new Waypoint($node->row + $height, $node->column + intdiv($width, 2)),
                new Waypoint($node->row + $height, $laneColumn),
                new Waypoint($centerRow, $laneColumn),
                new Waypoint($centerRow, $node->column + $width),
            ];
        }
    }
}
