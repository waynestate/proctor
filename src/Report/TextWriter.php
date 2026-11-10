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
 *   proctor 0.15.1 | composer: 2.11.1
 *   Policy: option (/app/policy.json), refreshed 2026-10-20, 7 days ago
 *   PASS php-floor: php floor 8.4 is active
 *   FAIL unbounded-constraints: vendor/a constraint "dev-master" is unbounded
 *   OVERRIDE lock-committed: x (expires 2026-01-01, approved by t)
 *   RESULT: fail (1 failures, 0 warnings)
 *
 * Finding messages, override reasons and approvers come from files the app
 * controls, so they are printed with terminal control characters replaced
 * by a visible "?": an escape sequence or carriage return in one of them
 * must not reach the log.
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
                $this->sanitize($finding->message),
            );
        }

        foreach ($report->overrides as $override) {
            $lines[] = sprintf(
                'OVERRIDE %s: %s (expires %s, approved by %s)',
                $override->check,
                $this->sanitize($override->reason),
                $override->displayDate(),
                $this->sanitize($override->approvedBy),
            );
        }

        $lines[] = $this->summaryLine($report);

        return implode("\n", $lines);
    }

    /**
     * The report body when the run could not decide: the failure label the
     * finding lines use, the tool as the check id, and the message, so a
     * tool error reads like any other failed line.
     */
    public function writeToolError(string $message): string
    {
        return sprintf('FAIL %s: %s', Report::TOOL, $this->sanitize($message));
    }

    /**
     * The header line: the tool and its version, then each external tool's
     * version, "unknown" when it could not be determined.
     */
    private function headerLine(Report $report): string
    {
        $parts = [sprintf('%s %s', Report::TOOL, Report::version())];
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

    /**
     * Replaces terminal control characters with a visible "?" (Terminal::clean()).
     */
    private function sanitize(string $text): string
    {
        return Terminal::clean($text);
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
