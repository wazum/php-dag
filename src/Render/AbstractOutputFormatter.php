<?php

declare(strict_types=1);

namespace PhpDag\Render;

/**
 * Shared skeleton for text formatters: walking the canvas row by row, skipping
 * blank rows at the top, dropping them at the bottom, and keeping interior ones.
 * Subclasses only decide how a single row becomes a string. Exposing the rows as
 * a generator lets a caller stream a large drawing to a resource without ever
 * materialising the whole output in memory.
 */
abstract class AbstractOutputFormatter implements OutputFormatter
{
    public function format(Canvas $canvas): string
    {
        /** @infection-ignore-all rows() yields sequential auto-keys, so preserving vs discarding them produces the same array */
        return implode("\n", iterator_to_array($this->rows($canvas), false));
    }

    public function rows(Canvas $canvas): iterable
    {
        /** @infection-ignore-all Canvas height is never negative; 0 vs -1 check is equivalent */
        if (0 === $canvas->height()) {
            return;
        }

        // Read the dimensions once: width()/height() each scan every cell, so
        // re-evaluating them in the loop condition would make this quadratic.
        $height = $canvas->height();
        $width = $canvas->width();
        // Content may sit left of / above the origin (edge labels on the outer
        // side of a lane), so start at the lowest written index; the drawing
        // shifts into view instead of clipping.
        $firstRow = $canvas->firstRow();
        $firstColumn = $canvas->firstColumn();

        // Blank rows are held back rather than emitted: a run of them is flushed
        // only when a later non-blank row proves they were interior, so trailing
        // blank rows are dropped and leading ones never start the count.
        /** @infection-ignore-all reset to 0 at the first content row before any blank is counted (leading blanks add 0 while $seenContent is false), so the initial value is never observed */
        $pendingBlankRows = 0;
        $seenContent = false;
        /** @infection-ignore-all over/under-running the row range only adds all-space rows that the blank-row handling above discards */
        for ($row = $firstRow; $row < $height; ++$row) {
            $line = $this->renderRow($canvas, $row, $firstColumn, $width);
            if ('' === $line) {
                $pendingBlankRows += $seenContent ? 1 : 0;

                continue;
            }

            for ($blank = 0; $blank < $pendingBlankRows; ++$blank) {
                yield '';
            }
            $pendingBlankRows = 0;
            $seenContent = true;

            yield $line;
        }
    }

    abstract protected function renderRow(Canvas $canvas, int $row, int $firstColumn, int $width): string;
}
