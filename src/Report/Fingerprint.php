<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Report;

/**
 * The stable identity of a finding in the GitLab Code Quality format.
 */
final class Fingerprint
{
    private function __construct() {}

    /**
     * Lower-case hex SHA-256 of rule_id + "\n" + path ("" when null)
     * + "\n" + message.
     */
    public static function of(string $ruleId, ?string $path, string $message): string
    {
        return hash('sha256', $ruleId . "\n" . ($path ?? '') . "\n" . $message);
    }
}
