<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\ProcessRunner;
use Waynestate\Proctor\ToolError;

/**
 * Tests the ProcessRunner: a normal process returns its output and exit
 * code, and a child process that exceeds the timeout is killed and reported
 * as a tool error naming the command.
 */
final class ProcessRunnerTest extends TestCase
{
    public function testNormalProcessReturnsOutputAndExitCode(): void
    {
        $runner = new ProcessRunner();

        $result = $runner->run(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(3);'],
            __DIR__,
            300,
        );

        self::assertSame(3, $result->exitCode);
        self::assertSame('out', $result->stdout);
        self::assertSame('err', $result->stderr);
    }

    public function testChildProcessOverTimeoutIsKilledAndReported(): void
    {
        $runner = new ProcessRunner();

        $start = microtime(true);
        try {
            $runner->run([PHP_BINARY, '-r', 'sleep(5);'], __DIR__, 1);
            self::fail('Expected a ToolError for a process that exceeds the timeout.');
        } catch (ToolError $e) {
            $elapsed = microtime(true) - $start;
        }

        self::assertStringContainsString(PHP_BINARY, $e->getMessage());
        self::assertStringContainsString('timed out after 1 seconds', $e->getMessage());
        self::assertLessThan(
            4.0,
            $elapsed,
            'The process should be killed well before its sleep(5) finishes.',
        );
    }
}
