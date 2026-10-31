<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\Creed\State;

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
     * @param string                     $checkId    the id of the check that produced this finding
     * @param string                     $status     one of the STATUS_* constants
     * @param string                     $message    human-readable description of the result
     * @param string|null                $path       path relative to the checked app, no leading "./", or null if none
     * @param int                        $line       line number in $path, 1-based
     * @param State|null                 $state      the policy state behind the finding, or null when the
     *                                               finding has no state
     * @param string|null                $rule       the rule within the check, or null for the check's default
     *                                               finding; the stable rule id is proctor/<checkId>/<rule>
     * @param list<array<string, mixed>> $advisories machine-readable advisory data (id, cve, title, link and
     *                                               severity per entry), empty when the finding carries none
     */
    public function __construct(
        public readonly string $checkId,
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $path = null,
        public readonly int $line = 1,
        public readonly ?State $state = null,
        public readonly ?string $rule = null,
        public readonly array $advisories = [],
    ) {
        if (!preg_match('/^[a-z0-9-]+$/', $checkId)) {
            throw new \InvalidArgumentException(sprintf('Invalid finding check id "%s".', $checkId));
        }
        if ($rule !== null && !preg_match('/^[a-z0-9-]+$/', $rule)) {
            throw new \InvalidArgumentException(sprintf('Invalid finding rule "%s".', $rule));
        }
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
        $this->ruleId = 'proctor/' . $checkId . ($rule !== null ? '/' . $rule : '');
    }

    /**
     * The stable, namespaced rule id: proctor/<checkId> for the check's
     * default finding, proctor/<checkId>/<rule> for a named rule.
     */
    public readonly string $ruleId;
}
