<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Graph\Node;

final class RealLayoutNode extends LayoutNode
{
    /** Floor applied to {@see boxWidth()} so a box can be grown to host one port per edge (top-to-bottom parallel edges). */
    public int $minBoxWidth = 0;

    // Measured once: Node is immutable, and its Unicode width/height are read
    // repeatedly across positioning and rendering.
    private readonly int $measuredWidth;
    private readonly int $measuredHeight;

    public function __construct(
        string $id,
        public readonly Node $node,
    ) {
        parent::__construct($id);
        $this->measuredWidth = $node->boxWidth();
        $this->measuredHeight = $node->boxHeight();
    }

    public function boxWidth(): int
    {
        return max($this->measuredWidth, $this->minBoxWidth);
    }

    protected function naturalBoxHeight(): int
    {
        return $this->measuredHeight;
    }
}
