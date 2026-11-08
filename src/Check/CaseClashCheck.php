<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Fails when two paths differ only by letter case (`Docs/Guide.md` and
 * `docs/guide.md`): macOS and Windows ignore case, so one checkout silently
 * overwrites the other, and a Linux host then autoloads a different file than
 * the developer edited. The fix is keeping one name and deleting or renaming
 * the duplicate.
 *
 * Scanned: every file and directory under the app path. Skipped at any depth:
 * vendor/, node_modules/ and .git/. One finding per topmost clash; a clash
 * below a clashing directory is part of it, not its own finding.
 *
 * Two students, one desk: the proctor notices.
 */
final class CaseClashCheck implements CheckInterface
{
    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

    public function id(): string
    {
        return 'case-clash';
    }

    public function run(CheckContext $context): array
    {
        $groups = [];
        foreach ($this->paths($context->appPath) as $path) {
            $groups[strtolower($path)][] = $path;
        }

        $clashes = [];
        foreach ($groups as $key => $paths) {
            if (count($paths) >= 2) {
                $clashes[$key] = $paths;
            }
        }

        // A clash below a clashing directory is part of that clash, so drop
        // every group nested inside another clash group.
        foreach (array_keys($clashes) as $key) {
            foreach (array_keys($clashes) as $otherKey) {
                if ($key !== $otherKey && str_starts_with($key, $otherKey . '/')) {
                    unset($clashes[$key]);
                    break;
                }
            }
        }

        if ($clashes === []) {
            return [new Finding($this->id(), Finding::STATUS_PASS, 'No two paths differ only by letter case.')];
        }

        ksort($clashes);
        $findings = [];
        foreach ($clashes as $paths) {
            sort($paths);
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                sprintf('%s differ only by letter case; on macOS and Windows one silently replaces the other. Rename all but one.', implode(' and ', $paths)),
                $paths[0],
            );
        }

        return $findings;
    }

    /**
     * Every relative path (forward slashes) of a file or directory under the
     * app path, skipping vendor/, node_modules/ and .git/ at any depth.
     *
     * @param string $appPath the application path
     *
     * @return list<string>
     */
    private function paths(string $appPath): array
    {
        $appPath = rtrim($appPath, '/\\');
        $inner = new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS);
        $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current): bool {
            return !($current->isDir() && in_array($current->getBasename(), self::SKIP_DIRS, true));
        });
        // SELF_FIRST so directories are yielded too, not just the files inside
        // them: a clashing pair of folders is the topmost clash to report.
        $iterator = new RecursiveIteratorIterator($outer, RecursiveIteratorIterator::SELF_FIRST);

        $paths = [];
        foreach ($iterator as $item) {
            $paths[] = str_replace('\\', '/', substr($item->getPathname(), strlen($appPath) + 1));
        }

        sort($paths);

        return $paths;
    }
}
