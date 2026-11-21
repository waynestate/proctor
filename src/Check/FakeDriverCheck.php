<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Fails when a committed env, deploy or CI file allows SESAME's fake driver.
 *
 * The fake driver signs anyone in without an identity provider, so it
 * belongs in local and test environments only: a committed config that sets
 * SESAME_DRIVER=fake or SESAME_FAKE_ALLOWED=true ships that hole with the
 * release. PROCTOR never reads .env itself (a committed .env is another
 * check's finding); it looks at the production templates (.env.production
 * and .env.squire.example; .env.example is the local-development template,
 * where the fake driver belongs), every file under deploy/ or deployments/,
 * and the CI pipelines, where a fake driver would ship with the release.
 * When the run carries an EnvFileFilter (ADVISOR's in-process run), an env
 * template it refuses is not opened.
 *
 * Emits proctor/fake-driver on pass and proctor/fake-driver/enabled on fail.
 *
 * One failure per file, on the line of its first match. The proctor marks
 * the paper, it does not rewrite it.
 */
final class FakeDriverCheck implements CheckInterface
{
    /** Committed production env templates at the app root that may carry the switch. */
    private const ENV_FILES = ['.env.production', '.env.squire.example'];

    /** Top-level directories whose files may allow the fake driver. */
    private const DEPLOY_DIRS = ['deploy', 'deployments'];

    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

    /** The GitLab pipeline file at the app root. */
    private const GITLAB_CI = '.gitlab-ci.yml';

    /** The directory holding GitHub workflow files. */
    private const GITHUB_WORKFLOWS = '.github/workflows';

    /**
     * A line that sets SESAME_DRIVER to the fake driver: the key, an = or :
     * with optional spaces, then fake with an optional single or double
     * quote. The \b keeps MY_SESAME_DRIVER out; (?!\w) keeps fakefoo out.
     */
    private const FAKE_DRIVER_LINE_PATTERN = '/\bSESAME_DRIVER\s*[=:]\s*[\'"]?fake(?!\w)/i';

    /**
     * A line that allows the fake driver: the key, an = or : with optional
     * spaces, then true or 1 with an optional single or double quote. The
     * \b keeps MY_SESAME_FAKE_ALLOWED out; (?!\w) keeps truefoo and 123 out.
     */
    private const FAKE_ALLOWED_LINE_PATTERN = '/\bSESAME_FAKE_ALLOWED\s*[=:]\s*[\'"]?(?:true|1)(?!\w)/i';

    public function id(): string
    {
        return 'fake-driver';
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
            $line = $this->firstMatchLine($raw);
            if ($line !== null) {
                $findings[] = $this->fail($relativePath, $line);
            }
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no env, deploy or CI file allows the SESAME fake driver',
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
     * The 1-based line of the first line that allows the fake driver, or
     * null when no line does. Comment lines are ignored.
     */
    private function firstMatchLine(string $raw): ?int
    {
        foreach (explode("\n", $raw) as $index => $line) {
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }
            if (preg_match(self::FAKE_DRIVER_LINE_PATTERN, $line) === 1
                || preg_match(self::FAKE_ALLOWED_LINE_PATTERN, $line) === 1) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * A failure finding for one file that allows the fake driver.
     */
    private function fail(string $relativePath, int $line): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_FAIL,
            sprintf(
                'the SESAME fake driver is allowed in %s:%d: it signs anyone in without an identity provider; a production config must use cas (or another real driver) and must never set SESAME_FAKE_ALLOWED.',
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
