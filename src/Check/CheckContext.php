<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use DateTimeImmutable;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Override\Override;

/**
 * Everything a check needs to know about the run: the app under test, the
 * policy, the date every state decision is made on, and the app's overrides.
 */
final class CheckContext
{
    /**
     * @param list<Override> $overrides  the app's policy-overrides.json entries,
     *                                   empty when the file is absent
     * @param int            $timeout    maximum seconds for an external process
     *                                   (composer audit) before it is killed
     * @param string|null    $phpRuntime the PHP version that will serve the app
     *                                   (--php-runtime), or null when not given
     */
    public function __construct(
        /** The application path (--path). */
        public readonly string $appPath,
        public readonly Policy $policy,
        /** The date for every state decision (--today, or the current date). */
        public readonly DateTimeImmutable $today,
        public readonly array $overrides = [],
        public readonly int $timeout = 300,
        /** The PHP version that will serve the app (--php-runtime), or null. */
        public readonly ?string $phpRuntime = null,
    ) {}
}
