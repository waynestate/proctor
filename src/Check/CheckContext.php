<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use DateTimeImmutable;
use Waynestate\Proctor\Policy\PolicySource;

/**
 * Everything a check needs to know about the run: the app under test, the
 * policy, and the date every state decision is made on.
 */
final class CheckContext
{
    public function __construct(
        /** The application path (--path). */
        public readonly string $appPath,
        public readonly PolicySource $policy,
        /** The date for every state decision (--today, or the current date). */
        public readonly DateTimeImmutable $today,
    ) {
    }
}
