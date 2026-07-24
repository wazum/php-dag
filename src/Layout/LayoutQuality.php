<?php

declare(strict_types=1);

namespace PhpDag\Layout;

enum LayoutQuality
{
    /** @infection-ignore-all convergence cap; ±1 sweeps yield identical orderings (MedianOrdering documents the same) */
    private const FAST_SWEEPS = 4;
    /** @infection-ignore-all convergence cap; ±1 sweeps yield identical orderings */
    private const QUALITY_SWEEPS = 64;

    case Fast;
    case Standard;
    case Quality;

    /**
     * Network-simplex layering minimises total edge length for a more compact
     * drawing (the algorithm Graphviz dot uses). Standard and Quality use it;
     * Fast keeps the linear-time longest-path assignment for very large graphs
     * where the extra layout cost is not worth it.
     */
    public function layerAssignment(): LayerAssignment
    {
        return match ($this) {
            self::Fast => new LongestPathLayering(),
            self::Standard, self::Quality => new NetworkSimplexLayering(),
        };
    }

    public function crossingMinimization(): CrossingMinimization
    {
        return match ($this) {
            /** @infection-ignore-all transpose:false is the Fast/Standard distinction; its behavioural effect is pinned by MedianOrderingTest::disabledTransposeLeavesTheCrossingSweepsCannotFix. After FAST_SWEEPS median passes, the post-sweep transpose is a no-op for graphs small enough to render, so the toggle is not separately observable through a rendered snapshot */
            self::Fast => new MedianOrdering(maxSweeps: self::FAST_SWEEPS, transpose: false),
            self::Standard => new MedianOrdering(),
            self::Quality => new MedianOrdering(maxSweeps: self::QUALITY_SWEEPS),
        };
    }
}
