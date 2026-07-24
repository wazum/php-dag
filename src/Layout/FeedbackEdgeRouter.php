<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Render\Waypoint;

final readonly class FeedbackEdgeRouter implements Processor
{
    public function __construct(
        private FlowDirection $flowDirection = FlowDirection::TopToBottom,
    ) {
    }

    public function process(LayoutGraph $graph): void
    {
        $laneIndex = 0;

        foreach ($graph->edges() as $edge) {
            if (!$edge->reversed) {
                continue;
            }

            match ($this->flowDirection) {
                FlowDirection::TopToBottom => $this->routeTopToBottom($graph, $edge, $laneIndex),
                FlowDirection::LeftToRight => $this->routeLeftToRight($graph, $edge, $laneIndex),
            };

            ++$laneIndex;
        }
    }

    private function routeTopToBottom(LayoutGraph $graph, LayoutEdge $edge, int $laneIndex): void
    {
        $sourceNode = $graph->getLayoutNode($edge->edge->sourceId);
        $targetNode = $graph->getLayoutNode($edge->edge->targetId);
        $laneColumn = $this->maxRightColumn($graph) + 3 + $laneIndex * 2;

        $edge->sourcePort = EdgePort::East;
        $edge->targetPort = EdgePort::East;
        $edge->waypoints = $this->deduplicateWaypoints([
            new Waypoint(
                $sourceNode->row + intdiv($sourceNode->boxHeight(), 2),
                $sourceNode->column + $sourceNode->boxWidth(),
            ),
            new Waypoint(
                $sourceNode->row + intdiv($sourceNode->boxHeight(), 2),
                $laneColumn,
            ),
            new Waypoint(
                $targetNode->row + intdiv($targetNode->boxHeight(), 2),
                $laneColumn,
            ),
            new Waypoint(
                $targetNode->row + intdiv($targetNode->boxHeight(), 2),
                $targetNode->column + $targetNode->boxWidth(),
            ),
        ]);
    }

    private function routeLeftToRight(LayoutGraph $graph, LayoutEdge $edge, int $laneIndex): void
    {
        $sourceNode = $graph->getLayoutNode($edge->edge->sourceId);
        $targetNode = $graph->getLayoutNode($edge->edge->targetId);
        $laneRow = $this->maxBottomRow($graph) + 2 + $laneIndex * 2;

        $edge->sourcePort = EdgePort::South;
        $edge->targetPort = EdgePort::South;
        $edge->waypoints = $this->deduplicateWaypoints([
            new Waypoint(
                $sourceNode->row + $sourceNode->boxHeight(),
                $sourceNode->column + intdiv($sourceNode->boxWidth(), 2),
            ),
            new Waypoint(
                $laneRow,
                $sourceNode->column + intdiv($sourceNode->boxWidth(), 2),
            ),
            new Waypoint(
                $laneRow,
                $targetNode->column + intdiv($targetNode->boxWidth(), 2),
            ),
            new Waypoint(
                $targetNode->row + $targetNode->boxHeight(),
                $targetNode->column + intdiv($targetNode->boxWidth(), 2),
            ),
        ]);
    }

    private function maxRightColumn(LayoutGraph $graph): int
    {
        /** @infection-ignore-all init value is consumed by max(); every node right column is non-negative */
        $maxRightColumn = 0;

        foreach ($graph->nodeIds() as $nodeId) {
            $node = $graph->getLayoutNode($nodeId);
            $maxRightColumn = max($maxRightColumn, $node->column + $node->boxWidth() - 1);
        }

        return $maxRightColumn;
    }

    private function maxBottomRow(LayoutGraph $graph): int
    {
        /** @infection-ignore-all init value is consumed by max(); every node bottom row is non-negative */
        $maxBottomRow = 0;

        foreach ($graph->nodeIds() as $nodeId) {
            $node = $graph->getLayoutNode($nodeId);
            $maxBottomRow = max($maxBottomRow, $node->row + $node->boxHeight() - 1);
        }

        return $maxBottomRow;
    }

    /**
     * @param list<Waypoint> $waypoints
     *
     * @return list<Waypoint>
     */
    private function deduplicateWaypoints(array $waypoints): array
    {
        $deduplicated = [];

        foreach ($waypoints as $waypoint) {
            if ([] === $deduplicated || $waypoint != end($deduplicated)) {
                $deduplicated[] = $waypoint;
            }
        }

        return $deduplicated;
    }
}
