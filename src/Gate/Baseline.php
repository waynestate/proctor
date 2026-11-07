<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Gate;

use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\ToolError;

/**
 * What the running deployment already fails: the rule ids the ratchet holds
 * instead of blocking.
 *
 * It comes from one of two places. A JSON report (`proctor gate --format=json`,
 * the file `--record` writes, or `proctor check --format=json`) gives its fail
 * findings. A directory (the running deployment's source) is checked with the
 * same gate rules, its own overrides and the same policy and date. Last
 * term's mark sheet, so this term's paper is graded on progress.
 */
final class Baseline
{
    public const SOURCE_REPORT = 'report';

    public const SOURCE_DIRECTORY = 'directory';

    /**
     * @param string       $source       SOURCE_REPORT or SOURCE_DIRECTORY
     * @param string       $path         the --baseline value, as given
     * @param list<string> $failingRules the rule ids that failed on the baseline, each once, in first-seen order
     */
    public function __construct(
        public readonly string $source,
        public readonly string $path,
        public readonly array $failingRules,
    ) {}

    /**
     * Whether the baseline already failed this rule.
     */
    public function failed(string $ruleId): bool
    {
        return in_array($ruleId, $this->failingRules, true);
    }

    /**
     * The baseline of a directory that was just checked.
     *
     * @param list<Finding> $findings the directory's findings, overrides applied
     */
    public static function fromFindings(string $path, array $findings): self
    {
        $rules = [];
        foreach ($findings as $finding) {
            if (Finding::STATUS_FAIL === $finding->status && !in_array($finding->ruleId, $rules, true)) {
                $rules[] = $finding->ruleId;
            }
        }

        return new self(self::SOURCE_DIRECTORY, $path, $rules);
    }

    /**
     * The baseline in a PROCTOR JSON report file.
     *
     * @throws ToolError when the file is unreadable, is not JSON, or is not a PROCTOR report with a findings list
     */
    public static function fromReportFile(string $file): self
    {
        $raw = is_readable($file) ? file_get_contents($file) : false;
        if (false === $raw) {
            throw new ToolError(sprintf('Baseline "%s" could not be read.', $file));
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ToolError(sprintf('Baseline "%s" is not valid JSON: %s', $file, json_last_error_msg()));
        }
        if (($data['tool'] ?? null) !== 'proctor' || !isset($data['findings']) || !is_array($data['findings']) || !array_is_list($data['findings'])) {
            throw new ToolError(sprintf(
                'Baseline "%s" is not a PROCTOR JSON report with a findings list (write one with `proctor gate --record` or `proctor check --format=json`).',
                $file,
            ));
        }

        $rules = [];
        foreach ($data['findings'] as $index => $finding) {
            if (!is_array($finding) || !is_string($finding['status'] ?? null)) {
                throw new ToolError(sprintf('Baseline "%s": finding %d has no status.', $file, $index));
            }
            if (Finding::STATUS_FAIL !== $finding['status']) {
                continue;
            }
            $rule = $finding['rule_id'] ?? null;
            if (!is_string($rule) || '' === $rule) {
                // An older report without rule ids still names its check.
                $check = $finding['check_id'] ?? null;
                if (!is_string($check) || '' === $check) {
                    throw new ToolError(sprintf('Baseline "%s": finding %d has no rule_id or check_id.', $file, $index));
                }
                $rule = 'proctor/' . $check;
            }
            if (!in_array($rule, $rules, true)) {
                $rules[] = $rule;
            }
        }

        return new self(self::SOURCE_REPORT, $file, $rules);
    }
}
