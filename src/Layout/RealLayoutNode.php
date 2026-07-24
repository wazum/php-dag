<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Graph\Node;

final class RealLayoutNode extends LayoutNode
{
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
        return $this->measuredWidth;
    }

    protected function naturalBoxHeight(): int
    {
        return $this->measuredHeight;
    }
}
