<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Policy;

/**
 * The four support states a component version can be in on a given date.
 *
 * These are the only strings a PolicySource::stateOf() may return.
 */
final class State
{
    /** The version is in its active (full support) phase. */
    public const ACTIVE = 'active';

    /** The version is past active support but still receiving security fixes. */
    public const SECURITY = 'security';

    /** The version is end-of-life, within the policy's eol_grace_days window. */
    public const EOL = 'eol';

    /** The version is end-of-life and the grace window has passed. */
    public const EOL_GRACE_EXPIRED = 'eol_grace_expired';

    /**
     * All state values, in support order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ACTIVE,
            self::SECURITY,
            self::EOL,
            self::EOL_GRACE_EXPIRED,
        ];
    }

    private function __construct() {}
}
