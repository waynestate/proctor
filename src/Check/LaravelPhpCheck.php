<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Waynestate\Proctor\ToolError;

/**
 * Checks that the require.php constraint of composer.json stays inside the
 * PHP range the policy records for the locked laravel/framework line.
 *
 * A constraint that allows a PHP version the locked Laravel major cannot run
 * on is a fail: the app would not boot on PHP it declares. The range comes
 * from the policy's requires block; an app without laravel/framework in
 * composer.lock, a line without a recorded range, or an app without a
 * require.php constraint earns no finding (php-floor owns the
 * missing-constraint verdict).
 */
final class LaravelPhpCheck implements CheckInterface
{
    public function id(): string
    {
        return 'laravel-php';
    }

    public function run(CheckContext $context): array
    {
        $version = $this->laravelVersion($context->appPath);
        if ($version === null) {
            return [];
        }

        $line = $context->policy->resolveVersion('laravel', $version);
        if ($line === null) {
            return [];
        }

        $range = $context->policy->phpRange('laravel', $line);
        if ($range === null) {
            return [];
        }

        $constraint = $this->phpConstraint($context->appPath);
        if ($constraint === null) {
            return [];
        }

        $allowed = $this->parseConstraint($constraint);
        $supported = (new VersionParser())->parseConstraints(
            sprintf('>=%s <%s', $range['min'], $this->upperBound($range['max'])),
        );

        $inside = Intervals::isSubsetOf($allowed, $supported);

        return [new Finding(
            $this->id(),
            $inside ? Finding::STATUS_PASS : Finding::STATUS_FAIL,
            sprintf(
                'php constraint "%s" %s the %s to %s range of laravel %s',
                $constraint,
                $inside ? 'fits' : 'allows PHP outside',
                $range['min'],
                $range['max'],
                $line,
            ),
            'composer.json',
        )];
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
     * The app's require.php constraint, or null when composer.json is absent
     * or declares none. php-floor reports the missing constraint; this check
     * only judges constraints that are there.
     *
     * @throws ToolError if composer.json is present but not valid JSON
     */
    private function phpConstraint(string $appPath): ?string
    {
        $file = $appPath . '/composer.json';
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            throw new ToolError(sprintf('composer.json in "%s" is not valid JSON.', $appPath));
        }

        $constraint = $data['require']['php'] ?? null;

        return is_string($constraint) && trim($constraint) !== '' ? $constraint : null;
    }

    /**
     * The constraint parsed into a semver constraint object.
     *
     * @throws ToolError if the constraint string is not a valid version constraint
     */
    private function parseConstraint(string $constraint): ConstraintInterface
    {
        try {
            return (new VersionParser())->parseConstraints($constraint);
        } catch (\UnexpectedValueException $e) {
            throw new ToolError(sprintf(
                'php constraint "%s" is not a valid version constraint: %s',
                $constraint,
                $e->getMessage(),
            ));
        }
    }

    /**
     * The first minor after the range's max, the exclusive upper bound of
     * the supported interval: 8.5 -> 8.6, 8 -> 9.
     */
    private function upperBound(string $max): string
    {
        $parts = explode('.', $max);
        $last = (int) array_pop($parts);
        $parts[] = (string) ($last + 1);

        return implode('.', $parts);
    }
}
