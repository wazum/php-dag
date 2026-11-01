<?php

declare(strict_types=1);

namespace PhpDag\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function samples(): iterable
    {
        $root = dirname(__DIR__);
        foreach ([$root.'/README.md', ...glob($root.'/guide/*.md') ?: []] as $file) {
            $markdown = (string) file_get_contents($file);
            preg_match_all('/^```(\w*)\n(.*?)^```$/ms', $markdown, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($blocks as $index => [$fence, $language, $content]) {
                $next = $blocks[$index + 1] ?? null;
                if (!in_array($language[0], ['php', 'dot'], true) || null === $next || '' !== $next[1][0]) {
                    continue;
                }
                $end = $fence[1] + strlen($fence[0]);
                if ('' !== trim(substr($markdown, $end, $next[0][1] - $end))) {
                    continue;
                }
                $line = substr_count($markdown, "\n", 0, $fence[1]) + 1;
                yield basename($file).':'.$line => [$language[0], $content[0], $next[2][0]];
            }
        }
    }

    #[Test]
    public function readmeHasSamplesToCheck(): void
    {
        self::assertNotEmpty(iterator_to_array(self::samples()));
    }

    #[Test]
    #[DataProvider('samples')]
    public function sampleRendersTheOutputShownBelowIt(string $language, string $code, string $expected): void
    {
        $actual = 'php' === $language ? $this->runPhp($code) : $this->runCli($code);

        self::assertSame(rtrim($expected, "\n"), rtrim($actual, "\n"));
    }

    private function runPhp(string $code): string
    {
        ob_start();
        try {
            (static function (string $code): void {
                eval($code);
            })($code);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private function runCli(string $dot): string
    {
        $command = [PHP_BINARY, dirname(__DIR__).'/bin/php-dag', '--no-ansi'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        fwrite($pipes[0], $dot);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }
}
