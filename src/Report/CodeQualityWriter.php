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

            $items[] = $this->item(
                $finding->message,
                $finding->checkId,
                $finding->path,
                $finding->line,
                $this->severity($finding->status),
            );
        }

        return $this->encode($items);
    }

    /**
     * A single issue describing a tool error, so a run that cannot proceed
     * still shows up in the merge request instead of an empty array.
     */
    public function writeToolError(string $message): string
    {
        return $this->encode([$this->item(
            $message,
            Report::TOOL,
            null,
            1,
            'blocker',
        )]);
    }

    /**
     * One Code Quality item in the GitLab shape.
     *
     * @return array<string, mixed>
     */
    private function item(string $message, string $checkName, ?string $path, int $line, string $severity): array
    {
        return [
            'description' => $message,
            'check_name' => $checkName,
            'fingerprint' => Fingerprint::of($checkName, $path, $message),
            'severity' => $severity,
            'location' => [
                'path' => $path ?? self::DEFAULT_PATH,
                'lines' => [
                    'begin' => $line,
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function encode(array $items): string
    {
        $json = json_encode(
            $items,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (false === $json) {
            throw new ToolError(sprintf(
                'Could not encode the code quality report: %s',
                json_last_error_msg(),
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
