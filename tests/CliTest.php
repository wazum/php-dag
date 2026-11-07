<?php

declare(strict_types=1);

namespace PhpDag\Tests;

use PhpDag\Style\AnsiColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    #[Test]
    public function rendersDotFromStdin(): void
    {
        $result = $this->runCli([], 'digraph { a -> b; }');

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('│ a │', $result['stdout']);
        self::assertStringContainsString('│ b │', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    #[Test]
    public function colorsANodeFromItsDotColorOnlyWithAnsiOutput(): void
    {
        $dot = 'digraph { a [color=red]; }';

        $ansi = $this->runCli(['--ansi'], $dot);
        $plain = $this->runCli(['--no-ansi'], $dot);

        self::assertStringContainsString(AnsiColor::Red->escapeCode(), $ansi['stdout']);
        self::assertStringNotContainsString("\033[", $plain['stdout']);
        self::assertStringContainsString('│ a │', $plain['stdout']);
    }

    #[Test]
    public function asciiFlagRendersWithoutUnicodeGlyphs(): void
    {
        $result = $this->runCli(['--ascii'], 'digraph { a -> b; }');

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('| a |', $result['stdout']);
        self::assertStringNotContainsString('─', $result['stdout']);
    }

    #[Test]
    public function directionFlagOverridesFlowToLeftToRight(): void
    {
        $topToBottom = $this->runCli([], 'digraph { a -> b; }');
        $leftToRight = $this->runCli(['--direction=lr'], 'digraph { a -> b; }');

        self::assertSame(0, $leftToRight['exitCode']);
        self::assertNotSame($topToBottom['stdout'], $leftToRight['stdout']);
    }

    #[Test]
    public function helpFlagPrintsUsageAndExitsZero(): void
    {
        $result = $this->runCli(['--help']);

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('Usage: php-dag', $result['stdout']);
    }

    #[Test]
    public function unknownOptionFailsWithMessage(): void
    {
        $result = $this->runCli(['--bogus']);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('Unknown option "--bogus"', $result['stderr']);
    }

    #[Test]
    public function emptyInputFails(): void
    {
        $result = $this->runCli([], '   ');

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('Empty input', $result['stderr']);
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('documentedOptions')]
    public function honoursEveryDocumentedOption(array $arguments, string $stdin, int $exitCode, string $expectedOutput): void
    {
        $result = $this->runCli($arguments, $stdin);

        self::assertSame($exitCode, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString($expectedOutput, 0 === $exitCode ? $result['stdout'] : $result['stderr']);
    }

    /** @return iterable<string, array{list<string>, string, int, string}> */
    public static function documentedOptions(): iterable
    {
        $file = __DIR__.'/Fixtures/cli-input.dot';
        $fanOut = 'digraph { a -> b; a -> c }';

        yield 'a file path' => [[$file], '', 0, '│ b │  │ c │'];
        yield 'a dash reads stdin' => [['-'], $fanOut, 0, '│ b │  │ c │'];
        yield 'node spacing' => [['--node-spacing=5', $file], '', 0, '│ b │     │ c │'];
        yield 'rank spacing' => [['--rank-spacing=4', $file], '', 0, "╰─┬─╯\n     │\n     │\n     │\n"];
        yield 'fast quality' => [['--quality=fast', $file], '', 0, '│ b │  │ c │'];
        yield 'quality quality' => [['--quality=QUALITY', $file], '', 0, '│ b │  │ c │'];
        yield 'forced ansi' => [['--ansi', $file], '', 0, '│ b │  │ c │'];
        yield 'plain output' => [['--no-ansi', $file], '', 0, '│ b │  │ c │'];
        yield 'rankdir from the file' => [[], 'digraph { rankdir=LR; a -> b }', 0, '│ a ├─▶┤ b │'];
        yield 'a missing file' => [[__DIR__.'/Fixtures/missing.dot'], '', 1, 'Cannot read input from'];
        yield 'two input files' => [[$file, $file], '', 1, 'Only one input file is supported.'];
    }

    #[Test]
    public function aDirectoryPathFailsWithAClearMessage(): void
    {
        $result = $this->runCli([__DIR__]);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('is not a file', $result['stderr']);
    }

    #[Test]
    public function showsTheUsageInsteadOfWaitingWhenStdinIsATerminal(): void
    {
        $result = $this->runCliInTerminal([]);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('Usage: php-dag', $result['stderr']);
    }

    #[Test]
    public function invalidDotReportsAParseError(): void
    {
        $result = $this->runCli([], 'graph { a -- b; }');

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('DOT parse error', $result['stderr']);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runCli(array $arguments, string $stdin = ''): array
    {
        $command = [PHP_BINARY, dirname(__DIR__).'/bin/php-dag', ...$arguments];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open($command, $descriptors, $pipes);
        self::assertIsResource($process);

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exitCode' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Runs the CLI with a terminal on stdin and stdout, the way a person runs it.
     *
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runCliInTerminal(array $arguments, array $environment = []): array
    {
        $command = [PHP_BINARY, dirname(__DIR__).'/bin/php-dag', ...$arguments];
        $descriptors = [0 => ['pty'], 1 => ['pty'], 2 => ['pipe', 'w']];

        $process = proc_open($command, $descriptors, $pipes, null, [...getenv(), ...$environment]);
        self::assertIsResource($process);

        $stdout = (string) @stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return ['exitCode' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
