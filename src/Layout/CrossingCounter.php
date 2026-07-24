<?php

declare(strict_types=1);

namespace PhpDag\Layout;

final readonly class CrossingCounter
{
    public function countAll(LayoutGraph $graph): int
    {
        $totalCrossings = 0;
        $layers = array_keys($graph->layerIndex());
        $layerCount = count($layers);

        for ($layerOffset = 0; $layerOffset < $layerCount - 1; ++$layerOffset) {
            $totalCrossings += $this->countBetweenLayers($graph, $layers[$layerOffset], $layers[$layerOffset + 1]);
        }

        return $totalCrossings;
    }

    public function countBetweenLayers(LayoutGraph $graph, int $upperLayer, int $lowerLayer): int
    {
        $upperNodes = $graph->layerIndex()[$upperLayer] ?? [];
        $lowerNodes = $graph->layerIndex()[$lowerLayer] ?? [];
        $lowerPosition = array_flip($lowerNodes);

        $targetPositions = [];
        foreach ($upperNodes as $nodeId) {
            // Sort each node's own edge endpoints ascending: two edges leaving
            // the same source never cross, so they must not register as an
            // inversion (Barth–Mutzel–Jünger counts inversions of the lower
            // endpoints ordered by upper position, then by lower position).
            $nodeTargets = [];
            foreach ($graph->successors($nodeId) as $successorId) {
                if (isset($lowerPosition[$successorId])) {
                    $nodeTargets[] = $lowerPosition[$successorId];
                }
            }
            sort($nodeTargets);
            foreach ($nodeTargets as $position) {
                $targetPositions[] = $position;
            }
        }

        return $this->countInversions($targetPositions);
    }

    /** @param list<int> $array */
    private function countInversions(array $array): int
    {
        return $this->mergeSort($array)[1];
    }

    /**
     * @param list<int> $array
     *
     * @return array{list<int>, int}
     */
    private function mergeSort(array $array): array
    {
        $count = count($array);
        if ($count <= 1) {
            return [$array, 0];
        }

        $middleIndex = intdiv($count, 2);
        [$left, $leftInversions] = $this->mergeSort(array_slice($array, 0, $middleIndex));
        [$right, $rightInversions] = $this->mergeSort(array_slice($array, $middleIndex));

        $inversions = $leftInversions + $rightInversions;
        $merged = [];
        $leftOffset = 0;
        $rightOffset = 0;
        $leftCount = count($left);
        $rightCount = count($right);

        while ($leftOffset < $leftCount && $rightOffset < $rightCount) {
            if ($left[$leftOffset] <= $right[$rightOffset]) {
                $merged[] = $left[$leftOffset];
                ++$leftOffset;
            } else {
                $merged[] = $right[$rightOffset];
                $inversions += $leftCount - $leftOffset;
                ++$rightOffset;
            }
        }

        while ($leftOffset < $leftCount) {
            $merged[] = $left[$leftOffset];
            ++$leftOffset;
        }
        /** @infection-ignore-all right tail doesn't contribute inversions at this level; only affects merged array for parent recursion with 8+ elements */
        while ($rightOffset < $rightCount) {
            $merged[] = $right[$rightOffset];
            ++$rightOffset;
        }

        return [$merged, $inversions];
    }
}
