<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Warns when a committed env, deploy or CI file points APP_ENV away from
 * production.
 *
 * D-133 keeps the public/private border honest: a deployed environment runs
 * as production, so a committed template, deploy script or CI pipeline that
 * pins another environment ships the wrong border with the release. PROCTOR
 * never reads .env itself (a committed .env is another check's finding); it
 * looks at the production templates (.env.production and
 * .env.squire.example; .env.example is the local-development template, where
 * another environment is expected), every file under deploy/ or deployments/,
 * and the CI pipelines, where the environment would ship with the release.
 * When the run carries an EnvFileFilter (ADVISOR's in-process run), an env
 * template it refuses is not opened.
 *
 * Emits proctor/app-env on pass and
 * proctor/app-env/not-production on warning.
 *
 * One warning per file, on the line of its first flagged value. A value that
 * starts with $ (a variable reference) is not flagged, because the repository
 * cannot know what the host will fill in. The proctor marks the paper, it
 * does not rewrite it.
 */
final class AppEnvCheck implements CheckInterface
{
    /** Committed production env templates at the app root that may carry the switch. */
    private const ENV_FILES = ['.env.production', '.env.squire.example'];

    /** Top-level directories whose files may set the switch. */
    private const DEPLOY_DIRS = ['deploy', 'deployments'];

    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

    /** The GitLab pipeline file at the app root. */
    private const GITLAB_CI = '.gitlab-ci.yml';

    /** The directory holding GitHub workflow files. */
    private const GITHUB_WORKFLOWS = '.github/workflows';

    /**
     * A line that sets APP_ENV to a literal environment name: the key, an =
     * or : with optional spaces, then an optional single or double quote and
     * the value. The \b keeps IS_APP_ENV and MY_APP_ENV out; the character
     * class stops the value at a space, quote or comment, so
     * APP_ENV=staging # note reads staging.
     */
    private const ENV_LINE_PATTERN = '/\bAPP_ENV\s*[=:]\s*[\'"]?([A-Za-z0-9_-]+)/';

    public function id(): string
    {
        return 'app-env';
    }

    public function run(CheckContext $context): array
    {
        $findings = [];
        foreach ($this->filesToScan($context->appPath) as $relativePath) {
            if (in_array($relativePath, self::ENV_FILES, true) && !$context->mayReadEnvFile($this->id(), $relativePath)) {
                continue;
            }
            $raw = file_get_contents($context->appPath . '/' . $relativePath);
            if ($raw === false) {
                continue;
            }
            $hit = $this->firstFlaggedLine($raw);
            if ($hit !== null) {
                $findings[] = $this->warn($relativePath, $hit[0], $hit[1]);
            }
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no env, deploy or CI file sets APP_ENV to anything but production',
            )];
        }

        return $findings;
    }

    /**
     * The relative paths (forward slashes) this check scans, sorted: the
     * committed env templates, the CI pipelines (.gitlab-ci.yml and every
     * .github/workflows/*.yml), and every file under a deploy directory at
     * any depth.
     *
     * @param string $appPath the application path
     *
     * @return list<string>
     */
    private function filesToScan(string $appPath): array
    {
        $appPath = rtrim($appPath, '/\\');
        $paths = [];

        foreach (self::ENV_FILES as $name) {
            if (is_file($appPath . '/' . $name)) {
                $paths[$name] = $name;
            }
        }

        if (is_file($appPath . '/' . self::GITLAB_CI)) {
            $paths[self::GITLAB_CI] = self::GITLAB_CI;
        }

        $workflows = $appPath . '/' . self::GITHUB_WORKFLOWS;
        if (is_dir($workflows)) {
            foreach (scandir($workflows) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.yml')) {
                    continue;
                }
                $relative = self::GITHUB_WORKFLOWS . '/' . $entry;
                $paths[$relative] = $relative;
            }
        }

        foreach (self::DEPLOY_DIRS as $dir) {
            $base = $appPath . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $inner = new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS);
            $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current): bool {
                return !($current->isDir() && in_array($current->getBasename(), self::SKIP_DIRS, true));
            });
            $iterator = new RecursiveIteratorIterator($outer, RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($iterator as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appPath) + 1));
                $paths[$relative] = $relative;
            }
        }

        $paths = array_values($paths);
        sort($paths);

        return $paths;
    }

    /**
     * The 1-based line and value of the first line that sets APP_ENV to a
     * literal environment that is not production, or null when no line does.
     * Comment lines are ignored, and a value that starts with $ (a variable
     * reference) is not flagged.
     *
     * @return array{0: int, 1: string}|null
     */
    private function firstFlaggedLine(string $raw): ?array
    {
        foreach (explode("\n", $raw) as $index => $line) {
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }
            if (preg_match(self::ENV_LINE_PATTERN, $line, $matches) !== 1) {
                continue;
            }
            $value = $matches[1];
            if (str_starts_with($value, '$') || strtolower($value) === 'production') {
                continue;
            }

            return [$index + 1, $value];
        }

        return null;
    }

    /**
     * A warning finding for one file that points APP_ENV away from
     * production.
     */
    private function warn(string $relativePath, int $line, string $value): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                "APP_ENV is '%s' in %s; deployed environments must set APP_ENV=production (D-133)",
                $value,
                $relativePath,
            ),
            $relativePath,
            $line,
            null,
            'not-production',
        );
    }
}
