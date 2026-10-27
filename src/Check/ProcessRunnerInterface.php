<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\ToolError;

/**
 * Runs an external process and captures its output.
 *
 * The concrete implementation is ProcessRunner; the interface exists so a
 * check can be tested with a stub that returns a fixed ProcessResult.
 */
interface ProcessRunnerInterface
{
    /**
     * @param list<string> $command executable path followed by its arguments
     * @param string       $cwd     working directory of the process
     * @param int          $timeout maximum seconds to wait before killing the process
     *
     * @throws ToolError if the process cannot be started or times out
     */
    public function run(array $command, string $cwd, int $timeout = 300): ProcessResult;
}
