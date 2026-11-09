<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Warns when a committed env, deploy or CI file turns Laravel debug on.
 *
 * CREED practice 12 keeps APP_DEBUG false in production: a committed config
 * that sets it to true leaks stack traces and env values to users over HTTP.
 * PROCTOR never reads .env itself (a committed .env is another check's
 * finding); it looks at the production templates (.env.production and
 * .env.squire.example; .env.example is the local-development template,
 * where debug is expected to be on), every file under deploy/ or
 * deployments/, and the CI pipelines, where a debug switch would ship
 * with the release.
 *
 * Emits proctor/app-debug on pass and proctor/app-debug/enabled on warning.
 *
 * One warning per file, on the line of its first true value. The proctor
 * marks the paper, it does not rewrite it.
 */
final class AppDebugCheck implements CheckInterface
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
     * A line that sets APP_DEBUG to a truthy value: the key, an = or : with
     * optional spaces, then true or 1 with an optional single or double
     * quote. The \b keeps IS_APP_DEBUG and MY_APP_DEBUG out; (?!\w) keeps
     * truefoo and 123 out.
     */
    private const TRUTHY_LINE_PATTERN = '/\bAPP_DEBUG\s*[=:]\s*[\'"]?(?:true|1)(?!\w)/i';

    public function id(): string
    {
        return 'app-debug';
    }

    public function run(CheckContext $context): array
    {
        $findings = [];
        foreach ($this->filesToScan($context->appPath) as $relativePath) {
            $raw = file_get_contents($context->appPath . '/' . $relativePath);
            if ($raw === false) {
                continue;
            }
            $line = $this->firstTruthyLine($raw);
            if ($line !== null) {
                $findings[] = $this->warn($relativePath, $line);
            }
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no env, deploy or CI file sets APP_DEBUG=true',
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
     * The 1-based line of the first line that sets APP_DEBUG to a truthy
     * value, or null when no line does. Comment lines are ignored.
     */
    private function firstTruthyLine(string $raw): ?int
    {
        foreach (explode("\n", $raw) as $index => $line) {
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }
            if (preg_match(self::TRUTHY_LINE_PATTERN, $line) === 1) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * A warning finding for one file that turns APP_DEBUG on.
     */
    private function warn(string $relativePath, int $line): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                'APP_DEBUG=true in %s:%d shows stack traces and env values to users in production; set APP_DEBUG=false (Laravel reads it in config/app.php).',
                $relativePath,
                $line,
            ),
            $relativePath,
            $line,
            null,
            'enabled',
        );
    }
}
