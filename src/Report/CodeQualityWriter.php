<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Report;

use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\ToolError;

/**
 * The GitLab Code Quality format: a single JSON array on stdout, one object
 * per non-pass finding, so merge requests show the findings.
 *
 * The severity mapping and the fingerprint live only here.
 */
final class CodeQualityWriter implements ReportWriter
{
    /** The location path used when a finding has none of its own. */
    private const DEFAULT_PATH = 'composer.json';

    public function write(Report $report): string
    {
        $items = [];
        foreach ($report->findings as $finding) {
            if (Finding::STATUS_PASS === $finding->status) {
                continue;
            }

            $items[] = [
                'description' => $finding->message,
                'check_name' => $finding->checkId,
                'fingerprint' => Fingerprint::of($finding->checkId, $finding->path, $finding->message),
                'severity' => $this->severity($finding->status),
                'location' => [
                    'path' => $finding->path ?? self::DEFAULT_PATH,
                    'lines' => [
                        'begin' => $finding->line,
                    ],
                ],
            ];
        }

        $json = json_encode(
            $items,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (false === $json) {
            throw new ToolError(sprintf(
                'Could not encode the code quality report: %s',
                json_last_error_msg()
            ));
        }

        return $json;
    }

    /**
     * fail -> major, warning -> minor, info -> info.
     */
    private function severity(string $status): string
    {
        return match ($status) {
            Finding::STATUS_FAIL => 'major',
            Finding::STATUS_WARNING => 'minor',
            Finding::STATUS_INFO => 'info',
        };
    }
}
