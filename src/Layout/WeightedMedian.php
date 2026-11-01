<?php

declare(strict_types=1);

namespace PhpDag\Layout;

final readonly class WeightedMedian
{
    /** @param non-empty-list<int> $positions */
    public function value(array $positions): float
    {
        sort($positions);
        $count = count($positions);
        $middle = intdiv($count, 2);

        if (1 === $count % 2) {
            return (float) $positions[$middle];
        }

        $leftGap = $positions[$middle - 1] - $positions[0];
        $rightGap = $positions[$count - 1] - $positions[$middle];

        if (0 === $leftGap && 0 === $rightGap) {
            return ($positions[$middle - 1] + $positions[$middle]) / 2;
        }

        return ($positions[$middle - 1] * $rightGap + $positions[$middle] * $leftGap) / ($leftGap + $rightGap);
    }
}
