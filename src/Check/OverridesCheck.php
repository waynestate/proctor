<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\Override\OverrideFile;

/**
 * Reports expired overrides and warns about the ones about to expire.
 *
 * An expired entry fails. An active entry that expires within
 * EXPIRY_WARNING_DAYS of --today (the expiry day counts) warns, so an
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
            // left and warn while the approval is still inside the window.
            $daysLeft = (int) $context->today->diff($override->expires)->days;
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
