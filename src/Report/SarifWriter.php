<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Report;

use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\ToolError;

/**
 * The SARIF 2.1.0 log format: one run, one result per non-pass finding, so
 * GitHub code scanning (the GitHub mirror) and other SARIF viewers show the
 * findings.
 *
 * The level mapping and the fingerprint live only here.
 *
 * The invigilator files the mark sheet in the format the head office already
 * reads. No more translating the exam results by hand.
 */
final class SarifWriter implements ReportWriter
{
    /** The location path used when a finding has none of its own. */
    private const DEFAULT_PATH = 'composer.json';

    /** The SARIF schema URI. */
    private const SCHEMA = 'https://json.schemastore.org/sarif-2.1.0.json';

    /** The SARIF log version. */
    private const VERSION = '2.1.0';

    /** The tool's information URI. */
    private const INFORMATION_URI = 'https://git.wayne.edu/enterprise-apps/proctor';

    public function write(Report $report): string
    {
        return $this->encode([
            '$schema' => self::SCHEMA,
            'version' => self::VERSION,
            'runs' => [
                [
                    'tool' => [
                        'driver' => [
                            'name' => Report::TOOL,
                            'version' => Report::version(),
                            'informationUri' => self::INFORMATION_URI,
                            'rules' => $this->rules($report),
                        ],
                    ],
                    'results' => $this->results($report),
                ],
            ],
        ]);
    }

    /**
     * One rule entry per distinct rule id in the report, in first-seen order.
     *
     * @return list<array<string, mixed>>
     */
    private function rules(Report $report): array
    {
        $rules = [];
        $seen = [];
        foreach ($report->findings as $finding) {
            $id = $finding->ruleId;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $rules[] = [
                'id' => $id,
                'shortDescription' => [
                    'text' => $id,
                ],
            ];
        }

        return $rules;
    }

    /**
     * One result per non-pass finding.
     *
     * @return list<array<string, mixed>>
     */
    private function results(Report $report): array
    {
        $results = [];
        foreach ($report->findings as $finding) {
            if (Finding::STATUS_PASS === $finding->status) {
                continue;
            }

            $results[] = $this->result($finding);
        }

        return $results;
    }

    /**
     * One SARIF result for a single finding.
     *
     * @return array<string, mixed>
     */
    private function result(Finding $finding): array
    {
        return [
            'ruleId' => $finding->ruleId,
            'level' => $this->level($finding->status),
            'message' => [
                'text' => $finding->message,
            ],
            'locations' => [
                $this->location($finding),
            ],
            'partialFingerprints' => [
                'proctorFingerprint/v1' => Fingerprint::of($finding->ruleId, $finding->path, $finding->message),
            ],
        ];
    }

    /**
     * The physical location of a finding: its file and line when it has one,
     * otherwise the app's composer.json.
     *
     * @return array<string, mixed>
     */
    private function location(Finding $finding): array
    {
        $physical = [
            'artifactLocation' => [
                'uri' => $finding->path !== null
                    ? str_replace('\\', '/', $finding->path)
                    : self::DEFAULT_PATH,
            ],
        ];

        if ($finding->path !== null) {
            $physical['region'] = [
                'startLine' => $finding->line,
            ];
        }

        return [
            'physicalLocation' => $physical,
        ];
    }

    /**
     * fail -> error, warning -> warning, anything else that is not pass -> note.
     */
    private function level(string $status): string
    {
        return match ($status) {
            Finding::STATUS_FAIL => 'error',
            Finding::STATUS_WARNING => 'warning',
            default => 'note',
        };
    }

    /**
     * @param array<string, mixed> $log
     */
    private function encode(array $log): string
    {
        $json = json_encode(
            $log,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (false === $json) {
            throw new ToolError(sprintf(
                'Could not encode the SARIF report: %s',
                json_last_error_msg(),
            ));
        }

        return $json;
    }
}
