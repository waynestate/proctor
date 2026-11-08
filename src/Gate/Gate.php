<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Gate;

use Closure;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\OverridesCheck;

/**
 * The deploy gate's decision rule (ADR-0006, CREED's deploy-gate chapter).
 *
 * It ratchets: a deploy that does not make things worse always passes. After
 * overrides are applied, a fail finding is
 *
 * - blocked when it comes from the overrides check (an expired override
 *   always blocks);
 * - blocked when there is no baseline (a first deploy fails on any rule);
 * - held when the baseline failed the same rule id (an existing violation:
 *   owners of an EOL app can still ship the fix);
 * - blocked when the baseline passed that rule (a regression);
 * - waived when it only says the advisory data was unavailable
 *   (composer-audit's skipped and could-not-run rules): at deploy time a
 *   missing advisory database warns, it never stops a deploy.
 * - staged when it would be blocked but CREED's `gate.stages` put its check
 *   at `report` or `warn` today (ADR-0006 item 4); the deploy goes ahead and
 *   the verdict says so.
 *
 * The match is by rule id, so a rule already failing on the baseline is held
 * even if it got worse within the rule; `proctor check` in CI still fails it.
 * The unused-override warning is left to `proctor check`, because the gate
 * runs only some checks and would call an override for a skipped check unused.
 */
final class Gate
{
    public const EXIT_GO = 0;

    public const EXIT_NO_GO = 1;

    public const EXIT_TOOL_ERROR = 2;

    /** Fails that mean "no advisory data here", which only warn at deploy time. */
    public const WAIVED_RULES = [
        'proctor/composer-audit/skipped',
        'proctor/composer-audit/could-not-run',
    ];

    /** Findings the gate leaves out, because they only make sense for a full `proctor check`. */
    public const DROPPED_RULES = [
        'proctor/overrides/unused',
    ];

    private function __construct() {}

    /**
     * @param list<Finding>          $findings the gate checks' findings, overrides applied, in check order
     * @param Baseline|null          $baseline the running deployment's failures, or null for a first deploy
     * @param Closure(string): string|null $stageOf given a check id, the CREED gate stage today (`report`, `warn` or `block`); null means every check blocks
     *
     * @return list<GateDecision> one per finding, in the same order, without the dropped ones
     */
    public static function decide(array $findings, ?Baseline $baseline, ?Closure $stageOf = null): array
    {
        $decisions = [];
        foreach ($findings as $finding) {
            if (in_array($finding->ruleId, self::DROPPED_RULES, true)) {
                continue;
            }
            $decision = self::decideOne($finding, $baseline);
            if (GateDecision::BLOCKED === $decision->outcome && null !== $stageOf) {
                $stage = $stageOf($finding->checkId);
                if ('block' !== $stage) {
                    $decision = new GateDecision($finding, GateDecision::STAGED, $decision->reason, $stage);
                }
            }
            $decisions[] = $decision;
        }

        return $decisions;
    }

    private static function decideOne(Finding $finding, ?Baseline $baseline): GateDecision
    {
        if (Finding::STATUS_FAIL !== $finding->status) {
            return new GateDecision($finding, match ($finding->status) {
                Finding::STATUS_PASS => GateDecision::PASS,
                Finding::STATUS_INFO => GateDecision::INFO,
                default => GateDecision::WARNING,
            });
        }
        if (in_array($finding->ruleId, self::WAIVED_RULES, true)) {
            return new GateDecision($finding, GateDecision::WAIVED);
        }
        if (OverridesCheck::ID === $finding->checkId) {
            return new GateDecision($finding, GateDecision::BLOCKED, GateDecision::REASON_EXPIRED_OVERRIDE);
        }
        if (null === $baseline) {
            return new GateDecision($finding, GateDecision::BLOCKED, GateDecision::REASON_NEW_APP);
        }
        if ($baseline->failed($finding->ruleId)) {
            return new GateDecision($finding, GateDecision::HELD);
        }

        return new GateDecision($finding, GateDecision::BLOCKED, GateDecision::REASON_REGRESSION);
    }
}
