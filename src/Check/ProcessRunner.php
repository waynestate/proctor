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
     *
     * @throws ToolError if the process cannot be started
     */
    public function run(array $command, string $cwd): ProcessResult
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
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return new ProcessResult(
            $exitCode,
            $stdout === false ? '' : $stdout,
            $stderr === false ? '' : $stderr
        );
    }
}
