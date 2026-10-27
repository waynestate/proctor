<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

/**
 * A check measures one aspect of the app against the policy.
 */
interface CheckInterface
{
    /**
     * Stable identifier of the check, e.g. "php-floor".
     */
    public function id(): string;

    /**
     * Runs the check against the app described by $context.
     *
     * @return list<Finding>
     */
    public function run(CheckContext $context): array;
}
