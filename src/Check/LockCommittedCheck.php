<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

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

        return $findings;
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

        return md5(json_encode($content));
    }
}
