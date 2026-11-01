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
        $laneIndexBySide = ['start' => 0, 'end' => 0];

        foreach ($graph->edges() as $edge) {
            if (!$edge->reversed) {
                continue;
            }

            $onStartSide = $graph->feedbackRunsOnStartSide($edge);
            /** @infection-ignore-all swapping the two side keys swaps two counters that both start at zero, so every side still numbers its lanes 0, 1, 2 */
            $laneIndex = $laneIndexBySide[$onStartSide ? 'start' : 'end']++;

            match ($this->flowDirection) {
                FlowDirection::TopToBottom => $this->routeTopToBottom($graph, $edge, $onStartSide, $laneIndex),
                FlowDirection::LeftToRight => $this->routeLeftToRight($graph, $edge, $onStartSide, $laneIndex),
            };
        }
    }

    private function routeTopToBottom(LayoutGraph $graph, LayoutEdge $edge, bool $onWestSide, int $laneIndex): void
    {
        $sourceNode = $graph->getLayoutNode($edge->edge->sourceId);
        $targetNode = $graph->getLayoutNode($edge->edge->targetId);
        $laneColumn = $onWestSide
            ? $this->minLeftColumn($graph) - 3 - $laneIndex * 2
            : $this->maxRightColumn($graph) + 3 + $laneIndex * 2;
        $sideColumn = static fn (LayoutNode $node): int => $onWestSide ? $node->column - 1 : $node->column + $node->boxWidth();
        $centerRow = static fn (LayoutNode $node): int => $node->row + intdiv($node->boxHeight(), 2);

        $edge->sourcePort = $onWestSide ? EdgePort::West : EdgePort::East;
        $edge->targetPort = $edge->sourcePort;
        $edge->waypoints = $this->deduplicateWaypoints([
            new Waypoint($centerRow($sourceNode), $sideColumn($sourceNode)),
            new Waypoint($centerRow($sourceNode), $laneColumn),
            new Waypoint($centerRow($targetNode), $laneColumn),
            new Waypoint($centerRow($targetNode), $sideColumn($targetNode)),
        ]);
    }

    private function routeLeftToRight(LayoutGraph $graph, LayoutEdge $edge, bool $onNorthSide, int $laneIndex): void
    {
        $sourceNode = $graph->getLayoutNode($edge->edge->sourceId);
        $targetNode = $graph->getLayoutNode($edge->edge->targetId);
        $laneRow = $onNorthSide
            ? $this->minTopRow($graph) - 2 - $laneIndex * 2
            : $this->maxBottomRow($graph) + 2 + $laneIndex * 2;
        $sideRow = static fn (LayoutNode $node): int => $onNorthSide ? $node->row - 1 : $node->row + $node->boxHeight();
        $centerColumn = static fn (LayoutNode $node): int => $node->column + intdiv($node->boxWidth(), 2);

        $edge->sourcePort = $onNorthSide ? EdgePort::North : EdgePort::South;
        $edge->targetPort = $edge->sourcePort;
        $edge->waypoints = $this->deduplicateWaypoints([
            new Waypoint($sideRow($sourceNode), $centerColumn($sourceNode)),
            new Waypoint($laneRow, $centerColumn($sourceNode)),
            new Waypoint($laneRow, $centerColumn($targetNode)),
            new Waypoint($sideRow($targetNode), $centerColumn($targetNode)),
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

    private function minLeftColumn(LayoutGraph $graph): int
    {
        return min(PHP_INT_MAX, ...array_map(static fn (string $nodeId): int => $graph->getLayoutNode($nodeId)->column, $graph->nodeIds()));
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

    private function minTopRow(LayoutGraph $graph): int
    {
        return min(PHP_INT_MAX, ...array_map(static fn (string $nodeId): int => $graph->getLayoutNode($nodeId)->row, $graph->nodeIds()));
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
