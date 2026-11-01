<?php

declare(strict_types=1);

namespace PhpDag;

use InvalidArgumentException;
use PhpDag\Render\Canvas;
use PhpDag\Render\OutputFormatter;
use PhpDag\Render\PlainTextFormatter;
use RuntimeException;

/**
 * Immutable result of laying out and drawing a graph: the diagram has already
 * been computed onto a canvas, so it can be rendered to a string, streamed to a
 * resource, or queried for its dimensions without recomputing the layout.
 */
final readonly class LayoutResult
{
    public function __construct(
        private Canvas $canvas,
        private OutputFormatter $formatter,
    ) {
    }

    public function render(): string
    {
        return $this->formatter->format($this->canvas);
    }

    /**
     * Streams the rendered diagram to a writable resource one line at a time,
     * producing the same bytes as render() without holding the whole string.
     *
     * @param resource $stream
     */
    public function renderTo($stream): void
    {
        $streamMetadata = stream_get_meta_data($stream);
        if (1 !== preg_match('/[waxc+]/', $streamMetadata['mode'])) {
            throw new InvalidArgumentException('The output stream must be writable');
        }

        $first = true;
        foreach ($this->formatter->rows($this->canvas) as $line) {
            if (!$first) {
                $this->writeAll($stream, "\n");
            }
            $this->writeAll($stream, $line);
            $first = false;
        }
    }

    /** Visible width of the diagram in terminal columns. */
    public function width(): int
    {
        return $this->dimensions()[0];
    }

    /** Visible height of the diagram in rows. */
    public function height(): int
    {
        return $this->dimensions()[1];
    }

    /**
     * Measured from the plain geometry, never from the render formatter, whose
     * ANSI escape codes would inflate the width.
     *
     * @return array{int, int}
     */
    private function dimensions(): array
    {
        $width = 0;
        $height = 0;
        foreach ((new PlainTextFormatter())->rows($this->canvas) as $line) {
            ++$height;
            $width = max($width, mb_strwidth($line));
        }

        return [$width, $height];
    }

    /** @param resource $stream */
    private function writeAll($stream, string $content): void
    {
        $remainingContent = $content;
        while ('' !== $remainingContent) {
            $bytesWritten = @fwrite($stream, $remainingContent);
            if (false === $bytesWritten || 0 === $bytesWritten) {
                throw new RuntimeException('Failed to write the rendered graph to the output stream');
            }
            $remainingContent = substr($remainingContent, $bytesWritten);
        }
    }
}
