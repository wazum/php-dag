<?php

declare(strict_types=1);

namespace PhpDag\Render;

use LogicException;
use PhpDag\Layout\EdgePort;
use PhpDag\Layout\FlowDirection;
use PhpDag\Layout\LayoutEdge;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Layout\RealLayoutNode;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\EdgeStrokeStyle;

final readonly class EdgeRenderer implements ElementRenderer
{
    private const Z_INDEX = 5;
    private const CONNECTION_Z_INDEX = 11;

    public function __construct(
        private FlowDirection $flowDirection = FlowDirection::TopToBottom,
        private bool $unicodeGlyphs = true,
    ) {
    }

    public function render(Canvas $canvas, LayoutGraph $graph): void
    {
        // Self-loops are kept out of the layout adjacency, so render them after
        // the regular edges with a disjoint id range. The id offsets are only
        // passthrough-tracking keys; their exact value never reaches the glyphs.
        /** @infection-ignore-all edge-id offsets are bookkeeping; they do not affect rendered output */
        $this->renderEdges($canvas, $graph, $graph->edges(), 0);
        $this->renderEdges($canvas, $graph, $graph->selfLoops(), count($graph->edges()));
    }

    /**
     * @param list<LayoutEdge> $layoutEdges
     */
    private function renderEdges(Canvas $canvas, LayoutGraph $graph, array $layoutEdges, int $idOffset): void
    {
        /** @var list<EdgeRoute> $routes */
        $routes = [];
        foreach ($layoutEdges as $index => $layoutEdge) {
            if ([] === $layoutEdge->waypoints) {
                continue;
            }

            $color = $layoutEdge->edge->color;

            /** @infection-ignore-all edgeId is used for passthrough tracking; its exact value doesn't affect glyph output */
            $routes[] = new EdgeRoute(
                waypoints: $layoutEdge->waypoints,
                edgeId: $idOffset + $index + 1,
                strokeStyle: $layoutEdge->edge->edgeStrokeStyle,
                targetArrow: false,
                color: $color,
            );
        }
        $this->renderRoutes($canvas, $routes);

        foreach ($layoutEdges as $index => $layoutEdge) {
            if ([] === $layoutEdge->waypoints) {
                continue;
            }

            $color = $layoutEdge->edge->color;
            /** @infection-ignore-all edgeId is used for passthrough tracking; its exact value doesn't affect glyph output */
            $edgeId = $idOffset + $index + 1;
            $strokeStyle = $layoutEdge->edge->edgeStrokeStyle;

            $sourceNode = $graph->getLayoutNode($layoutEdge->visualSourceId());
            if ($sourceNode instanceof RealLayoutNode) {
                $this->renderNodeConnection(
                    $canvas,
                    $sourceNode,
                    $layoutEdge->waypoints[0],
                    $edgeId,
                    $layoutEdge->sourcePort ?? $this->defaultSourcePort(),
                    $strokeStyle,
                    $color,
                );
            }

            $targetNode = $graph->getLayoutNode($layoutEdge->visualTargetId());
            if ($targetNode instanceof RealLayoutNode) {
                $this->renderNodeConnection(
                    $canvas,
                    $targetNode,
                    end($layoutEdge->waypoints),
                    $edgeId,
                    $layoutEdge->targetPort ?? $this->defaultTargetPort(),
                    $strokeStyle,
                    $color,
                );
            }
        }

        foreach ($layoutEdges as $layoutEdge) {
            $waypoints = $layoutEdge->waypoints;
            if (count($waypoints) < 2) {
                continue;
            }

            $strokeStyle = $layoutEdge->edge->edgeStrokeStyle;
            $color = $layoutEdge->edge->color;
            if (!$layoutEdge->reversed || null !== $layoutEdge->targetPort) {
                $lastIndex = count($waypoints) - 1;
                $this->drawArrow($canvas, $waypoints[$lastIndex - 1], $waypoints[$lastIndex], $strokeStyle, $color);

                continue;
            }

            $this->drawArrow($canvas, $waypoints[1], $waypoints[0], $strokeStyle, $color);
        }
    }

    /**
     * Draws route bodies while coalescing the uncoloured collinear intervals
     * shared by ordinary edges. A wide fan can contain hundreds of routes over
     * the same trunk; painting each interval cell for every edge makes rendering
     * quadratic in the fan width even though the final canvas contains only one
     * line. Direction bits and stroke priority are associative, so uncoloured
     * intervals of the same stroke can be unioned without changing the result.
     * Coloured routes retain their original per-edge order because equal-priority
     * colour selection deliberately uses the first coloured edge at a crossing.
     *
     * @param list<EdgeRoute> $routes
     */
    private function renderRoutes(Canvas $canvas, array $routes): void
    {
        /** @var array<string, array<int, list<array{int, int}>>> $horizontalIntervals */
        $horizontalIntervals = [];
        /** @var array<string, array<int, list<array{int, int}>>> $verticalIntervals */
        $verticalIntervals = [];
        /** @var array<string, EdgeStrokeStyle> $strokeStyles */
        $strokeStyles = [];

        foreach ($routes as $route) {
            if (null !== $route->color) {
                $this->renderRoute($canvas, $route);

                continue;
            }

            $strokeKey = $route->strokeStyle->name;
            $strokeStyles[$strokeKey] = $route->strokeStyle;
            $waypoints = $route->waypoints;
            $lastIndex = count($waypoints) - 1;

            for ($index = 0; $index < $lastIndex; ++$index) {
                $from = $waypoints[$index];
                $to = $waypoints[$index + 1];
                $direction = $this->inferDirection($from, $to);
                $opposite = $this->oppositeDirection($direction);

                $canvas->markEdgePassthrough($from->row, $from->column, $direction, $route->edgeId, $route->strokeStyle, self::Z_INDEX);
                $canvas->markEdgePassthrough($to->row, $to->column, $opposite, $route->edgeId, $route->strokeStyle, self::Z_INDEX);

                if ($from->column === $to->column) {
                    $start = min($from->row, $to->row) + 1;
                    $end = max($from->row, $to->row) - 1;
                    if ($start <= $end) {
                        $verticalIntervals[$strokeKey][$from->column][] = [$start, $end];
                    }

                    continue;
                }

                $start = min($from->column, $to->column) + 1;
                $end = max($from->column, $to->column) - 1;
                if ($start <= $end) {
                    $horizontalIntervals[$strokeKey][$from->row][] = [$start, $end];
                }
            }
        }

        foreach ($horizontalIntervals as $strokeKey => $rows) {
            $strokeStyle = $strokeStyles[$strokeKey];
            foreach ($rows as $row => $intervals) {
                foreach ($this->mergeIntervals($intervals) as [$start, $end]) {
                    $canvas->horizontalLine($row, $start, $end, 0, $strokeStyle, self::Z_INDEX);
                }
            }
        }

        foreach ($verticalIntervals as $strokeKey => $columns) {
            $strokeStyle = $strokeStyles[$strokeKey];
            foreach ($columns as $column => $intervals) {
                foreach ($this->mergeIntervals($intervals) as [$start, $end]) {
                    $canvas->verticalLine($column, $start, $end, 0, $strokeStyle, self::Z_INDEX);
                }
            }
        }
    }

    /**
     * @param list<array{int, int}> $intervals
     *
     * @return list<array{int, int}>
     */
    private function mergeIntervals(array $intervals): array
    {
        usort($intervals, static fn (array $left, array $right): int => $left[0] <=> $right[0] ?: $left[1] <=> $right[1]);

        /** @var list<array{int, int}> $merged */
        $merged = [];
        if ([] === $intervals) {
            return $merged;
        }

        [$currentStart, $currentEnd] = $intervals[0];
        for ($index = 1, $count = count($intervals); $index < $count; ++$index) {
            [$start, $end] = $intervals[$index];
            if ($start <= $currentEnd + 1) {
                $currentEnd = max($currentEnd, $end);

                continue;
            }

            $merged[] = [$currentStart, $currentEnd];
            $currentStart = $start;
            $currentEnd = $end;
        }
        $merged[] = [$currentStart, $currentEnd];

        return $merged;
    }

    public function renderRoute(Canvas $canvas, EdgeRoute $route): void
    {
        $waypoints = $route->waypoints;
        $lastIndex = count($waypoints) - 1;
        $color = $route->color;

        for ($waypointOffset = 0; $waypointOffset < $lastIndex; ++$waypointOffset) {
            $this->drawSegment($canvas, $waypoints[$waypointOffset], $waypoints[$waypointOffset + 1], $route->edgeId, $route->strokeStyle, $color);
        }

        /** @psalm-suppress InvalidArrayOffset,MixedArgument EdgeRoute guarantees at least 2 waypoints */
        if ($route->targetArrow) {
            $this->drawArrow($canvas, $waypoints[$lastIndex - 1], $waypoints[$lastIndex], $route->strokeStyle, $color);
        }

        /** @psalm-suppress InvalidArrayOffset,MixedArgument EdgeRoute guarantees at least 2 waypoints */
        if ($route->sourceArrow) {
            $this->drawArrow($canvas, $waypoints[1], $waypoints[0], $route->strokeStyle, $color);
        }
    }

    private function renderNodeConnection(Canvas $canvas, RealLayoutNode $node, Waypoint $waypoint, int $edgeId, EdgePort $port, EdgeStrokeStyle $strokeStyle, ?AnsiColor $color): void
    {
        match ($port) {
            EdgePort::North => $this->renderVerticalConnection(
                canvas: $canvas,
                borderRow: $node->row,
                column: $waypoint->column,
                waypoint: $waypoint,
                borderMask: Direction::LEFT | Direction::RIGHT | Direction::UP,
                waypointDirection: Direction::DOWN,
                edgeId: $edgeId,
                strokeStyle: $strokeStyle,
                color: $color,
            ),
            EdgePort::South => $this->renderVerticalConnection(
                canvas: $canvas,
                borderRow: $node->row + $node->boxHeight() - 1,
                column: $waypoint->column,
                waypoint: $waypoint,
                borderMask: Direction::LEFT | Direction::RIGHT | Direction::DOWN,
                waypointDirection: Direction::UP,
                edgeId: $edgeId,
                strokeStyle: $strokeStyle,
                color: $color,
            ),
            EdgePort::East => $this->renderHorizontalConnection(
                canvas: $canvas,
                row: $waypoint->row,
                borderColumn: $node->column + $node->boxWidth() - 1,
                waypoint: $waypoint,
                borderMask: Direction::UP | Direction::DOWN | Direction::RIGHT,
                waypointDirection: Direction::LEFT,
                edgeId: $edgeId,
                strokeStyle: $strokeStyle,
                color: $color,
            ),
            EdgePort::West => $this->renderHorizontalConnection(
                canvas: $canvas,
                row: $waypoint->row,
                borderColumn: $node->column,
                waypoint: $waypoint,
                borderMask: Direction::UP | Direction::DOWN | Direction::LEFT,
                waypointDirection: Direction::RIGHT,
                edgeId: $edgeId,
                strokeStyle: $strokeStyle,
                color: $color,
            ),
        };
    }

    private function renderVerticalConnection(Canvas $canvas, int $borderRow, int $column, Waypoint $waypoint, int $borderMask, int $waypointDirection, int $edgeId, EdgeStrokeStyle $strokeStyle, ?AnsiColor $color): void
    {
        $glyph = $strokeStyle->glyphs($this->unicodeGlyphs)->junctionFor($borderMask);
        $canvas->putCharacter($borderRow, $column, $glyph, self::connectionZIndex($strokeStyle), $color);

        $gapStart = min($borderRow, $waypoint->row) + 1;
        $gapEnd = max($borderRow, $waypoint->row) - 1;
        if ($gapStart <= $gapEnd) {
            $canvas->verticalLine($column, $gapStart, $gapEnd, $edgeId, $strokeStyle, self::Z_INDEX, $color);
        }

        $canvas->markEdgePassthrough($waypoint->row, $waypoint->column, $waypointDirection, $edgeId, $strokeStyle, self::Z_INDEX, $color);
    }

    private function renderHorizontalConnection(Canvas $canvas, int $row, int $borderColumn, Waypoint $waypoint, int $borderMask, int $waypointDirection, int $edgeId, EdgeStrokeStyle $strokeStyle, ?AnsiColor $color): void
    {
        $glyph = $strokeStyle->glyphs($this->unicodeGlyphs)->junctionFor($borderMask);
        $canvas->putCharacter($row, $borderColumn, $glyph, self::connectionZIndex($strokeStyle), $color);

        $gapStart = min($borderColumn, $waypoint->column) + 1;
        $gapEnd = max($borderColumn, $waypoint->column) - 1;
        if ($gapStart <= $gapEnd) {
            $canvas->horizontalLine($row, $gapStart, $gapEnd, $edgeId, $strokeStyle, self::Z_INDEX, $color);
        }

        $canvas->markEdgePassthrough($waypoint->row, $waypoint->column, $waypointDirection, $edgeId, $strokeStyle, self::Z_INDEX, $color);
    }

    private static function connectionZIndex(EdgeStrokeStyle $strokeStyle): int
    {
        return self::CONNECTION_Z_INDEX + $strokeStyle->priority();
    }

    private function defaultSourcePort(): EdgePort
    {
        return match ($this->flowDirection) {
            FlowDirection::TopToBottom => EdgePort::South,
            FlowDirection::LeftToRight => EdgePort::East,
        };
    }

    private function defaultTargetPort(): EdgePort
    {
        return match ($this->flowDirection) {
            FlowDirection::TopToBottom => EdgePort::North,
            FlowDirection::LeftToRight => EdgePort::West,
        };
    }

    private function drawSegment(Canvas $canvas, Waypoint $from, Waypoint $to, int $edgeId, EdgeStrokeStyle $strokeStyle, ?AnsiColor $color): void
    {
        $direction = $this->inferDirection($from, $to);
        $opposite = $this->oppositeDirection($direction);

        $canvas->markEdgePassthrough($from->row, $from->column, $direction, $edgeId, $strokeStyle, self::Z_INDEX, $color);
        $canvas->markEdgePassthrough($to->row, $to->column, $opposite, $edgeId, $strokeStyle, self::Z_INDEX, $color);

        if ($from->column === $to->column) {
            $startRow = min($from->row, $to->row) + 1;
            $endRow = max($from->row, $to->row) - 1;
            if ($startRow <= $endRow) {
                $canvas->verticalLine($from->column, $startRow, $endRow, $edgeId, $strokeStyle, self::Z_INDEX, $color);
            }
        } else {
            $startColumn = min($from->column, $to->column) + 1;
            $endColumn = max($from->column, $to->column) - 1;
            if ($startColumn <= $endColumn) {
                $canvas->horizontalLine($from->row, $startColumn, $endColumn, $edgeId, $strokeStyle, self::Z_INDEX, $color);
            }
        }
    }

    private function drawArrow(Canvas $canvas, Waypoint $from, Waypoint $to, EdgeStrokeStyle $strokeStyle, ?AnsiColor $color): void
    {
        $direction = $this->inferDirection($from, $to);
        $this->drawArrowForDirection($canvas, $to, $direction, $strokeStyle, $color);
    }

    private function drawArrowForDirection(Canvas $canvas, Waypoint $to, int $direction, EdgeStrokeStyle $strokeStyle, ?AnsiColor $color): void
    {
        $canvas->putCharacter($to->row, $to->column, $strokeStyle->glyphs($this->unicodeGlyphs)->arrowFor($direction), self::Z_INDEX, $color);
    }

    private function inferDirection(Waypoint $from, Waypoint $to): int
    {
        if ($to->row > $from->row) {
            return Direction::DOWN;
        }
        if ($to->row < $from->row) {
            return Direction::UP;
        }
        /** @infection-ignore-all Equal columns are handled by the row checks above; this branch only runs when columns differ */
        if ($to->column > $from->column) {
            return Direction::RIGHT;
        }

        return Direction::LEFT;
    }

    /** @infection-ignore-all Defensive default arm; all four Direction constants are covered */
    private function oppositeDirection(int $direction): int
    {
        return match ($direction) {
            Direction::UP => Direction::DOWN,
            Direction::DOWN => Direction::UP,
            Direction::LEFT => Direction::RIGHT,
            Direction::RIGHT => Direction::LEFT,
            default => throw new LogicException(sprintf('Unexpected direction: %d', $direction)),
        };
    }
}
