<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\ToolError;

/**
 * Runs an external process with an argument array (no shell) and captures
 * its output. Built on proc_open from PHP core so PROCTOR gains no runtime
 * dependency for it.
 */
final class ProcessRunner implements ProcessRunnerInterface
{
    /**
     * @param list<string> $command executable path followed by its arguments
     * @param string       $cwd     working directory of the process
     * @param int          $timeout maximum seconds to wait before killing the process
     *
     * @throws ToolError if the process cannot be started or times out
     */
    public function run(array $command, string $cwd, int $timeout = 300): ProcessResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new ToolError(sprintf(
                'Could not start process "%s".',
                implode(' ', $command)
            ));
        }

        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        $open = [$pipes[1], $pipes[2]];

        while ($open !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $this->kill($process, $pipes, $command, $timeout);
            }

            $read = $open;
            $write = null;
            $except = null;
            $seconds = (int) floor($remaining);
            $microseconds = (int) (($remaining - $seconds) * 1000000);

            $changed = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($changed === false || $changed === 0) {
                $this->kill($process, $pipes, $command, $timeout);
            }

            foreach ($read as $key => $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    fclose($stream);
                    unset($open[$key]);
                } elseif ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        $exitCode = proc_close($process);

        return new ProcessResult(
            $exitCode,
            $stdout,
            $stderr
        );
    }

    /**
     * Kills the process and throws a ToolError naming the command.
     *
     * @param resource          $process
     * @param array<int, mixed> $pipes
     * @param list<string>      $command
     */
    private function kill($process, array $pipes, array $command, int $timeout): never
    {
        proc_terminate($process, 9);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
        throw new ToolError(sprintf(
            'Process "%s" timed out after %d seconds.',
            implode(' ', $command),
            $timeout
        ));
    }
}
