<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use DateTimeImmutable;
use Waynestate\Proctor\Creed\State;
use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\Override\OverrideFile;

/**
 * Applies active overrides to the findings of a run.
 *
 * An active override (expires on or after --today) for check id X turns X's
 * matching fail findings into warning findings, with "(overridden: <reason>)"
 * appended to the message. An override with no scope matches every fail of
 * its check; a scoped one (an advisory and/or package field) matches only
 * the fails whose advisory data satisfies the scope.
 *
 * Exception (the stack's version policy: "EOL + 90 days: fail; no override"): an override
 * NEVER downgrades a fail whose state is eol_grace_expired. That exception
 * lives in apply(), in the isOverridable() guard below.
 *
 * An active override that matched no fail finding earns a warning of its own
 * (unusedWarnings()), so a stale approval is removed instead of rotting. For
 * a scoped override the warning names the scope.
 */
final class OverrideApplier
{
    /**
     * @param list<Override> $overrides all of the app's overrides
     */
    public function __construct(
        private readonly array $overrides,
        private readonly DateTimeImmutable $today,
    ) {}

    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    public function apply(array $findings): array
    {
        $result = [];
        foreach ($findings as $finding) {
            if ($finding->status === Finding::STATUS_FAIL) {
                $override = $this->activeOverrideFor($finding);
                if ($override !== null && $this->isOverridable($finding)) {
                    $result[] = new Finding(
                        $finding->checkId,
                        Finding::STATUS_WARNING,
                        $finding->message . sprintf(' (overridden: %s)', $override->reason),
                        $finding->path,
                        $finding->line,
                        $finding->state,
                        $finding->rule,
                    );
                    continue;
                }
            }
            $result[] = $finding;
        }

        return $result;
    }

    /**
     * One warning per active override that matched no fail finding.
     *
     * $findings must be the findings BEFORE apply(): a fail that an override
     * downgrades still counts as matched, while an active override that
     * matches no fail of its check is stale clutter. A scoped override
     * counts as matched only when some fail finding of its check satisfies
     * its scope, and the warning then names the scope. Expired overrides are
     * not reported here; the overrides check already fails on them.
     *
     * @param list<Finding> $findings the findings before apply()
     *
     * @return list<Finding>
     */
    public function unusedWarnings(array $findings): array
    {
        $warnings = [];
        foreach ($this->overrides as $override) {
            if ($override->isExpired($this->today)) {
                continue;
            }
            $matched = false;
            foreach ($findings as $finding) {
                if ($finding->checkId === $override->check && $finding->status === Finding::STATUS_FAIL && $override->matches($finding)) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }
            $warnings[] = new Finding(
                OverridesCheck::ID,
                Finding::STATUS_WARNING,
                sprintf(
                    'override for %s matched no failure; remove it if the problem is fixed',
                    $this->overrideLabel($override),
                ),
                OverrideFile::FILENAME,
                1,
                null,
                'unused',
            );
        }

        return $warnings;
    }

    /**
     * A fail is never overridable when its state is eol_grace_expired (the
     * EOL exception, per the stack's version policy: "EOL + 90 days: fail; no override") or when
     * it comes from the overrides check itself: an expired override must
     * always fail, even if another active override names the overrides check.
     */
    private function isOverridable(Finding $finding): bool
    {
        return $finding->state !== State::EolGraceExpired
            && $finding->checkId !== OverridesCheck::ID;
    }

    /**
     * The first active override for the finding's check that matches the
     * finding, or null.
     */
    private function activeOverrideFor(Finding $finding): ?Override
    {
        foreach ($this->overrides as $override) {
            if ($override->check === $finding->checkId && !$override->isExpired($this->today) && $override->matches($finding)) {
                return $override;
            }
        }

        return null;
    }

    /**
     * The override's name in the unused warning: the quoted check id, plus
     * its scope in parentheses when the override carries one.
     */
    private function overrideLabel(Override $override): string
    {
        $scope = [];
        if ($override->advisory !== null) {
            $scope[] = 'advisory ' . $override->advisory;
        }
        if ($override->package !== null) {
            $scope[] = 'package ' . $override->package;
        }
        if ($scope === []) {
            return sprintf('"%s"', $override->check);
        }

        return sprintf('"%s" (%s)', $override->check, implode(', ', $scope));
    }
}
