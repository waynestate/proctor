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
     * @param string                $app        a fixture app name
     * @param string                $format     the report format
     * @param string|null           $policy     an optional policy file; defaults to the fixture policy
     * @param string|null           $phpRuntime an optional --php-runtime value (X.Y.Z)
     * @param string|null           $today      an optional --today value (YYYY-MM-DD); defaults to self::TODAY
     * @param string|null           $path       the PATH the child process uses; defaults to a directory that finds no tools
     * @param array<string, string> $extraEnv   environment variables for the child, merged last
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    protected function runProctor(string $app, string $format = 'text', ?string $policy = null, ?string $phpRuntime = null, ?string $today = null, ?string $path = null, array $extraEnv = []): array
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

        return $this->runCommand($cmd, null, $path, $extraEnv);
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
     * Runs bin/proctor check with the given arguments. Like runGate(), it
     * adds nothing: pass --path, --policy and --today yourself.
     *
     * @param list<string> $args the arguments after `check`
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    protected function runCheck(array $args): array
    {
        return $this->runCommand([PHP_BINARY, dirname(__DIR__) . '/bin/proctor', 'check', ...$args]);
    }

    /**
     * Runs bin/proctor gate with the given arguments. Unlike runProctor(), it
     * adds nothing: pass --path, --policy and --today yourself.
     *
     * @param list<string>          $args     the arguments after `gate`
     * @param array<string, string> $extraEnv environment variables for the child, merged last
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    protected function runGate(array $args, array $extraEnv = []): array
    {
        return $this->runCommand([PHP_BINARY, dirname(__DIR__) . '/bin/proctor', 'gate', ...$args], null, null, $extraEnv);
    }

    /**
     * Runs bin/proctor with a PATH that finds no tools (unless $path gives
     * one), and returns the exit code, stdout and stderr.
     *
     * The CI markers (CI, GITLAB_CI, GITHUB_ACTIONS) and PROCTOR_ALLOW_OVERRIDES
     * never leak in from the host; pass them with $extraEnv.
     *
     * @param list<string>          $cmd
     * @param string|null           $envPolicy the PROCTOR_POLICY value, or null
     * @param array<string, string> $extraEnv  environment variables for the child, merged last
     */
    private function runCommand(array $cmd, ?string $envPolicy = null, ?string $path = null, array $extraEnv = []): array
    {
        // The CLI tests must not depend on whether composer is installed (the CI runner has it, the php:*-cli
        // images do not), so bin/proctor runs with a PATH that finds no tools.
        $scrubbed = ['PATH', 'PROCTOR_POLICY', 'CI', 'GITLAB_CI', 'GITHUB_ACTIONS', 'PROCTOR_ALLOW_OVERRIDES'];
        $env = getenv();
        foreach (array_keys($env) as $key) {
            if (in_array(strtoupper((string) $key), $scrubbed, true)) {
                unset($env[$key]);
            }
        }
        $env['PATH'] = $path ?? __DIR__ . '/fixtures/no-tools';
        if ($envPolicy !== null) {
            $env['PROCTOR_POLICY'] = $envPolicy;
        }
        foreach ($extraEnv as $key => $value) {
            $env[(string) $key] = (string) $value;
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
     * A PATH directory holding a stub composer executable, for tests that
     * must reach the audit step even though the CLI runs normally with a
     * PATH that finds no tools. The stub exits 0 with empty output, so
     * ComposerVersionCheck warns instead of failing.
     */
    protected function fakeComposerPath(): string
    {
        $dir = sys_get_temp_dir() . '/proctor-fake-composer';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $composer = $dir . '/composer';
        file_put_contents($composer, "#!/bin/sh\nexit 0\n");
        chmod($composer, 0755);

        return $dir;
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
