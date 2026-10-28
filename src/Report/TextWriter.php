<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Report;

use Waynestate\Proctor\Check\Finding;

/**
 * The default human-readable format.
 *
 * One line per finding in check order, then one line per override, then a
 * summary line:
 *
 *   PASS php-floor: php floor 8.4 is active
 *   FAIL unbounded-constraints: vendor/a constraint "dev-master" is unbounded
 *   OVERRIDE lock-committed: x (expires 2026-01-01, approved by t)
 *   RESULT: fail (1 failures, 0 warnings)
 */
final class TextWriter implements ReportWriter
{
    public function write(Report $report): string
    {
        $lines = [];
        foreach ($report->findings as $finding) {
            $lines[] = sprintf(
                '%s %s: %s',
                $this->statusLabel($finding->status),
                $finding->checkId,
                $finding->message,
            );
        }

        foreach ($report->overrides as $override) {
            $lines[] = sprintf(
                'OVERRIDE %s: %s (expires %s, approved by %s)',
                $override->check,
                $override->reason,
                $override->displayDate(),
                $override->approvedBy,
            );
        }

        $lines[] = $this->summaryLine($report);

        return implode("\n", $lines);
    }

    private function summaryLine(Report $report): string
    {
        if (Report::EXIT_PASS === $report->exitCode) {
            return 'RESULT: pass';
        }

        return sprintf(
            'RESULT: fail (%d failures, %d warnings)',
            $report->countByStatus(Finding::STATUS_FAIL),
            $report->countByStatus(Finding::STATUS_WARNING),
        );
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            Finding::STATUS_PASS => 'PASS',
            Finding::STATUS_INFO => 'INFO',
            Finding::STATUS_WARNING => 'WARN',
            Finding::STATUS_FAIL => 'FAIL',
        };
    }
}
