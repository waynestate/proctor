<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Fails when a deploy script drops the database: an artisan command that
 * wipes or rebuilds the schema (migrate:fresh, migrate:refresh,
 * migrate:reset, db:wipe) in a deploy script runs on every deploy and
 * erases production data. Deploys must run plain `php artisan migrate --force`.
 *
 * Scanned: every .sh and .php file under a top-level deployments/,
 * deploy_scripts/, deploy/ or scripts/ directory, the top-level build.sh,
 * release.sh and .gitlab-ci.yml, and the scripts section of composer.json.
 * Shell comments are ignored.
 *
 * The proctor does not allow erasing the exam.
 */
final class DestructiveDeployCheck implements CheckInterface
{
    /** Top-level directories whose .sh and .php files are deploy scripts. */
    private const DEPLOY_DIRS = ['deployments', 'deploy_scripts', 'deploy', 'scripts'];

    /** Top-level files that are deploy scripts. */
    private const DEPLOY_FILES = ['build.sh', 'release.sh', '.gitlab-ci.yml'];

    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

    /** Artisan commands that drop or rebuild the database. */
    private const DESTRUCTIVE_COMMANDS = ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe'];

    public function id(): string
    {
        return 'destructive-deploy';
    }

    public function run(CheckContext $context): array
    {
        $findings = [];
        foreach ($this->filesToScan($context->appPath) as $relativePath) {
            $absolutePath = $context->appPath . '/' . $relativePath;
            $lines = file($absolutePath, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $index => $line) {
                $line = rtrim((string) $line, "\r\n");
                if ($this->isShellComment($line)) {
                    continue;
                }
                $command = $this->destructiveCommandOnLine($line);
                if (null !== $command) {
                    $findings[] = $this->fail($command, $relativePath, $index + 1);
                }
            }
        }

        foreach ($this->composerScriptFindings($context->appPath) as $finding) {
            $findings[] = $finding;
        }

        if ($findings === []) {
            return [new Finding($this->id(), Finding::STATUS_PASS, 'no destructive deploy commands found')];
        }

        // Stable on every OS: by path, then line.
        usort($findings, static function (Finding $a, Finding $b): int {
            return strcmp((string) $a->path, (string) $b->path) ?: ($a->line <=> $b->line);
        });

        return $findings;
    }

    /**
     * The relative paths (forward slashes) of the deploy scripts this check
     * scans: every .sh and .php file under a top-level deploy directory, and
     * the top-level build.sh, release.sh and .gitlab-ci.yml.
     *
     * @param string $appPath the application path
     *
     * @return list<string>
     */
    private function filesToScan(string $appPath): array
    {
        $appPath = rtrim($appPath, '/\\');
        $inner = new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS);
        $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current): bool {
            return !($current->isDir() && in_array($current->getBasename(), self::SKIP_DIRS, true));
        });
        $iterator = new RecursiveIteratorIterator($outer, RecursiveIteratorIterator::LEAVES_ONLY);

        $files = [];
        foreach ($iterator as $file) {
            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($appPath) + 1));
            $basename = $file->getBasename();
            $segments = explode('/', $relativePath);
            if (1 === count($segments)) {
                if (in_array($basename, self::DEPLOY_FILES, true)) {
                    $files[] = $relativePath;
                }
            } elseif (in_array($segments[0], self::DEPLOY_DIRS, true)
                && (str_ends_with($basename, '.sh') || str_ends_with($basename, '.php'))) {
                $files[] = $relativePath;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Whether the line is a shell comment: only whitespace before the #.
     */
    private function isShellComment(string $line): bool
    {
        return preg_match('/^\s*#/', $line) === 1;
    }

    /**
     * The destructive artisan command on the line, or null: the line must
     * name artisan, and the command must come after it.
     */
    private function destructiveCommandOnLine(string $line): ?string
    {
        if (preg_match_all('/\bartisan\b/i', $line, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        foreach ($matches[0] as $match) {
            $after = substr($line, (int) $match[1] + strlen('artisan'));
            foreach (self::DESTRUCTIVE_COMMANDS as $command) {
                if (false !== stripos($after, $command)) {
                    return $command;
                }
            }
        }

        return null;
    }

    /**
     * The findings for the scripts section of composer.json, if any.
     *
     * @param string $appPath the application path
     *
     * @return list<Finding>
     */
    private function composerScriptFindings(string $appPath): array
    {
        $composerPath = rtrim($appPath, '/\\') . '/composer.json';
        if (!is_file($composerPath)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($composerPath), true);
        if (!is_array($decoded) || !isset($decoded['scripts']) || !is_array($decoded['scripts'])) {
            return [];
        }

        $lines = file($composerPath, FILE_IGNORE_NEW_LINES);
        $lines = $lines === false
            ? []
            : array_map(static fn(string $line): string => rtrim($line, "\r\n"), $lines);

        $findings = [];
        foreach ($decoded['scripts'] as $script) {
            $commands = is_array($script) ? array_values($script) : [$script];
            foreach ($commands as $command) {
                if (!is_string($command)) {
                    continue;
                }
                $destructive = $this->destructiveCommandOnLine($command);
                if (null === $destructive) {
                    continue;
                }
                $findings[] = $this->fail($destructive, 'composer.json', $this->lineOf($lines, $command, $destructive));
            }
        }

        return $findings;
    }

    /**
     * The 1-based line of a composer script command in the raw file: the line
     * carrying the full command string, else the first line naming the
     * destructive command, else 1.
     *
     * @param list<string> $lines     the file's lines without line endings
     * @param string       $command   the full script command string
     * @param string       $destructive the destructive command it contains
     */
    private function lineOf(array $lines, string $command, string $destructive): int
    {
        foreach ($lines as $index => $line) {
            if (str_contains($line, $command)) {
                return $index + 1;
            }
        }
        foreach ($lines as $index => $line) {
            if (str_contains($line, $destructive)) {
                return $index + 1;
            }
        }

        return 1;
    }

    /**
     * A fail finding for one destructive command.
     */
    private function fail(string $command, string $relativePath, int $line): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_FAIL,
            sprintf('%s in a deploy script drops data on every run. Use `php artisan migrate --force`.', $command),
            $relativePath,
            $line,
        );
    }
}
