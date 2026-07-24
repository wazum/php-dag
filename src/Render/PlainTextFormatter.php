<?php

declare(strict_types=1);

namespace PhpDag\Render;

final class PlainTextFormatter extends AbstractOutputFormatter
{
    protected function renderRow(Canvas $canvas, int $row, int $firstColumn, int $width): string
    {
        $line = '';
        /** @infection-ignore-all over-running the column range appends a trailing space that rtrim() strips; the start column is pinned by the golden snapshots */
        for ($column = $firstColumn; $column < $width; ++$column) {
            $line .= $canvas->cellAt($row, $column)?->resolvedCharacter() ?? ' ';
        }

        return rtrim($line);
    }
}
