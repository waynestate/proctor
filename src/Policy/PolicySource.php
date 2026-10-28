<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Policy;

use DateTimeImmutable;

/**
 * A source of component version states and policy rules.
 */
interface PolicySource
{
    /**
     * Returns the state of $version of $component on $date.
     *
     * The result is one of the State:: constants:
     * - State::ACTIVE if active_until is set and $date <= active_until;
     * - State::SECURITY if $date <= security_until;
     * - State::EOL if $date <= security_until + eol_grace_days;
     * - State::EOL_GRACE_EXPIRED otherwise.
     *
     * @param string $component a component name, e.g. "php"
     * @param string $version   a version key as used in the policy, e.g. "8.4"
     *
     * @throws PolicyException if the component or version is not in the policy
     */
    public function stateOf(string $component, string $version, DateTimeImmutable $date): string;

    /**
     * Returns the policy rules (the "rules" object of the policy file).
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Returns the latest patch recorded for $version of $component, or null
     * when the component, the version line or the latest_patch key is not in
     * the policy.
     *
     * @param string $component a component name, e.g. "laravel"
     * @param string $version   a version line as used in the policy, e.g. "12.1"
     *
     * @return array{latest_patch: string, latest_patch_checked: string|null}|null
     */
    public function latestPatchOf(string $component, string $version): ?array;

    /**
     * Returns the security_until date of $version of $component, parsed at
     * midnight, or null when the component, the version line or the
     * security_until key is not in the policy.
     *
     * @param string $component a component name, e.g. "php"
     * @param string $version   a version line as used in the policy, e.g. "8.3"
     */
    public function securityUntilOf(string $component, string $version): ?DateTimeImmutable;
}
