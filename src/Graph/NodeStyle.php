<?php

declare(strict_types=1);

namespace PhpDag\Graph;

use PhpDag\Style\BorderStyle;

final readonly class NodeStyle
{
    public function __construct(
        public BorderStyle $borderStyle = BorderStyle::Rounded,
        public ?Badge $badge = null,
        public ContentAlignment $titleAlignment = ContentAlignment::Center,
        public ContentAlignment $bodyAlignment = ContentAlignment::Left,
        public bool $titleBodySeparator = false,
    ) {
    }
}
