<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Gate;

use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\Report\Report;
use Waynestate\Proctor\Report\Terminal;
use Waynestate\Proctor\ToolError;

/**
 * Writes a gate verdict as a short text report or as JSON.
 *
 * Text, for the deploy log: the verdict first, then the policy and baseline
 * in use, one line per finding that is not a pass, the overrides, and a
 * RESULT line with the counts:
 *
 *   proctor 0.8.0 gate: NO-GO
 *   Policy: bundled (/app/vendor/waynestate/proctor/src/Creed/policy.json), refreshed 2026-10-20, 7 days ago
 *   Baseline: none, so this is a first deploy and every failure blocks
 *   Checks: php-floor, unpatched, laravel-eol, laravel-php, lock-committed, composer-audit, overrides, policy-age
 *   BLOCK php-floor: php floor 7.4 is eol (security support ended 2022-11-28) [new app: no baseline, so every failure blocks]
 *   RESULT: no-go (1 blocked, 0 held, 0 waived, 0 warnings, 3 passed)
 *
 * JSON is the same verdict for machines, and it is also a baseline: its
 * findings keep the check's own status (a held fail is still "fail"), so the
 * next run can read it with --baseline.
 */
final class GateWriter
{
    public function text(GateVerdict $verdict): string
    {
        $lines = [
            sprintf('%s %s gate: %s', Report::TOOL, Report::version(), strtoupper($verdict->verdict())),
            $this->policyLine($verdict),
            $this->baselineLine($verdict->baseline),
            'Checks: ' . implode(', ', $verdict->checkIds),
        ];

        foreach ($verdict->decisions as $decision) {
            if (GateDecision::PASS === $decision->outcome) {
                continue;
            }
            $line = sprintf(
                '%s %s: %s',
                $this->label($decision->outcome),
                $decision->finding->checkId,
                Terminal::clean($decision->finding->message),
            );
            $why = $this->why($decision);
            if (null !== $why) {
                $line .= ' [' . $why . ']';
            }
            $lines[] = $line;
        }

        foreach ($verdict->overrides as $override) {
            $lines[] = sprintf(
                'OVERRIDE %s: %s (expires %s, approved by %s)',
                $override->check,
                Terminal::clean($override->reason),
                $override->displayDate(),
                Terminal::clean($override->approvedBy),
            );
        }

        $lines[] = sprintf(
            'RESULT: %s (%d blocked, %d held, %d waived, %d warnings, %d passed)',
            $verdict->verdict(),
            $verdict->count(GateDecision::BLOCKED),
            $verdict->count(GateDecision::HELD),
            $verdict->count(GateDecision::WAIVED),
            $verdict->count(GateDecision::WARNING),
            $verdict->count(GateDecision::PASS),
        );

        return implode("\n", $lines);
    }

    /**
     * @throws ToolError when the verdict cannot be encoded
     */
    public function json(GateVerdict $verdict): string
    {
        $baseline = $verdict->baseline;
        $data = [
            'tool' => Report::TOOL,
            'version' => Report::version(),
            'command' => 'gate',
            'path' => $verdict->path,
            'today' => $verdict->today->format('Y-m-d'),
            'verdict' => $verdict->verdict(),
            'exit' => $verdict->exitCode(),
            'policy' => [
                'origin' => $verdict->resolvedPolicy->origin,
                'path' => $verdict->resolvedPolicy->path,
                'refreshed_on' => $verdict->resolvedPolicy->refreshedOn,
                'age_days' => $verdict->resolvedPolicy->ageDays($verdict->today),
            ],
            'baseline' => null === $baseline ? null : [
                'source' => $baseline->source,
                'path' => $baseline->path,
                'failing_rules' => $baseline->failingRules,
            ],
            'checks' => $verdict->checkIds,
            'summary' => [
                'blocked' => $verdict->count(GateDecision::BLOCKED),
                'held' => $verdict->count(GateDecision::HELD),
                'waived' => $verdict->count(GateDecision::WAIVED),
                'warnings' => $verdict->count(GateDecision::WARNING),
                'info' => $verdict->count(GateDecision::INFO),
                'passed' => $verdict->count(GateDecision::PASS),
            ],
            'findings' => array_map(
                static function (GateDecision $decision): array {
                    $finding = $decision->finding;
                    $data = [
                        'check_id' => $finding->checkId,
                        'rule_id' => $finding->ruleId,
                        'status' => $finding->status,
                        'gate' => $decision->outcome,
                        'message' => $finding->message,
                        'path' => $finding->path,
                        'line' => $finding->line,
                    ];
                    if (null !== $decision->reason) {
                        $data['reason'] = $decision->reason;
                    }
                    if ($finding->advisories !== []) {
                        $data['advisories'] = $finding->advisories;
                    }

                    return $data;
                },
                $verdict->decisions,
            ),
            'overrides' => array_map(
                static fn(Override $override): array => [
                    'check' => $override->check,
                    'reason' => $override->reason,
                    'expires' => $override->displayDate(),
                    'approved_by' => $override->approvedBy,
                    'expired' => $override->isExpired($verdict->today),
                ],
                $verdict->overrides,
            ),
        ];

        return $this->encode($data);
    }

