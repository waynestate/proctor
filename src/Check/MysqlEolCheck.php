<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\Creed\State;

/**
 * Grades every MySQL (and, when the policy carries one, MariaDB) version the
 * repository names against the policy's support dates.
 *
 * An app names its database in two places: an `image:` of `mysql:<ver>` or
 * `mariadb:<ver>` in a `docker-compose*.yml` or `compose*.yml` at the repo
 * root, and a `SQUIRE_MYSQL_IMAGE=mysql:<ver>` line in `.env.squire.example`
 * or `.env.example`; the names are read by DatabaseImageNames, which
 * mysql-minimum shares. Each name is graded on its own through the CREED
 * state table: still in active support is silent, past `active_until` warns
 * with the days left, and past `security_until` warns as eol or, beyond the
 * grace window, eol_grace_expired. Every finding warns, never fails, until
 * Citadel offers a supported MySQL (D-008); then eol fails again. Unlike
 * node-eol, a supported version earns no PASS line: a database that is fine
 * is not news.
 *
 * The major.minor is what the policy keys on, so `mysql:5.6.51` is judged as
 * 5.6 and `mysql:8.4.2` as 8.4. A bare `mysql:8` resolves to no line unless
 * the policy says otherwise, and the reader does not invent a mapping: it
 * warns with the pin advice, naming the highest line the policy lists in
 * that major, and stays silent only when the policy lists no line in that
 * major. A registry prefix, a `library/` segment and an `@sha256:` digest
 * are all tolerated; another org's image named mysql (someorg/mysql) carries
 * its own versions and is not graded.
 *
 * MariaDB images are graded only when the policy has a `mariadb` component;
 * otherwise they are ignored silently. Silent too when the policy has no
 * `mysql` or `mariadb` component, or the repository names no version.
 *
 * The proctor checks the database the app actually runs, not the one the
 * architecture slide promises.
 */
final class MysqlEolCheck implements CheckInterface
{
    public function id(): string
    {
        return 'mysql-eol';
    }

    public function run(CheckContext $context): array
    {
        if (!$context->policy->hasComponent('mysql') && !$context->policy->hasComponent('mariadb')) {
            return [];
        }

        $findings = [];
        foreach (DatabaseImageNames::namedVersions($context->appPath) as [$path, $line, $engine, $version]) {
            // Each engine is graded only against its own component; a policy
            // without mariadb ignores mariadb images silently.
            if (!$context->policy->hasComponent($engine)) {
                continue;
            }
            $finding = $this->grade($path, $line, $engine, $version, $context);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * The finding for one named version, or null when the version is still in
     * active support (silent) or the reader cannot grade it.
     */
    private function grade(string $path, int $line, string $engine, string $version, CheckContext $context): ?Finding
    {
        // A bare major (mysql:8) names a family, not a line: the policy keys
        // on major.minor, so the reader invents no mapping and grades
        // nothing. When the policy lists lines in that major, the tag is
        // floating and gets a warning asking for a major.minor pin.
        if (!str_contains($version, '.') && $context->policy->resolveVersion($engine, $version) === null) {
            return $this->floatingMajorWarning($path, $line, $engine, $version, $context);
        }

        $state = $context->policy->tryStateOf($engine, $version, $context->today);
        if ($state === null || $state === State::Active) {
            return null;
        }

        // Every graded state warns, never fails, until Citadel offers a
        // supported MySQL (D-008); eol fails again once it does (D-053).
        $status = Finding::STATUS_WARNING;
        $subject = sprintf('%s %s (%s)', $engine, $version, $path);

        return new Finding(
            $this->id(),
            $status,
            $subject . ' ' . $this->describe($engine, $version, $state, $context),
            $path,
            $line,
            $state,
        );
    }

    /**
     * The warning for a bare major tag the policy cannot resolve, or null
     * when the policy lists no line in that major (silent, as before).
     *
     * The finding carries no state: the reader invents no mapping from the
     * family to a line, so there is nothing to grade.
     */
    private function floatingMajorWarning(string $path, int $line, string $engine, string $version, CheckContext $context): ?Finding
    {
        $prefix = $version . '.';
        $lines = array_values(array_filter(
            $context->policy->versions($engine),
            static fn(string $listed): bool => str_starts_with($listed, $prefix),
        ));
        if ($lines === []) {
            return null;
        }
        $highest = $lines[0];
        foreach ($lines as $listed) {
            if (version_compare($listed, $highest) > 0) {
                $highest = $listed;
            }
        }

        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf('%s %s (%s) is a floating tag; pin a major.minor such as %s so the check can grade it', $engine, $version, $path, $highest),
            $path,
            $line,
            null,
        );
    }

    /**
     * "is in security-only support until DATE (N days left)", "is eol
     * (security support ended DATE)", or "is eol_grace_expired (security
     * support ended DATE)". The active branch is kept for parity with the
     * wording table even though a supported version never reaches it.
     */
    private function describe(string $engine, string $version, State $state, CheckContext $context): string
    {
        $line = $context->policy->resolveVersion($engine, $version);
        if ($state === State::Active) {
            $until = $line === null ? null : $context->policy->activeUntil($engine, $line);

            return $until === null ? 'is active' : 'is active until ' . $until->format('Y-m-d');
        }

        $until = $line === null ? null : $context->policy->securityUntil($engine, $line);
        if ($state === State::Security) {
            if ($until === null) {
                return 'is in security-only support';
            }
            $days = (int) $context->today->diff($until)->format('%a');
            $left = match (true) {
                $days === 0 => 'ends today',
                $days === 1 => '1 day left',
                default => sprintf('%d days left', $days),
            };

            return sprintf('is in security-only support until %s (%s)', $until->format('Y-m-d'), $left);
        }

        if ($until === null) {
            $reason = $context->policy->belowOldestLineReason($engine, $version);

            return sprintf('is %s', $state->value) . ($reason === null ? '' : ' (' . $reason . ')');
        }

        return sprintf('is %s (security support ended %s)', $state->value, $until->format('Y-m-d'));
    }
}
