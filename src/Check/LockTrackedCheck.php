<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\ToolError;

/**
 * Checks that git tracks composer.lock when the lock is on disk.
 *
 * This is the git half of what used to sit inside lock-committed, split out
 * because the two questions need different answers: lock-committed grades
 * the file on disk (missing, or out of sync with composer.json), while this
 * check asks git whether the lock is committed at all. CI's own install
 * writes a lock when composer.json has changed, so an uncommitted lock hides
 * behind a passing lock-committed until the release installs a tree nobody
 * tested; the warning (`proctor/lock-tracked`) names the fix, git add.
 *
 * The check starts git, so ADVISOR's in-process proctor_check skips it
 * (REASON_SUBPROCESS); outside a git repository, or when git cannot be run
 * (git not found, a ToolError, or a non-zero exit code such as dubious
 * ownership), it says nothing.
 */
final class LockTrackedCheck implements CheckInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $process = new ProcessRunner(),
        private readonly ?string $gitPath = null,
    ) {}

    public function id(): string
    {
        return 'lock-tracked';
    }

    public function run(CheckContext $context): array
    {
        $lockFile = $context->appPath . '/composer.lock';
        if (!is_file($lockFile)) {
            return [];
        }

        $tracked = $this->gitTracksLock($context);
        if ($tracked === true) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'composer.lock is tracked by git',
                'composer.lock',
            )];
        }
        if ($tracked === false) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'composer.lock is on disk but git does not track it; commit it (git add composer.lock) so the release installs the tree you tested',
                'composer.lock',
                1,
            )];
        }

        return [];
    }

    /**
     * Whether git tracks composer.lock in the app path: true when git lists
     * it, false when git ran cleanly and listed nothing, null when the app
     * path is not inside a git repository or git cannot be run (git not
     * found, a ToolError, or a non-zero exit code such as dubious
     * ownership), in which case the check says nothing.
     */
    private function gitTracksLock(CheckContext $context): ?bool
    {
        $git = $this->gitPath ?? $this->findGit();
        if ($git === null) {
            return null;
        }

        try {
            $result = $this->process->run(
                [$git, '-C', $context->appPath, 'ls-files', '-z', '--', 'composer.lock'],
                $context->appPath,
                $context->timeout,
            );
        } catch (ToolError $e) {
            return null;
        }
        if ($result->exitCode !== 0) {
            return null;
        }

        return trim(str_replace("\0", '', $result->stdout)) !== '';
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
}
