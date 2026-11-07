<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Gate;

use Waynestate\Proctor\Check\Finding;

/**
 * What the gate made of one finding.
 *
 * Value object. A fail becomes blocked (with a reason), held or waived; any
 * other status passes through as pass, info or warning.
 */
final class GateDecision
{
    public const BLOCKED = 'blocked';

    public const HELD = 'held';

    public const WAIVED = 'waived';

    public const PASS = 'pass';

    public const INFO = 'info';

    public const WARNING = 'warning';

    /** No baseline: a first deploy must pass every gate rule. */
    public const REASON_NEW_APP = 'new-app';

    /** The rule passed on the baseline and fails now. */
    public const REASON_REGRESSION = 'regression';

    /** An expired override blocks whatever the baseline says. */
    public const REASON_EXPIRED_OVERRIDE = 'expired-override';

    /**
     * @param string      $outcome one of the outcome constants
     * @param string|null $reason  one of the REASON_* constants when the outcome is BLOCKED, null otherwise
     */
    public function __construct(
        public readonly Finding $finding,
        public readonly string $outcome,
        public readonly ?string $reason = null,
    ) {}
}
