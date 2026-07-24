<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Layout\WeightedMedian;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WeightedMedianTest extends TestCase
{
    #[Test]
    public function returnsTheSinglePositionItself(): void
    {
        self::assertSame(5.0, (new WeightedMedian())->value([5]));
    }

    #[Test]
    public function returnsTheMiddleOfAnOddCountSortingFirst(): void
    {
        self::assertSame(1.0, (new WeightedMedian())->value([7, 0, 1]));
    }

    #[Test]
    public function averagesTheTwoPositionsWhenExactlyTwo(): void
    {
        self::assertSame(5.0, (new WeightedMedian())->value([8, 2]));
    }

    #[Test]
    public function weightsTowardTheTighterSideForFourOrMore(): void
    {
        // Sorted [0,2,4,10]: left gap 2, right gap 6 -> result pulled toward the
        // tight left pair, 2.5, not the plain mid-average 3.0 nor median 4.
        self::assertSame(2.5, (new WeightedMedian())->value([10, 2, 0, 4]));
    }

    #[Test]
    public function weightsTowardTheTighterRightSide(): void
    {
        // Sorted [0,6,8,10]: left gap 6, right gap 2 -> pulled toward the tight
        // right pair, 7.5, the mirror of the left-weighted case.
        self::assertSame(7.5, (new WeightedMedian())->value([0, 6, 8, 10]));
    }

    #[Test]
    public function measuresGapsRelativeToTheActualExtremesNotZero(): void
    {
        // Sorted [2,4,8,14] (no zero anchor): left gap 4-2=2, right gap 14-8=6
        // -> (4*6 + 8*2)/(2+6) = 5.0. Anchoring either gap at 0 would shift it.
        self::assertSame(5.0, (new WeightedMedian())->value([2, 4, 8, 14]));
    }
}
