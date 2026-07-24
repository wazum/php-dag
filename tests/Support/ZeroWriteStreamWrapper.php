<?php

declare(strict_types=1);

namespace PhpDag\Tests\Support;

/**
 * A writable stream whose every write reports zero bytes written, simulating a
 * stalled sink. Lets tests exercise the zero-byte-write guard in
 * LayoutResult::writeAll() that a read-only stream (fwrite === false) cannot.
 */
final class ZeroWriteStreamWrapper
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }
}
