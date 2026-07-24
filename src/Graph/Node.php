<?php

declare(strict_types=1);

namespace PhpDag\Graph;

use InvalidArgumentException;
use PhpDag\Style\AnsiColor;

final readonly class Node
{
    public const HORIZONTAL_PADDING = 1;

    /** @param list<string> $body */
    public function __construct(
        public string $id,
        public string $title,
        public array $body = [],
        public NodeStyle $style = new NodeStyle(),
        public ?AnsiColor $color = null,
    ) {
        if ('' === $this->title) {
            throw new InvalidArgumentException('Node title must not be empty');
        }

        if (ControlCharacters::present($this->id)) {
            throw new InvalidArgumentException('Node id must be valid UTF-8 without control characters');
        }

        if (ControlCharacters::present($this->title)) {
            throw new InvalidArgumentException('Node title must be valid UTF-8 without control characters');
        }

        foreach ($this->body as $line) {
            if (ControlCharacters::present($line)) {
                throw new InvalidArgumentException('Node body must be valid UTF-8 without control characters');
            }
        }
    }

    public function withColor(AnsiColor $color): self
    {
        return new self(
            $this->id,
            $this->title,
            $this->body,
            $this->style,
            $color,
        );
    }

    public function contentWidth(): int
    {
        $titleWidth = mb_strwidth($this->title);

        if ([] === $this->body) {
            return $titleWidth;
        }

        return max($titleWidth, ...array_map(mb_strwidth(...), $this->body));
    }

    public function contentHeight(): int
    {
        $separatorHeight = ($this->style->titleBodySeparator && [] !== $this->body) ? 1 : 0;

        return 1 + $separatorHeight + count($this->body);
    }

    public function boxWidth(): int
    {
        $badgeWidth = $this->style->badge?->width() ?? 0;

        return $this->contentWidth()
            + 2 * ($this->style->borderStyle->thickness() + self::HORIZONTAL_PADDING)
            + $this->style->borderStyle->badgeExtraWidth($badgeWidth);
    }

    public function boxHeight(): int
    {
        return $this->contentHeight() + 2 * $this->style->borderStyle->thickness();
    }
}
