<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\ToolError;

/**
 * Warns when the app's declared MySQL or MariaDB image is below the minimum
 * the policy records for its locked Laravel line.
 *
 * The policy records, per Laravel line, the minimum MySQL and MariaDB the
 * line needs (requires.mysql and requires.mariadb). An app on Laravel 12
 * with mysql:5.6 passes mysql-eol's line lookup today but cannot run its
 * migrations. The image is found the way mysql-eol finds it, through
 * DatabaseImageNames: an official mysql or mariadb image on an `image:` line
 * in a root `docker-compose*.yml` or `compose*.yml`, and a
 * `SQUIRE_MYSQL_IMAGE=mysql:<ver>` line in `.env.squire.example` or
 * `.env.example`. The version the tag names is what is compared.
 *
 * Below the minimum: one warning per declaration
 * (`proctor/mysql-minimum/below`). At least one image graded and none below:
 * one pass naming the first graded image. Warns, never fails, and is not in
 * the deploy gate. No finding when composer.lock has no laravel/framework,
 * the line resolves to no policy line, the line records no minimums, or the
 * repository names no database image with a version.
 */
final class MysqlMinimumCheck implements CheckInterface
{
    public function id(): string
    {
        return 'mysql-minimum';
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

        $requires = $context->policy->requires('laravel', $line);
        if ($requires === null) {
            return [];
        }

        $graded = [];
        $findings = [];
        foreach (DatabaseImageNames::namedVersions($context->appPath) as [$path, $lineNo, $engine, $imageVersion]) {
            $minimum = $requires[$engine] ?? null;
            if (!is_string($minimum) || trim($minimum) === '') {
                continue;
            }
            $graded[] = [$path, $lineNo, $engine, $imageVersion, $minimum];
            if (version_compare($imageVersion, $minimum) < 0) {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_WARNING,
                    sprintf('%s %s is below the %s minimum Laravel %s needs (%s:%d)', $engine, $imageVersion, $minimum, $line, $path, $lineNo),
                    $path,
                    $lineNo,
                    null,
                    'below',
                );
            }
        }

        if ($graded === []) {
            return [];
        }

        if ($findings !== []) {
            return $findings;
        }

        [$path, $lineNo, $engine, $imageVersion, $minimum] = $graded[0];

        return [new Finding(
            $this->id(),
            Finding::STATUS_PASS,
            sprintf('%s %s meets the %s minimum Laravel %s needs', $engine, $imageVersion, $minimum, $line),
            $path,
            $lineNo,
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
}
