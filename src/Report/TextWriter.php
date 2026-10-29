<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Report;

use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\ToolError;

/**
 * The default human-readable format.
 *
 * A header line with the tool and the versions of the external tools used,
 * then one line per finding in check order, then one line per override, then
 * a summary line:
 *
 *   proctor 0.1.0 | composer: 2.10.3
 *   Policy: option (/app/policy.json), refreshed 2026-10-20, 7 days ago
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
        if ($report->tools !== []) {
            $lines[] = $this->headerLine($report);
        }
        $lines[] = $this->policyLine($report);
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

    /**
     * The header line: the tool and its version, then each external tool's
     * version, "unknown" when it could not be determined.
     */
    private function headerLine(Report $report): string
    {
        $parts = [sprintf('%s %s', Report::TOOL, Report::VERSION)];
        foreach ($report->tools as $name => $version) {
            $parts[] = sprintf('%s: %s', $name, $version ?? 'unknown');
        }

        return implode(' | ', $parts);
    }

    /**
     * The policy line: where the policy came from, and how old it is.
     */
    private function policyLine(Report $report): string
    {
        $resolved = $report->resolvedPolicy;
        $base = sprintf('Policy: %s (%s)', $resolved->origin, $resolved->path);
        $age = $resolved->ageDays($report->today);
        if ($age === null || $resolved->refreshedOn === null) {
            return $base . ', no refresh date';
        }

        return sprintf('%s, refreshed %s, %d days ago', $base, $resolved->refreshedOn, $age);
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
            default => throw new ToolError(sprintf('Unknown finding status "%s".', $status)),
        };
    }
}
