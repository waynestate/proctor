<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Report;

use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\ToolError;

/**
 * The machine-readable JSON format: one pretty-printed object on stdout.
 */
final class JsonWriter implements ReportWriter
{
    public function write(Report $report): string
    {
        $data = [
            'tool' => Report::TOOL,
            'version' => Report::VERSION,
            'path' => $report->path,
            'today' => $report->today->format('Y-m-d'),
            'result' => $report->result(),
            'exit' => $report->exitCode,
            'tools' => $report->tools,
            'findings' => array_map(
                static fn(Finding $finding): array => [
                    'check_id' => $finding->checkId,
                    'status' => $finding->status,
                    'message' => $finding->message,
                    'path' => $finding->path,
                    'line' => $finding->line,
                ],
                $report->findings,
            ),
            'overrides' => array_map(
                static fn(Override $override): array => [
                    'check' => $override->check,
                    'reason' => $override->reason,
                    'expires' => $override->displayDate(),
                    'approved_by' => $override->approvedBy,
                    'expired' => $override->isExpired($report->today),
                ],
                $report->overrides,
            ),
        ];

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (false === $json) {
            throw new ToolError(sprintf(
                'Could not encode the JSON report: %s',
                json_last_error_msg(),
            ));
        }

        return $json;
    }
}
