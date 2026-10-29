<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\Policy\PolicyResolver;
use Waynestate\Proctor\Policy\ResolvedPolicy;

/**
 * Warns when the policy in use is old, so a stopped CREED refresh robot is
 * visible instead of silent.
 *
 * Warning level only: an old policy never fails a run. The check emits no
 * finding while the policy is fresh, and none for a bundled policy without
 * a refreshed_on date (the field is optional).
 */
final class PolicyAgeCheck implements CheckInterface
{
    /** The age, in days, at which the policy starts to warn. */
    public const MAX_AGE_DAYS = 60;

    public function __construct(
        private readonly ResolvedPolicy $resolved,
    ) {}

    public function id(): string
    {
        return 'policy-age';
    }

    public function run(CheckContext $context): array
    {
        $age = $this->resolved->ageDays($context->today);

        if ($age !== null && $age > self::MAX_AGE_DAYS) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf('policy is %d days old (refreshed %s)', $age, $this->resolved->refreshedOn),
            )];
        }

        if ($age === null && PolicyResolver::ORIGIN_BUNDLED !== $this->resolved->origin) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'policy has no refreshed_on date',
            )];
        }

        return [];
    }
}
