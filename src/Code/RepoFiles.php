<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Code;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Proctor\Check\ProcessRunner;
use Waynestate\Proctor\Check\ProcessRunnerInterface;
use Waynestate\Proctor\ToolError;

/**
 * The files of the app under check, as the repository holds them.
 *
 * When the app path sits in a git repository and git can be run, the list is
 * the git-tracked files (`git ls-files`), so a developer's own `.env` or a
 * local log never counts as committed. Otherwise it is the files on disk. A
 * check that must not grade an untracked file (a real `.env` on a laptop)
 * asks committedKnown() first: it is true when the list came from git, or
 * when the run is in CI, where the checkout holds only what the repository
 * holds.
 *
 * Directories named in $skipDirs are skipped at any depth. Paths are relative
 * to the app path, with forward slashes, sorted.
 */
final class RepoFiles
{
    /** The CI markers that say the files on disk are a fresh checkout. */
    private const CI_MARKERS = ['CI', 'GITLAB_CI', 'GITHUB_ACTIONS'];

    /**
     * @param list<string> $files
     */
    private function __construct(
        public readonly array $files,
        public readonly bool $fromGit,
    ) {}

    /**
     * Lists the app's files.
     *
     * @param list<string> $skipDirs directory names skipped at any depth
     */
    public static function list(
        string $appPath,
        int $timeout = 300,
        array $skipDirs = ['vendor', 'node_modules', '.git'],
        ?ProcessRunnerInterface $process = null,
    ): self {
        $appPath = rtrim($appPath, '/\\');
        $tracked = self::gitTracked($appPath, $timeout, $skipDirs, $process ?? new ProcessRunner());
        if ($tracked !== null) {
            return new self($tracked, true);
        }

        return new self(self::onDisk($appPath, $skipDirs), false);
    }

    /**
     * Whether every listed file is known to be committed: the list came from
     * git, or the run is in CI.
     */
    public function committedKnown(): bool
    {
        if ($this->fromGit) {
            return true;
        }
        foreach (self::CI_MARKERS as $marker) {
            $value = getenv($marker);
            if ($value !== false && $value !== '' && strtolower($value) !== 'false') {
                return true;
            }
        }

        return false;
    }

    /**
     * The listed files under a top-level directory (no trailing slash).
     *
     * @return list<string>
     */
    public function under(string $directory): array
    {
        $prefix = trim($directory, '/') . '/';

        return array_values(array_filter(
            $this->files,
            static fn(string $path): bool => str_starts_with($path, $prefix),
        ));
    }

    /**
     * @param list<string> $skipDirs
     *
     * @return list<string>|null
     */
    private static function gitTracked(string $appPath, int $timeout, array $skipDirs, ProcessRunnerInterface $process): ?array
    {
        $git = self::findGit();
        if ($git === null || !is_dir($appPath)) {
            return null;
        }

        try {
            $result = $process->run([$git, '-C', $appPath, 'ls-files', '-z'], $appPath, $timeout);
        } catch (ToolError) {
            return null;
        }
        if ($result->exitCode !== 0) {
            return null;
        }

        $files = [];
        foreach (explode("\0", $result->stdout) as $path) {
            if ($path === '' || self::skipped($path, $skipDirs)) {
                continue;
            }
            // A tracked file deleted in the working tree is not there to read.
            if (!is_file($appPath . '/' . $path)) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $skipDirs
     *
     * @return list<string>
     */
    private static function onDisk(string $appPath, array $skipDirs): array
    {
        if (!is_dir($appPath)) {
            return [];
        }
        $inner = new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS);
        $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current) use ($skipDirs): bool {
            return !($current->isDir() && in_array($current->getBasename(), $skipDirs, true));
        });
        $files = [];
        foreach (new RecursiveIteratorIterator($outer, RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($appPath) + 1));
        }
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $skipDirs
     */
    private static function skipped(string $relativePath, array $skipDirs): bool
    {
        foreach (explode('/', $relativePath) as $segment) {
            if (in_array($segment, $skipDirs, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The git executable on PATH, or null. Resolving the path first keeps
     * proc_open from printing a spawn warning into the report when git is
     * absent (the CLI tests run with a PATH that finds no tools). Shared with
     * OverrideApproverCheck, which must find git the same way.
     */
    public static function findGit(): ?string
    {
        $pathEnv = getenv('PATH');
        if ($pathEnv === false || $pathEnv === '') {
            $pathEnv = getenv('Path');
        }
        if ($pathEnv === false || $pathEnv === '') {
            return null;
        }

        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $candidates = $isWindows ? ['git.exe', 'git.cmd', 'git'] : ['git'];
        foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
            if ($dir === '') {
                continue;
            }
            foreach ($candidates as $name) {
                $full = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($full) && ($isWindows || is_executable($full))) {
                    return $full;
                }
            }
        }

        return null;
    }
}
