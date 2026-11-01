<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Proctor\ToolError;

/**
 * Warns when Oracle Instant Client is committed to the repository.
 *
 * App repos pick up the client as 60 to 126 MB zips, unzipped instantclient_*
 * folders and stray libclntsh.so copies. They bloat every clone, pin a stale
 * client, and are never needed on the hosting platform, whose images already
 * carry Instant Client.
 *
 * Only committed files count: the git-tracked files when the app path sits in
 * a git repository and git can be run, otherwise the files on disk. vendor/,
 * node_modules/ and .git/ are skipped at any depth. A match is an
 * instantclient*.zip or instantclient*.rpm archive, any file inside a
 * directory named instantclient or instantclient_*, or a loose library file
 * (libclntsh.so*, libclntshcore.so*, libociei.so, libocijdbc*.so, oci.dll,
 * oraociei*.dll). One warning per top-level match: the archive, the topmost
 * instantclient* directory, or the loose file, with its total size in MB.
 * Warning, not failure: a new check must not turn a passing app into a
 * failing one.
 *
 * The proctor confiscates the oversized textbook at the door: the exam hall
 * already keeps a copy on the shelf.
 */
final class InstantClientCommittedCheck implements CheckInterface
{
    /** Archive names that are an Instant Client download. */
    private const ARCHIVE_PATTERNS = [
        '/^instantclient.*\.zip$/i',
        '/^instantclient.*\.rpm$/i',
    ];

    /** Loose file names that are Instant Client shared libraries. */
    private const LIBRARY_PATTERNS = [
        '/^libclntsh\.so.*/i',
        '/^libclntshcore\.so.*/i',
        '/^libociei\.so$/i',
        '/^libocijdbc.*\.so$/i',
        '/^oci\.dll$/i',
        '/^oraociei.*\.dll$/i',
    ];

    /** Directory names that mark a folder as an Instant Client install. */
    private const DIRECTORY_PATTERNS = [
        '/^instantclient$/i',
        '/^instantclient_.+$/i',
    ];

    /** Directories skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

    public function __construct(
        private readonly ProcessRunnerInterface $process = new ProcessRunner(),
    ) {}

    public function id(): string
    {
        return 'instant-client-committed';
    }

    public function run(CheckContext $context): array
    {
        $totals = [];
        foreach ($this->committedFiles($context) as $path) {
            $top = $this->topLevelMatch($path);
            if ($top === null) {
                continue;
            }
            $size = @filesize($context->appPath . '/' . $path);
            $totals[$top] = ($totals[$top] ?? 0) + ($size === false ? 0 : $size);
        }

        if ($totals === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no Oracle Instant Client committed to the repo',
            )];
        }

        ksort($totals);
        $findings = [];
        foreach ($totals as $path => $bytes) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf(
                    '%s (%.1f MB) is Oracle Instant Client committed to the repo. Delete it: Citadel\'s images already include Instant Client, and the WSU Sail and Docker images will too. Then run git rm -r --cached %s and add it to .gitignore.',
                    $path,
                    $bytes / 1048576,
                    $path,
                ),
                $path,
            );
        }

        return $findings;
    }

    /**
     * The relative paths (forward slashes) of the committed files: the
     * git-tracked files when the app path is inside a git repository and git
     * can be run, otherwise the files on disk. Skips vendor/, node_modules/
     * and .git/ at any depth.
     *
     * @return list<string>
     */
    private function committedFiles(CheckContext $context): array
    {
        $tracked = $this->gitTrackedFiles($context);
        if ($tracked !== null) {
            return $tracked;
        }

        $inner = new RecursiveDirectoryIterator($context->appPath, FilesystemIterator::SKIP_DOTS);
        $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current): bool {
            return !($current->isDir() && in_array($current->getBasename(), self::SKIP_DIRS, true));
        });
        $iterator = new RecursiveIteratorIterator($outer, RecursiveIteratorIterator::LEAVES_ONLY);

        $files = [];
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($context->appPath) + 1));
        }
        sort($files);

        return $files;
    }

    /**
     * The git-tracked file paths (relative to the app path, forward
     * slashes), or null when the app path is not inside a git repository or
     * git cannot be run.
     *
     * @return list<string>|null
     */
    private function gitTrackedFiles(CheckContext $context): ?array
    {
        $git = $this->findGit();
        if ($git === null) {
            return null;
        }

        try {
            $result = $this->process->run(
                [$git, '-C', $context->appPath, 'ls-files', '-z'],
                $context->appPath,
                $context->timeout,
            );
        } catch (ToolError $e) {
            return null;
        }
        if ($result->exitCode !== 0) {
            return null;
        }

        $files = [];
        foreach (explode("\0", $result->stdout) as $path) {
            if ($path === '' || $this->skipped($path)) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);

        return $files;
    }

    /**
     * Finds the git executable on PATH, or returns null. The same walk as
     * ComposerAuditCheck::findComposer: resolving the path ourselves keeps
     * proc_open from printing a spawn warning into the report when git is
     * absent (the CLI tests run with a PATH that finds no tools).
     */
    private function findGit(): ?string
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

    /** True when any path segment is a skipped directory. */
    private function skipped(string $relativePath): bool
    {
        foreach (explode('/', $relativePath) as $segment) {
            if (in_array($segment, self::SKIP_DIRS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The top-level match a committed file belongs to: the topmost directory
     * named instantclient or instantclient_* when the file sits under one
     * (the directory swallows everything beneath it, nested archives
     * included), otherwise the file itself when its name is an Instant
     * Client archive or library. Null when the file is not Instant Client.
     */
    private function topLevelMatch(string $relativePath): ?string
    {
        $segments = explode('/', $relativePath);
        $last = count($segments) - 1;

        for ($index = 0; $index < $last; $index++) {
            foreach (self::DIRECTORY_PATTERNS as $pattern) {
                if (preg_match($pattern, $segments[$index]) === 1) {
                    return implode('/', array_slice($segments, 0, $index + 1));
                }
            }
        }

        $base = $segments[$last];
        foreach (self::ARCHIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $base) === 1) {
                return $relativePath;
            }
        }
        foreach (self::LIBRARY_PATTERNS as $pattern) {
            if (preg_match($pattern, $base) === 1) {
                return $relativePath;
            }
        }

        return null;
    }
}
