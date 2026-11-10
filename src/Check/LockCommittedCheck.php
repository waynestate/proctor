<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\ToolError;

/**
 * Checks that composer.lock is committed, i.e. present in the app path, and
 * that it still matches composer.json.
 *
 * A present lock whose content-hash no longer equals the hash Composer
 * computes from composer.json describes a different tree from the one
 * declared, so an audit of it reports on packages the next install will not
 * use. That is a hygiene problem, not a broken install: the stale lock still
 * installs, so the mismatch is a warning, never a fail. The hash follows
 * Composer's own Locker::getContentHash: the relevant keys of the decoded
 * composer.json, plus config.platform when set, ksorted and md5-hashed as
 * JSON.
 *
 * When the lock is on disk, the check also asks git whether it tracks the
 * file and warns (`proctor/lock-committed/untracked`) when it does not,
 * because CI's own install would otherwise write a lock that was never
 * committed; outside a git repository, or when git cannot be run, it falls
 * back silently to the file on disk.
 */
final class LockCommittedCheck implements CheckInterface
{
    /**
     * The composer.json keys that take part in the lock's content-hash,
     * the same list Composer's Locker hashes over.
     *
     * @var list<string>
     */
    private const RELEVANT_KEYS = [
        'name',
        'version',
        'require',
        'require-dev',
        'conflict',
        'replace',
        'provide',
        'minimum-stability',
        'prefer-stable',
        'repositories',
        'extra',
    ];

    public function __construct(
        private readonly ProcessRunnerInterface $process = new ProcessRunner(),
        private readonly ?string $gitPath = null,
    ) {}

    public function id(): string
    {
        return 'lock-committed';
    }

    public function run(CheckContext $context): array
    {
        $lockFile = $context->appPath . '/composer.lock';
        if (!is_file($lockFile)) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                'composer.lock is missing',
                'composer.lock',
            )];
        }

        $findings = [new Finding(
            $this->id(),
            Finding::STATUS_PASS,
            'composer.lock is present',
            'composer.lock',
        )];

        $storedHash = $this->storedContentHash($lockFile);
        $expectedHash = $this->contentHash($context->appPath . '/composer.json');
        if ($storedHash !== null && $expectedHash !== null && $storedHash !== $expectedHash) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'composer.lock is out of sync with composer.json (content-hash differs); run composer update to refresh it and commit the lock',
                'composer.lock',
                1,
                null,
                'out-of-sync',
            );
        }

        if ($this->gitTracksLock($context) === false) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'composer.lock is on disk but git does not track it; commit it (git add composer.lock) so the release installs the tree you tested',
                'composer.lock',
                1,
                null,
                'untracked',
            );
        }

        return $findings;
    }

    /**
     * Whether git tracks composer.lock in the app path: true when git lists
     * it, false when git ran cleanly and listed nothing, null when the app
     * path is not inside a git repository or git cannot be run (git not
     * found, a ToolError, or a non-zero exit code such as dubious
     * ownership), in which case the check falls back to the file on disk.
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

    /**
     * The lock's content-hash field, or null when the lock cannot be read,
     * is not valid JSON, or the field is not a 32-character lowercase hex
     * string. A placeholder hash such as "fixture" is not a hash, so it
     * yields no finding.
     */
    private function storedContentHash(string $lockFile): ?string
    {
        $raw = @file_get_contents($lockFile);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $hash = $data['content-hash'] ?? null;

        return is_string($hash) && preg_match('/^[a-f0-9]{32}$/', $hash) === 1 ? $hash : null;
    }

    /**
     * The content-hash Composer computes for the given composer.json, or
     * null when the file is missing or does not parse.
     */
    private function contentHash(string $composerJsonFile): ?string
    {
        $raw = @file_get_contents($composerJsonFile);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $content = [];
        foreach (self::RELEVANT_KEYS as $key) {
            if (isset($data[$key])) {
                $content[$key] = $data[$key];
            }
        }
        if (isset($data['config']['platform'])) {
            $content['config']['platform'] = $data['config']['platform'];
        }
        ksort($content);

        $json = json_encode($content);
        if ($json === false) {
            return null;
        }

        return md5($json);
    }
}
