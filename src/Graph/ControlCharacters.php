<?php

declare(strict_types=1);

namespace PhpDag\Graph;

/**
 * The control-character policy for user-supplied identifiers and display text.
 *
 * Identifiers and display text must be valid UTF-8 and must not contain C0/C1
 * control characters. A NUL would collide the "\0"-delimited adjacency keys,
 * while a newline or raw ESC/CSI byte would break the ASCII canvas or inject
 * terminal escape sequences into rendered output. Value objects reject unsafe
 * input; ingestion boundaries such as the DOT parser scrub and strip it.
 *
 * @internal
 */
final class ControlCharacters
{
    private const PATTERN = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u';

    public static function present(string $value): bool
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return true;
        }

        return 1 === preg_match(self::PATTERN, $value);
    }

    public static function strip(string $value): string
    {
        $scrubbed = mb_scrub($value, 'UTF-8');

        return (string) preg_replace(self::PATTERN, '', $scrubbed);
    }

    /**
     * Makes untrusted text safe to include in terminal-facing diagnostics.
     */
    public static function escape(string $value): string
    {
        $scrubbed = mb_scrub($value, 'UTF-8');

        return (string) preg_replace_callback(
            self::PATTERN,
            static fn (array $matches): string => sprintf('\\u{%04X}', (int) mb_ord($matches[0], 'UTF-8')),
            $scrubbed,
        );
    }
}
