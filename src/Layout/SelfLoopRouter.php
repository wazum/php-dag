<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Render\Waypoint;

/**
 * Routes self-loops (an edge from a node back to itself) as a compact side loop
 * — the degenerate case of a feedback edge, drawn the way Graphviz/dagre draw
 * one: it leaves the bottom of the box, drops one row, runs out to a lane just
 * past the right edge, climbs to the box's vertical centre and re-enters the
 * east side with an arrowhead. A feedback edge already owns the east side in
 * top-to-bottom flow and the south side in left-to-right flow, so on such a
 * node the loop moves to the west or the north side instead. The positioners
 * reserve the lane's footprint so neighbours never overlap it.
 */
final readonly class SelfLoopRouter implements Processor
{
    public function __construct(
        private FlowDirection $flowDirection = FlowDirection::TopToBottom,
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        foreach ($graph->selfLoops() as $loop) {
            $node = $graph->getLayoutNode($loop->edge->sourceId);
            $width = $node->boxWidth();
            $height = $node->boxHeight();
            $centerRow = $node->row + intdiv($height, 2);
            $mirrored = $graph->hasFeedbackEdge($node->id);
            $onWestSide = $mirrored && FlowDirection::TopToBottom === $this->flowDirection;
            $onNorthSide = $mirrored && FlowDirection::LeftToRight === $this->flowDirection;
            $loopRow = $onNorthSide ? $node->row - 1 : $node->row + $height;
            $laneColumn = $onWestSide ? $node->column - 2 : $node->column + $width + 1;

            $loop->sourcePort = $onNorthSide ? EdgePort::North : EdgePort::South;
            $loop->targetPort = $onWestSide ? EdgePort::West : EdgePort::East;
            $loop->waypoints = [
                new Waypoint($loopRow, $node->column + intdiv($width, 2)),
                new Waypoint($loopRow, $laneColumn),
                new Waypoint($centerRow, $laneColumn),
                new Waypoint($centerRow, $onWestSide ? $node->column - 1 : $node->column + $width),
            ];
        }
    }
}
