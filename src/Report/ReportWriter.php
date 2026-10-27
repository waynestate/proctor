<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Report;

/**
 * Renders a Report for stdout.
 *
 * write() returns the exact text to print, without a trailing newline; the
 * CLI adds the final newline. A writer must not print anything itself: the
 * returned string is the only stdout output of the run.
 */
interface ReportWriter
{
    public function write(Report $report): string;
}
