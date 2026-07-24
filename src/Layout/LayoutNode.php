<?php

declare(strict_types=1);

namespace PhpDag\Layout;

abstract class LayoutNode
{
    public int $layer = -1;
    public int $row = 0;
    public int $column = 0;

    /** Floor applied to {@see boxHeight()} so a box can be grown to host edge ports (left-to-right parallel edges). */
    public ?int $minBoxHeight = null;

    protected function __construct(
        public readonly string $id,
    ) {
    }

    abstract public function boxWidth(): int;

    public function boxHeight(): int
    {
        /** @infection-ignore-all the ?? fallback is only reached when minBoxHeight is null, and the natural height (>= 1 for every node) always wins the max then, so the fallback value is never observable */
        return max($this->naturalBoxHeight(), $this->minBoxHeight ?? 0);
    }

    abstract protected function naturalBoxHeight(): int;
}