    /**
     * The JSON a gate prints (and records) when it could not decide: exit 2,
     * verdict "error". A caller such as Citadel fails open on it.
     */
    public function jsonToolError(string $message, string $path): string
    {
        return $this->encode([
            'tool' => Report::TOOL,
            'version' => Report::version(),
            'command' => 'gate',
            'path' => $path,
            'verdict' => 'error',
            'exit' => Gate::EXIT_TOOL_ERROR,
            'error' => $message,
        ]);
    }

    /**
     * The text a gate prints when it could not decide, for the deploy log:
     * the verdict line, the failure line shaped like the finding lines, and
     * the RESULT line.
     */
    public function textToolError(string $message): string
    {
        return implode("\n", [
            sprintf('%s %s gate: ERROR', Report::TOOL, Report::version()),
            sprintf('FAIL %s: %s', Report::TOOL, Terminal::clean($message)),
            'RESULT: error (the gate could not decide)',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (false === $json) {
            throw new ToolError(sprintf('Could not encode the gate verdict: %s', json_last_error_msg()));
        }

        return $json;
    }

    private function policyLine(GateVerdict $verdict): string
    {
        $resolved = $verdict->resolvedPolicy;
        $base = sprintf('Policy: %s (%s)', $resolved->origin, $resolved->path);
        $age = $resolved->ageDays($verdict->today);
        if (null === $age || null === $resolved->refreshedOn) {
            return $base . ', no refresh date';
        }

        return sprintf('%s, refreshed %s, %d days ago', $base, $resolved->refreshedOn, $age);
    }

    private function baselineLine(?Baseline $baseline): string
    {
        if (null === $baseline) {
            return 'Baseline: none, so this is a first deploy and every failure blocks';
        }
        $count = count($baseline->failingRules);

        return sprintf(
            'Baseline: %s %s, %d failing %s',
            $baseline->source,
            Terminal::clean($baseline->path),
            $count,
            1 === $count ? 'rule' : 'rules',
        );
    }

    private function label(string $outcome): string
    {
        return match ($outcome) {
            GateDecision::BLOCKED => 'BLOCK',
            GateDecision::HELD => 'HOLD',
            GateDecision::WAIVED => 'WAIVE',
            GateDecision::INFO => 'INFO',
            GateDecision::WARNING => 'WARN',
            default => 'PASS',
        };
    }

    /**
     * Why a fail was blocked, held or waived, in the deploy log's words.
     */
    private function why(GateDecision $decision): ?string
    {
        return match ($decision->outcome) {
            GateDecision::BLOCKED => match ($decision->reason) {
                GateDecision::REASON_EXPIRED_OVERRIDE => 'expired override: renew it or fix the failure it covered',
                GateDecision::REASON_REGRESSION => 'regression: this rule passed on the baseline',
                default => 'new app: no baseline, so every failure blocks',
            },
            GateDecision::HELD => 'held: it failed on the baseline too, so the ratchet lets it through',
            GateDecision::WAIVED => 'waived: no advisory data at deploy time, so it only warns here',
            default => null,
        };
    }
}
