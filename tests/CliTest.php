<?php

declare(strict_types=1);

namespace PhpDag\Tests;

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
}
