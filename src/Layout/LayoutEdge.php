<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Render\Waypoint;

final class LayoutEdge
{
    /** @var list<Waypoint> */
    public array $waypoints = [];
    public ?EdgePort $sourcePort = null;
    public ?EdgePort $targetPort = null;
    public ?int $labelLaneColumn = null;

    public function __construct(
        public readonly Edge $edge,
        public bool $reversed = false,
        public readonly ?string $originalEdgeId = null,
    ) {
    }

    /**
     * Opaque, unique-per-original-edge key. Parallel edges between the same pair
     * carry distinct ids assigned at layout construction; without one (edges
     * built ad hoc) it falls back to the endpoint pair.
     */
    public function identityKey(): string
    {
        return $this->originalEdgeId ?? $this->sourceId().'_'.$this->targetId();
    }

    public function sourceId(): string
    {
        return $this->reversed ? $this->edge->targetId : $this->edge->sourceId;
    }

    public function targetId(): string
    {
        return $this->reversed ? $this->edge->sourceId : $this->edge->targetId;
    }

    public function visualSourceId(): string
    {
        return null === $this->sourcePort ? $this->sourceId() : $this->edge->sourceId;
    }

    public function visualTargetId(): string
    {
        return null === $this->targetPort ? $this->targetId() : $this->edge->targetId;
    }

    public function minLength(): int
    {
        return $this->edge->minLength;
    }
}
