<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Report;

use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\ToolError;

/**
 * The machine-readable JSON format: one pretty-printed object on stdout.
 *
 * A finding that carries advisory data gets an "advisories" array with its
 * entries; findings without advisory data carry no such key.
 */
final class JsonWriter implements ReportWriter
{
    public function write(Report $report): string
    {
        $data = [
            'tool' => Report::TOOL,
            'version' => Report::version(),
            'path' => $report->path,
            'today' => $report->today->format('Y-m-d'),
            'result' => $report->result(),
            'exit' => $report->exitCode,
            'tools' => $report->tools,
            'policy' => [
                'origin' => $report->resolvedPolicy->origin,
                'path' => $report->resolvedPolicy->path,
                'refreshed_on' => $report->resolvedPolicy->refreshedOn,
                'age_days' => $report->resolvedPolicy->ageDays($report->today),
            ],
            'findings' => array_map(
                static function (Finding $finding): array {
                    $data = [
                        'check_id' => $finding->checkId,
                        'rule_id' => $finding->ruleId,
                        'status' => $finding->status,
                        'message' => $finding->message,
                        'path' => $finding->path,
                        'line' => $finding->line,
                    ];
                    if ($finding->advisories !== []) {
                        $data['advisories'] = $finding->advisories;
                    }

                    return $data;
                },
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

        return $this->encode($data);
    }

    /**
     * The JSON report when the run could not decide: the report shape with no
     * findings, result "error", and the message in an error field, so the CI
     * upload still gets a valid document.
     */
    public function writeToolError(string $message, string $path): string
    {
        return $this->encodeToolError([
            'tool' => Report::TOOL,
            'version' => Report::version(),
            'path' => $path,
            'today' => null,
            'result' => 'error',
            'exit' => 2,
            'tools' => [],
            'policy' => null,
            'error' => $message,
            'findings' => [],
            'overrides' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
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

    /**
     * Like encode(), but for the tool-error report: the message may carry
     * app-controlled text, so invalid UTF-8 is substituted instead of
     * failing, which keeps the error report itself encodable.
     *
     * @param array<string, mixed> $data
     */
    private function encodeToolError(array $data): string
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
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
