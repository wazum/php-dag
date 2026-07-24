<?php

declare(strict_types=1);

namespace PhpDag\Render;

interface OutputFormatter
{
    public function format(Canvas $canvas): string;

    /**
     * The formatted output one line at a time, so large drawings can be streamed
     * without building the whole string. Joined by "\n" this equals format().
     *
     * @return iterable<string>
     */
    public function rows(Canvas $canvas): iterable;
}
