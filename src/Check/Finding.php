<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

/**
 * A single result of a check.
 *
 * Value object: immutable, no behavior beyond validation.
 */
final class Finding
{
    public const STATUS_PASS = 'pass';
    public const STATUS_INFO = 'info';
    public const STATUS_WARNING = 'warning';
    public const STATUS_FAIL = 'fail';

    /**
     * @param string      $checkId the id of the check that produced this finding
     * @param string      $status  one of the STATUS_* constants
     * @param string      $message human-readable description of the result
     * @param string|null $path    path relative to the checked app, no leading "./", or null if none
     * @param int         $line    line number in $path, 1-based
     * @param string|null $state   the policy state behind the finding, one of the State::
     *                             constants, or null when the finding has no state
     */
    public function __construct(
        public readonly string $checkId,
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $path = null,
        public readonly int $line = 1,
        public readonly ?string $state = null,
    ) {
        if (!in_array($status, [
            self::STATUS_PASS,
            self::STATUS_INFO,
            self::STATUS_WARNING,
            self::STATUS_FAIL,
        ], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid finding status "%s".', $status));
        }
        if ($path !== null && (str_starts_with($path, './') || str_starts_with($path, '/'))) {
            throw new \InvalidArgumentException(sprintf(
                'Finding path "%s" must be relative to the checked app without a leading "./" or "/".',
                $path,
            ));
        }
    }
}
