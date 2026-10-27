<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Shared helper for tests that run bin/proctor as a subprocess.
 *
 * Every run uses the fixture policy and a fixed --today so the results are
 * deterministic on any day.
 */
abstract class ProctorTestCase extends TestCase
{
    /** The fixed date every CLI test runs with. */
    protected const TODAY = '2026-10-27';

    /**
     * The absolute path of a fixture app.
     */
    protected function app(string $name): string
    {
        return __DIR__ . '/fixtures/apps/' . $name;
    }

    /**
     * Runs bin/proctor check on the given app and returns the exit code,
     * stdout and stderr.
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    protected function runProctor(string $app, string $format = 'text'): array
    {
        $cmd = [
            PHP_BINARY,
            dirname(__DIR__) . '/bin/proctor',
            'check',
            '--path=' . $app,
            '--format=' . $format,
            '--policy=' . __DIR__ . '/fixtures/policy.json',
            '--today=' . self::TODAY,
        ];

        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            self::fail('could not start bin/proctor');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    /**
     * The check ids of the FAIL lines in a text report, in output order.
     *
     * @return list<string>
     */
    protected function failCheckIds(string $stdout): array
    {
        preg_match_all('/^FAIL (\S+): /m', $stdout, $matches);

        return $matches[1];
    }

    /**
     * The check ids with at least one non-pass line (FAIL, WARN or INFO) in
     * a text report.
     *
     * @return list<string>
     */
    protected function nonPassCheckIds(string $stdout): array
    {
        preg_match_all('/^(?:FAIL|WARN|INFO) (\S+): /m', $stdout, $matches);

        return array_values(array_unique($matches[1]));
    }
}
