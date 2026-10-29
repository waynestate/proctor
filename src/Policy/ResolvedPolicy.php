<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Policy;

use DateTimeImmutable;
use Waynestate\Proctor\Creed\Policy;

/**
 * The CREED policy a run checks against, and where it came from.
 *
 * Value object: immutable. $origin is one of the PolicyResolver::ORIGIN_*
 * constants.
 */
final class ResolvedPolicy
{
    public function __construct(
        /** The policy to check against. */
        public readonly Policy $policy,
        /** Where it came from: option, env, vendor or bundled. */
        public readonly string $origin,
        /** The file it was read from. */
        public readonly string $path,
        /** The policy's top-level refreshed_on date (ISO), or null when absent. */
        public readonly ?string $refreshedOn,
    ) {}

    /**
     * Whole days since the policy was refreshed.
     *
     * Zero on the refresh day, positive after it, negative when the date is
     * in the future. Null when the policy carries no refreshed_on.
     */
    public function ageDays(DateTimeImmutable $today): ?int
    {
        if ($this->refreshedOn === null) {
            return null;
        }
        $refreshed = new DateTimeImmutable($this->refreshedOn);
        $interval = $refreshed->diff(new DateTimeImmutable($today->format('Y-m-d')));

        return $interval->invert === 1 ? -$interval->days : $interval->days;
    }
}
