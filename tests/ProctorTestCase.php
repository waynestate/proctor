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
     * @param string      $app        a fixture app name
     * @param string      $format     the report format
     * @param string|null $policy     an optional policy file; defaults to the fixture policy
     * @param string|null $phpRuntime an optional --php-runtime value (X.Y.Z)
     * @param string|null $today      an optional --today value (YYYY-MM-DD); defaults to self::TODAY
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    protected function runProctor(string $app, string $format = 'text', ?string $policy = null, ?string $phpRuntime = null, ?string $today = null): array
    {
        $cmd = [
            PHP_BINARY,
            dirname(__DIR__) . '/bin/proctor',
            'check',
            '--path=' . $app,
            '--format=' . $format,
            '--policy=' . ($policy ?? __DIR__ . '/fixtures/policy.json'),
            '--today=' . ($today ?? self::TODAY),
        ];
        if ($phpRuntime !== null) {
            $cmd[] = '--php-runtime=' . $phpRuntime;
        }

        return $this->runCommand($cmd);
    }

    /**
     * Runs bin/proctor check without forcing --policy, so the resolution
     * order decides: the PROCTOR_POLICY environment variable, the app's
     * vendor/waynestate/creed/policy/policy.json, the bundled copy.
     *
     * The host's PROCTOR_POLICY never leaks in; set it with $envPolicy.
     *
     * @param string      $app       the app path
     * @param string      $format    the report format
     * @param string|null $policy    an explicit --policy value, or null
     * @param string|null $envPolicy the PROCTOR_POLICY value, or null
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    protected function runProctorResolved(string $app, string $format = 'text', ?string $policy = null, ?string $envPolicy = null): array
    {
        $cmd = [
            PHP_BINARY,
            dirname(__DIR__) . '/bin/proctor',
            'check',
            '--path=' . $app,
            '--format=' . $format,
            '--today=' . self::TODAY,
        ];
        if ($policy !== null) {
            $cmd[] = '--policy=' . $policy;
        }

        return $this->runCommand($cmd, $envPolicy);
    }

    /**
     * Runs bin/proctor with a PATH that finds no tools, and returns the
     * exit code, stdout and stderr.
     *
     * @param list<string> $cmd
     */
    private function runCommand(array $cmd, ?string $envPolicy = null): array
    {
        // The CLI tests must not depend on whether composer is installed (the CI runner has it, the php:*-cli
        // images do not), so bin/proctor runs with a PATH that finds no tools.
        $env = getenv();
        foreach (array_keys($env) as $key) {
            if (strcasecmp((string) $key, 'PATH') === 0 || strcasecmp((string) $key, 'PROCTOR_POLICY') === 0) {
                unset($env[$key]);
            }
        }
        $env['PATH'] = __DIR__ . '/fixtures/no-tools';
        if ($envPolicy !== null) {
            $env['PROCTOR_POLICY'] = $envPolicy;
        }

        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
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
