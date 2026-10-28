<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Override;

use DateTimeImmutable;

/**
 * A single entry of an app's policy-overrides.json file.
 *
 * Value object: immutable, no behavior beyond the expiry comparison.
 */
final class Override
{
    /**
     * @param string            $check      the id of the check this override applies to
     * @param string            $reason     why the override exists
     * @param DateTimeImmutable $expires    the last day the override is active (midnight)
     * @param string            $approvedBy who approved the override
     */
    public function __construct(
        public readonly string $check,
        public readonly string $reason,
        public readonly DateTimeImmutable $expires,
        public readonly string $approvedBy,
    ) {}

    /**
     * An override is expired when its expires date is before $today;
     * an override expiring on $today is still active.
     */
    public function isExpired(DateTimeImmutable $today): bool
    {
        return $this->expires < $today;
    }

    /**
     * The expires date as YYYY-MM-DD, for display.
     */
    public function displayDate(): string
    {
        return $this->expires->format('Y-m-d');
    }
}
