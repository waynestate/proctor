<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\Creed\State;
use Waynestate\Proctor\ToolError;

/**
 * Classifies the locked laravel/framework version against the policy and
 * reports its state.
 *
 * The proctor keeps the hall on a framework that still gets security fixes:
 * active -> pass, security -> warning, eol and eol_grace_expired -> fail.
 * An app without laravel/framework in composer.lock earns no finding, and so
 * does a version the policy cannot resolve to a line: the reader does not
 * guess.
 */
final class LaravelEolCheck implements CheckInterface
{
    public function id(): string
    {
        return 'laravel-eol';
    }

    public function run(CheckContext $context): array
    {
        $version = $this->laravelVersion($context->appPath);
        if ($version === null) {
            return [];
        }

        $state = $context->policy->tryStateOf('laravel', $version, $context->today);
        if ($state === null) {
            return [];
        }

        // The date fields live on the line, and the reader looks lines up by
        // their exact key, so resolve the version to its line before asking
        // for dates. A version outside every listed line carries no dates.
        $line = $context->policy->resolveVersion('laravel', $version);

        return match ($state) {
            State::Active => [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                $this->activeMessage($version, $line, $context),
                'composer.lock',
                1,
                $state,
            )],
            State::Security => [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                $this->securityOnlyMessage($version, $line, $context),
                'composer.lock',
                1,
                $state,
            )],
            default => [new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                $this->eolMessage($version, $state, $line, $context),
                'composer.lock',
                1,
                $state,
            )],
        };
    }

    /**
     * The laravel/framework version from composer.lock, with a leading v
     * stripped, or null when the lock is missing, the package entry has no
     * usable version, or the version is not plain numeric (a dev alias
     * resolves to no policy line).
     */
    private function laravelVersion(string $appPath): ?string
    {
        $lock = $this->readComposerLock($appPath);
        if ($lock === null) {
            return null;
        }

        foreach (['packages', 'packages-dev'] as $section) {
            if (!isset($lock[$section]) || !is_array($lock[$section])) {
                continue;
            }
            foreach ($lock[$section] as $package) {
                if (!is_array($package)) {
                    continue;
                }
                if (($package['name'] ?? null) !== 'laravel/framework') {
                    continue;
                }
                $version = $package['version'] ?? null;
                if (!is_string($version) || trim($version) === '') {
                    return null;
                }
                $stripped = preg_replace('/^v/', '', $version);
                if (!is_string($stripped)) {
                    return null;
                }

                return preg_match('/^\d+(\.\d+)*$/', $stripped) === 1 ? $stripped : null;
            }
        }

        return null;
    }

    /**
     * Reads and decodes the app's composer.lock, or null when it is absent.
     * A present lock that is not valid JSON is a tool error, the same as the
     * other lock readers.
     *
     * @return array<string, mixed>|null
     *
     * @throws ToolError
     */
    private function readComposerLock(string $appPath): ?array
    {
        $file = $appPath . '/composer.lock';
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            throw new ToolError(sprintf('composer.lock in "%s" is not valid JSON.', $appPath));
        }

        return $data;
    }

    /**
     * The active-state message with the end date when the line has one; a
     * current line (no active_until) or a version ahead of every line simply
     * reads "is active".
     */
    private function activeMessage(string $version, ?string $line, CheckContext $context): string
    {
        $until = $line === null ? null : $context->policy->activeUntil('laravel', $line);
        if ($until === null) {
            return sprintf('laravel %s is active', $version);
        }

        return sprintf('laravel %s is active until %s', $version, $until->format('Y-m-d'));
    }

    /**
     * The security-only warning with the end date and the days left, the
     * same midnight-to-midnight comparison php-floor uses for its warning.
     */
    private function securityOnlyMessage(string $version, ?string $line, CheckContext $context): string
    {
        $until = $line === null ? null : $context->policy->securityUntil('laravel', $line);
        if ($until === null) {
            return sprintf('laravel %s is in security-only support', $version);
        }

        $days = (int) $context->today->diff($until)->format('%a');
        $left = match (true) {
            $days === 0 => 'ends today',
            $days === 1 => '1 day left',
            default => sprintf('%d days left', $days),
        };

        return sprintf(
            'laravel %s is in security-only support until %s (%s)',
            $version,
            $until->format('Y-m-d'),
            $left,
        );
    }

    /**
     * The eol / eol_grace_expired message with the security end date when
     * the line has one; a version older than the oldest listed line carries
     * no date.
     */
    private function eolMessage(string $version, State $state, ?string $line, CheckContext $context): string
    {
        $until = $line === null ? null : $context->policy->securityUntil('laravel', $line);
        if ($until === null) {
            return sprintf('laravel %s is %s', $version, $state->value);
        }

        return sprintf(
            'laravel %s is %s (security support ended %s)',
            $version,
            $state->value,
            $until->format('Y-m-d'),
        );
    }
}
