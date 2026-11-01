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
 * Warns when forked copies of WayneUI's views or assets are committed.
 *
 * WayneUI 1.x published its views into resources/views/vendor/wayne-ui/ and
 * its assets into public/vendor/wayne-ui/. Those copies silently override
 * the package, so an app keeps old layouts after a WayneUI upgrade and
 * nobody sees it; WayneUI's MIGRATING.md tells developers to delete them.
 *
 * Only committed files count: the git-tracked files when the app path sits
 * in a git repository and git can be run, otherwise the files on disk.
 * node_modules/ and .git/ are skipped at any depth, and so is the app's
 * top-level vendor/ folder (Composer's); a deeper vendor segment, such as
 * the one inside resources/views/vendor/wayne-ui/, still matches, because
 * the match is on the path relative to the app root. One warning per
 * matched directory, with its file count. Warning, not failure: a new
 * check must not turn a passing app into a failing one.
 *
 * The proctor confiscates the photocopied syllabus: the current one, with
 * the errata, is already pinned to the board.
 */
final class WayneUiForkedViewsCheck implements CheckInterface
{
    /** The committed folder that holds forked WayneUI views. */
    private const VIEWS_DIR = 'resources/views/vendor/wayne-ui';

    /** The committed folder that holds a copy of WayneUI's 1.x assets. */
    private const ASSETS_DIR = 'public/vendor/wayne-ui';

    /** Directories skipped at any depth. */
    private const SKIP_DIRS = ['node_modules', '.git'];

    public function __construct(
        private readonly ProcessRunnerInterface $process = new ProcessRunner(),
    ) {}

    public function id(): string
    {
        return 'wayneui-forked-views';
    }

    public function run(CheckContext $context): array
    {
        $views = 0;
        $assets = 0;
        foreach ($this->committedFiles($context) as $path) {
            if (str_starts_with($path, self::VIEWS_DIR . '/')) {
                $views++;
            } elseif (str_starts_with($path, self::ASSETS_DIR . '/')) {
                $assets++;
            }
        }

        if ($views === 0 && $assets === 0) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no forked WayneUI views or assets committed to the repo',
            )];
        }

        $findings = [];
        if ($views > 0) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf(
                    '%s/ (%d files) holds forked WayneUI views. They override the package, so layout fixes in new WayneUI releases do not reach this app. If you did not change them on purpose, delete the folder and run git rm -r --cached %s. If you did, compare them with the package views before upgrading.',
                    self::VIEWS_DIR,
                    $views,
                    self::VIEWS_DIR,
                ),
                self::VIEWS_DIR,
            );
        }
        if ($assets > 0) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf(
                    '%s/ (%d files) is a committed copy of WayneUI assets from the 1.x install. WayneUI 2.x serves its assets itself. Delete it and run git rm -r --cached %s.',
                    self::ASSETS_DIR,
                    $assets,
                    self::ASSETS_DIR,
                ),
                self::ASSETS_DIR,
            );
        }

        return $findings;
    }

    /**
     * The relative paths (forward slashes) of the committed files: the
     * git-tracked files when the app path is inside a git repository and
     * git can be run, otherwise the files on disk. Skips node_modules/ and
     * .git/ at any depth, and the app's top-level vendor/ folder only.
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
        $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current) use ($context): bool {
            if (! $current->isDir()) {
                return true;
            }
            $relative = str_replace('\\', '/', substr($current->getPathname(), strlen($context->appPath) + 1));
            $segments = explode('/', $relative);
            if (in_array($segments[count($segments) - 1], self::SKIP_DIRS, true)) {
                return false;
            }

            // Skip only the app's top-level vendor/ (Composer's); a deeper
            // vendor segment, such as resources/views/vendor/wayne-ui,
            // still counts.
            return $relative !== 'vendor';
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
     * slashes), or null when the app path is not inside a git repository
     * or git cannot be run.
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

    /**
     * True when the path sits in a skipped directory: node_modules/ or
     * .git/ at any depth, or the app's top-level vendor/ folder.
     */
    private function skipped(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);
        if ($segments[0] === 'vendor') {
            return true;
        }
        foreach ($segments as $segment) {
            if (in_array($segment, self::SKIP_DIRS, true)) {
                return true;
            }
        }

        return false;
    }
}
