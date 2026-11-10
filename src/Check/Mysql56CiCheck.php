<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Warns when an app has migrations but its GitLab pipeline never runs them
 * against MySQL 5.6, the version Citadel's shared server runs.
 *
 * CREED databases.md says an app MUST run its migrations in CI against a real
 * MySQL 5.6, because code that passes on a newer local database (or on
 * SQLite in tests) can reject on the shared 5.6 server at deploy time. The
 * `mysql56` check scans the migration and app code for the features 5.6
 * rejects; this check covers the wiring: does the pipeline actually run the
 * migrations on a 5.6 server at all? Preflight's `mysql56-migrate` component
 * does.
 *
 * Like every PROCTOR check that reads CI content, it warns and never fails:
 * see the docblock of PreflightPinnedCheck. One warning, `no-job`, when the
 * app has migrations and none of these holds:
 *
 * - an include: entry whose component: path contains
 *   /preflight/mysql56-migrate@ (Preflight's mysql56-migrate component,
 *   pinned to a release tag);
 * - a top-level job named mysql56-migrate (the component's default job name,
 *   or the app's own job that runs the migrations on 5.6).
 *
 * No finding when the app has no database/migrations/ directory (or it holds
 * no *.php file), when there is no .gitlab-ci.yml, or when the file does not
 * parse: ci-pipelines and preflight-gates report both. With nothing to warn
 * about the check stays silent, rather than passing: an app with no
 * migrations has nothing to run. The file is parsed as YAML, never grepped,
 * and nothing here reads the environment: the pipeline file is the only
 * witness.
 */
final class Mysql56CiCheck implements CheckInterface
{
    /** The GitLab pipeline file, relative to the app root. */
    private const GITLAB_CI = '.gitlab-ci.yml';

    /** The migrations directory, relative to the app root. */
    private const MIGRATIONS_DIR = 'database/migrations';

    /**
     * The marker of an include of Preflight's mysql56-migrate component: the
     * component path up to and including the @ that pins a release.
     */
    private const COMPONENT_MARKER = '/preflight/mysql56-migrate@';

    /** The job name the component defines by default. */
    private const JOB_NAME = 'mysql56-migrate';

    public function id(): string
    {
        return 'mysql56-ci';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');

        if (!$this->hasMigrations($appPath)) {
            return [];
        }

        $absolutePath = $appPath . '/' . self::GITLAB_CI;
        if (!is_file($absolutePath)) {
            return [];
        }

        $data = $this->parseYaml($absolutePath);
        if ($data === null) {
            return [];
        }

        if ($this->runsMigrationsAgainst56($data)) {
            return [];
        }

        return [new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            "migrations in database/migrations/ never run against MySQL 5.6 in CI: include Preflight's mysql56-migrate component (CREED databases.md)",
            self::GITLAB_CI,
            1,
            null,
            'no-job',
        )];
    }

    /**
     * Whether the app has migrations: a database/migrations/ directory that
     * holds at least one *.php file.
     */
    private function hasMigrations(string $appPath): bool
    {
        $dir = $appPath . '/' . self::MIGRATIONS_DIR;
        if (!is_dir($dir)) {
            return false;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && str_ends_with($entry, '.php') && is_file($dir . '/' . $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the pipeline runs the migrations against MySQL 5.6: a
     * top-level job named mysql56-migrate, or an include of Preflight's
     * mysql56-migrate component.
     *
     * @param array<mixed, mixed> $data
     */
    private function runsMigrationsAgainst56(array $data): bool
    {
        if (array_key_exists(self::JOB_NAME, $data)) {
            return true;
        }

        return $this->includesMysql56Migrate($data['include'] ?? null);
    }

    /**
     * Whether an include: entry carries Preflight's mysql56-migrate
     * component: a map whose component: path contains the marker, in the
     * list form or as the single-entry form.
     */
    private function includesMysql56Migrate(mixed $include): bool
    {
        if (!is_array($include)) {
            // A single string include is a file, never a component.
            return false;
        }
        // include: as one map rather than a list of entries.
        if (isset($include['component'])) {
            $include = [$include];
        }

        foreach ($include as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $component = $entry['component'] ?? null;
            if (is_string($component) && str_contains($component, self::COMPONENT_MARKER)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parses the file as a YAML map, or null when it cannot be read, does
     * not parse, or is not a map.
     *
     * @return array<mixed, mixed>|null
     */
    private function parseYaml(string $absolutePath): ?array
    {
        $raw = file_get_contents($absolutePath);
        if ($raw === false) {
            return null;
        }

        try {
            $data = Yaml::parse($raw);
        } catch (ParseException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
