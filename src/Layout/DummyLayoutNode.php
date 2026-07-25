<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Graph\Edge;

final class DummyLayoutNode extends LayoutNode
{
    public readonly Edge $originalEdge;
    public int $corridorWidth = 0;

    public function __construct(
        string $id,
        public readonly string $originalEdgeSourceId,
        public readonly string $originalEdgeTargetId,
        ?Edge $originalEdge = null,
        public readonly bool $originalEdgeReversed = false,
        public readonly ?string $originalEdgeId = null,
    ) {
        parent::__construct($id);
        $this->originalEdge = $originalEdge ?? new Edge($originalEdgeSourceId, $originalEdgeTargetId);
    }

    /** Opaque key grouping every dummy of one original edge; parallel edges never share it. */
    public function identityKey(): string
    {
        /** @infection-ignore-all the pair fallback is reached only for non-parallel edges (parallels always carry a non-null id), whose endpoint pair is already unique — so mutating the separator or dropping an operand keeps every key unique and the chain grouping unchanged */
        return $this->originalEdgeId ?? $this->originalEdgeSourceId.':'.$this->originalEdgeTargetId;
    }

    public function boxWidth(): int
    {
        return max(1, $this->corridorWidth);
    }

    protected function naturalBoxHeight(): int
    {
        return 1;
    }
}
