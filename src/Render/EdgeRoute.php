<?php

declare(strict_types=1);

namespace PhpDag\Render;

use InvalidArgumentException;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\EdgeStrokeStyle;

final readonly class EdgeRoute
{
    /** @param list<Waypoint> $waypoints */
    public function __construct(
        public array $waypoints,
        public int $edgeId,
        public EdgeStrokeStyle $strokeStyle = EdgeStrokeStyle::Solid,
        public bool $sourceArrow = false,
        public bool $targetArrow = true,
        public ?AnsiColor $color = null,
    ) {
        if (count($this->waypoints) < 2) {
            throw new InvalidArgumentException('EdgeRoute requires at least 2 waypoints');
        }

        for ($waypointOffset = 0, $segmentCount = count($this->waypoints) - 1; $waypointOffset < $segmentCount; ++$waypointOffset) {
            $current = $this->waypoints[$waypointOffset];
            $next = $this->waypoints[$waypointOffset + 1];

            if ($current->row !== $next->row && $current->column !== $next->column) {
                /** @infection-ignore-all Changing the index in the error message doesn't alter validation behavior */
                throw new InvalidArgumentException(sprintf('Consecutive waypoints must share row or column. Waypoint %d (%d,%d) and %d (%d,%d) are diagonal.', $waypointOffset, $current->row, $current->column, $waypointOffset + 1, $next->row, $next->column));
            }
        }
    }
}
