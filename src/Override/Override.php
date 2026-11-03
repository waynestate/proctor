<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Override;

use DateTimeImmutable;
use Waynestate\Proctor\Check\Finding;

/**
 * A single entry of an app's policy-overrides.json file.
 *
 * Value object: immutable, no behavior beyond the expiry comparison and
 * the scope match.
 */
final class Override
{
    /**
     * @param string            $check      the id of the check this override applies to
     * @param string            $reason     why the override exists
     * @param DateTimeImmutable $expires    the last day the override is active (midnight)
     * @param string            $approvedBy who approved the override
     * @param string|null       $advisory   the advisory id or CVE this override covers, or null for no scope
     * @param string|null       $package    the package name (a star may wildcard) this override covers, or null
     */
    public function __construct(
        public readonly string $check,
        public readonly string $reason,
        public readonly DateTimeImmutable $expires,
        public readonly string $approvedBy,
        public readonly ?string $advisory = null,
        public readonly ?string $package = null,
    ) {}

    /**
     * An override is expired when its expires date is before $today;
     * an override expiring on $today is still active.
     */
    public function isExpired(DateTimeImmutable $today): bool
    {
        return $this->expires < $today;
    }

    /**
     * The expires date as YYYY-MM-DD, for display.
     */
    public function displayDate(): string
    {
        return $this->expires->format('Y-m-d');
    }

    /**
     * Whether this override covers the finding.
     *
     * An override with no scope covers every fail of its check. A scoped
     * override covers the finding when at least one of its advisories
     * satisfies the whole scope: the advisory field compares
     * case-insensitively against the advisory id or CVE, the package field
     * compares case-insensitively against the audited package with a star
     * matching any run of characters, the way composer does. Both fields
     * present means both must match the same advisory. A scoped override
     * never covers a finding that carries no advisories (for example the
     * exit-code fail).
     */
    public function matches(Finding $finding): bool
    {
        if ($this->advisory === null && $this->package === null) {
            return true;
        }

        foreach ($finding->advisories as $advisory) {
            if ($this->advisory !== null && !$this->advisoryMatches($advisory)) {
                continue;
            }
            if ($this->package !== null && !$this->packageMatches($advisory)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $advisory
     */
    private function advisoryMatches(array $advisory): bool
    {
        $needle = (string) $this->advisory;
        $id = $advisory['id'] ?? null;
        if (is_string($id) && strcasecmp($needle, $id) === 0) {
            return true;
        }

        $cve = $advisory['cve'] ?? null;

        return is_string($cve) && strcasecmp($needle, $cve) === 0;
    }

    /**
     * @param array<string, mixed> $advisory
     */
    private function packageMatches(array $advisory): bool
    {
        $package = $advisory['package'] ?? null;
        if (!is_string($package)) {
            return false;
        }

        $pattern = (string) $this->package;
        if (!str_contains($pattern, '*')) {
            return strcasecmp($pattern, $package) === 0;
        }

        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';

        return preg_match($regex, $package) === 1;
    }
}
