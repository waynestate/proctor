<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use InvalidArgumentException;
use Waynestate\Proctor\Override\OverrideFile;
use Waynestate\Proctor\ToolError;

/**
 * Reports expired and over-long overrides and warns about the ones about to
 * expire.
 *
 * An expired entry fails. An entry that runs past the policy's maximum
 * duration (rules.override_max_days, 90 days when the key is absent) fails
 * too and is not applied: an approval is a dated exception, not a standing
 * one (CREED allows no permanent override). An active entry that expires
 * within EXPIRY_WARNING_DAYS of --today (the expiry day counts) warns, so an
 * approval that is about to lapse is visible instead of silent. A warning
 * never changes the exit code.
 *
 * When the app has no policy-overrides.json, the check produces no findings
 * at all: it passes and prints nothing. Every override, active or expired,
 * is printed as an OVERRIDE line by the CLI in addition to these findings.
 */
final class OverridesCheck implements CheckInterface
{
    public const ID = 'overrides';

    /** An active override this many days from its expiry gets a warning. */
    private const EXPIRY_WARNING_DAYS = 14;

    public function id(): string
    {
        return self::ID;
    }

    public function run(CheckContext $context): array
    {
        try {
            $maxDays = $context->policy->overrideMaxDays();
        } catch (InvalidArgumentException $error) {
            throw new ToolError($error->getMessage());
        }

        $findings = [];
        foreach ($context->overrides as $override) {
            if ($override->isExpired($context->today)) {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_FAIL,
                    sprintf(
                        'override for "%s" expired on %s',
                        $override->check,
                        $override->displayDate(),
                    ),
                    OverrideFile::FILENAME,
                );
                continue;
            }

            // Not expired, so the expiry is today or later: count the days
            // left. An entry that runs past the policy's maximum duration
            // fails and is not applied; it is a standing override in all but
            // name, and CREED allows no permanent override.
            $daysLeft = (int) $context->today->diff($override->expires)->days;
            if ($daysLeft > $maxDays) {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_FAIL,
                    sprintf(
                        'override for "%s" expires on %s, more than %d days after %s (policy rules.override_max_days)',
                        $override->check,
                        $override->displayDate(),
                        $maxDays,
                        $context->today->format('Y-m-d'),
                    ),
                    OverrideFile::FILENAME,
                    1,
                    null,
                    'too-long',
                );
                continue;
            }

            if ($daysLeft <= self::EXPIRY_WARNING_DAYS) {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_WARNING,
                    sprintf(
                        'override for "%s" expires on %s (%d %s left)',
                        $override->check,
                        $override->displayDate(),
                        $daysLeft,
                        $daysLeft === 1 ? 'day' : 'days',
                    ),
                    OverrideFile::FILENAME,
                    1,
                    null,
                    'expiring',
                );
            }
        }

        return $findings;
    }
}
