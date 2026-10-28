<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use DateTimeImmutable;
use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\Policy\State;

/**
 * Applies active overrides to the findings of a run.
 *
 * An active override (expires on or after --today) for check id X turns X's
 * fail findings into warning findings, with "(overridden: <reason>)"
 * appended to the message.
 *
 * Exception (the stack's version policy: "EOL + 90 days: fail; no override"): an override
 * NEVER downgrades a fail whose state is eol_grace_expired. That exception
 * lives in apply(), in the isOverridable() guard below.
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
                $override = $this->activeOverrideFor($finding->checkId);
                if ($override !== null && $this->isOverridable($finding)) {
                    $result[] = new Finding(
                        $finding->checkId,
                        Finding::STATUS_WARNING,
                        $finding->message . sprintf(' (overridden: %s)', $override->reason),
                        $finding->path,
                        $finding->line,
                        $finding->state,
                    );
                    continue;
                }
            }
            $result[] = $finding;
        }

        return $result;
    }

    /**
     * A fail is never overridable when its state is eol_grace_expired (the
     * EOL exception, per the stack's version policy: "EOL + 90 days: fail; no override") or when
     * it comes from the overrides check itself: an expired override must
     * always fail, even if another active override names the overrides check.
     */
    private function isOverridable(Finding $finding): bool
    {
        return $finding->state !== State::EOL_GRACE_EXPIRED
            && $finding->checkId !== OverridesCheck::ID;
    }

    /**
     * The first active override for the check id, or null.
     */
    private function activeOverrideFor(string $checkId): ?Override
    {
        foreach ($this->overrides as $override) {
            if ($override->check === $checkId && !$override->isExpired($this->today)) {
                return $override;
            }
        }

        return null;
    }
}
