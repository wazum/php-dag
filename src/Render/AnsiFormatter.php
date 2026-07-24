<?php

declare(strict_types=1);

namespace PhpDag\Render;

use PhpDag\Style\AnsiColor;

final class AnsiFormatter extends AbstractOutputFormatter
{
    protected function renderRow(Canvas $canvas, int $row, int $firstColumn, int $width): string
    {
        /** @var array<int, array{string, ?AnsiColor}> $cells */
        $cells = [];
        /** @infection-ignore-all over-running the column range appends a trailing space cell that the trailing-space trim below pops; the start column is pinned by the golden snapshots */
        for ($column = $firstColumn; $column < $width; ++$column) {
            $cell = $canvas->cellAt($row, $column);
            $cells[] = [$cell?->resolvedCharacter() ?? ' ', $cell?->resolvedColor()];
        }

        while ([] !== $cells && ' ' === end($cells)[0]) {
            array_pop($cells);
        }

        $line = '';
        $currentColor = null;
        foreach ($cells as [$character, $cellColor]) {
            if ($cellColor !== $currentColor) {
                if (null !== $currentColor) {
                    $line .= AnsiColor::resetCode();
                }
                if (null !== $cellColor) {
                    $line .= $cellColor->escapeCode();
                }
                $currentColor = $cellColor;
            }
            $line .= $character;
        }

        if (null !== $currentColor) {
            $line .= AnsiColor::resetCode();
        }

        return $line;
    }
}
