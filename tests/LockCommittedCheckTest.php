<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\LockCommittedCheck;
use Waynestate\Proctor\Creed\Policy;

/**
 * Calls the lock-committed check directly on temporary app directories, so
 * the content-hash cases can be built around a real composer.json.
 */
final class LockCommittedCheckTest extends TestCase
{
    /** The fixed date every run uses. */
    private const TODAY = '2026-10-27';

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

    /**
     * The temporary app directories this test creates.
     *
     * @var list<string>
     */
    private array $appDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->appDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->appDirs = [];
    }

    public function testAMatchingLockGivesOnlyThePassFinding(): void
    {
        $composerJson = ['require' => ['php' => '^8.3']];
        $appPath = $this->makeApp($composerJson, $this->contentHash($composerJson));

        $findings = (new LockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
        self::assertSame('composer.lock is present', $findings[0]->message);
        self::assertSame('composer.lock', $findings[0]->path);
    }

    public function testAnOutOfSyncLockAddsAWarningWithTheRuleId(): void
    {
        $composerJson = ['require' => ['php' => '^8.3']];
        $appPath = $this->makeApp($composerJson, str_repeat('0', 32));

        $findings = (new LockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);

        self::assertSame(Finding::STATUS_WARNING, $findings[1]->status);
        self::assertSame('out-of-sync', $findings[1]->rule);
        self::assertSame('proctor/lock-committed/out-of-sync', $findings[1]->ruleId);
        self::assertSame(
            'composer.lock is out of sync with composer.json (content-hash differs); run composer update to refresh it and commit the lock',
            $findings[1]->message,
        );
        self::assertSame('composer.lock', $findings[1]->path);
    }

    public function testAPlaceholderHashGivesOnlyThePassFinding(): void
    {
        $composerJson = ['require' => ['php' => '^8.3']];
        $appPath = $this->makeApp($composerJson, 'fixture');

        $findings = (new LockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
    }

    public function testAMatchingLockWithConfigPlatformGivesOnlyThePassFinding(): void
    {
        $composerJson = [
            'require' => ['php' => '^8.3'],
            'config' => ['platform' => ['php' => '8.3.0']],
        ];
        $appPath = $this->makeApp($composerJson, $this->contentHash($composerJson));

        $findings = (new LockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
    }

    public function testAHashesThatIsNot32LowercaseHexGivesOnlyThePassFinding(): void
    {
        $composerJson = ['require' => ['php' => '^8.3']];
        $badHashes = [
            strtoupper(md5('waynestate')), // uppercase hex
            substr(md5('waynestate'), 0, 31), // 31 characters
            md5('waynestate') . '0', // 33 characters
            str_repeat('g', 32), // not hex
            'fixture', // the placeholder the fixtures use
        ];
        foreach ($badHashes as $hash) {
            $appPath = $this->makeApp($composerJson, $hash);
            $findings = (new LockCommittedCheck())->run($this->contextFor($appPath));
            self::assertCount(1, $findings, sprintf('hash "%s" must give no extra finding', $hash));
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
            self::assertNull($findings[0]->rule);
        }
    }

    public function testALockWithoutAContentHashFieldGivesOnlyThePassFinding(): void
    {
        $appPath = $this->makeRawApp(
            json_encode(['require' => ['php' => '^8.3']]),
            json_encode(['packages' => [], 'packages-dev' => []]),
        );

        $findings = (new LockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
    }

    public function testAnUnparsableLockGivesOnlyThePassFinding(): void
    {
        $appPath = $this->makeRawApp(
            json_encode(['require' => ['php' => '^8.3']]),
            'not json at all',
        );

        $findings = (new LockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
    }

    public function testAValidHashWithNoComposerJsonGivesOnlyThePassFinding(): void
    {
        $appPath = $this->makeRawApp(null, json_encode([
            'packages' => [],
            'packages-dev' => [],
            'content-hash' => str_repeat('0', 32),
        ]));

        $findings = (new LockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
    }

    public function testAValidHashWithAnUnparsableComposerJsonGivesOnlyThePassFinding(): void
    {
        $appPath = $this->makeRawApp('not json at all', json_encode([
            'packages' => [],
            'packages-dev' => [],
            'content-hash' => str_repeat('0', 32),
        ]));

        $findings = (new LockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
    }

    public function testAnOutOfSyncLockWithConfigPlatformAddsAWarning(): void
    {
        $composerJson = [
            'require' => ['php' => '^8.3'],
            'config' => ['platform' => ['php' => '8.3.0']],
        ];
        // The hash of the same composer.json without the config block: it
        // matches only if the check ignores config.platform, so a warning
        // proves config.platform is part of the hash input.
        $appPath = $this->makeApp($composerJson, $this->contentHash(['require' => ['php' => '^8.3']]));

        $findings = (new LockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame(Finding::STATUS_WARNING, $findings[1]->status);
        self::assertSame('proctor/lock-committed/out-of-sync', $findings[1]->ruleId);
    }

    public function testALockHashedWithConfigPolicyGivesOnlyThePassFinding(): void
    {
        $composerJson = [
            'require' => ['php' => '^8.3'],
            'config' => [
                'platform' => ['php' => '8.3.0'],
                'policy' => ['cooldown' => ['period' => '3 days']],
            ],
        ];
        $appPath = $this->makeApp($composerJson, $this->contentHash($composerJson));

        $findings = (new LockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
    }

    public function testALockHashedWithoutConfigPolicyWarnsWhenPolicyIsSet(): void
    {
        $composerJson = [
            'require' => ['php' => '^8.3'],
            'config' => [
                'platform' => ['php' => '8.3.0'],
                'policy' => ['cooldown' => ['period' => '3 days']],
            ],
        ];
        // The hash of the same composer.json without the policy block: it
        // matches only if the check ignores config.policy, so a warning
        // proves config.policy is part of the hash input.
        $withoutPolicy = [
            'require' => ['php' => '^8.3'],
            'config' => ['platform' => ['php' => '8.3.0']],
        ];
        $appPath = $this->makeApp($composerJson, $this->contentHash($withoutPolicy));

        $findings = (new LockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame(Finding::STATUS_WARNING, $findings[1]->status);
        self::assertSame('out-of-sync', $findings[1]->rule);
        self::assertSame('proctor/lock-committed/out-of-sync', $findings[1]->ruleId);
    }

    public function testAComposerJsonWithoutPolicyHashesAsBefore(): void
    {
        $composerJson = [
            'require' => ['php' => '^8.3'],
            'config' => ['platform' => ['php' => '8.3.0']],
        ];
        // The hash a pre-2.11 lock carries: the relevant keys plus
        // config.platform only, built by hand rather than through the
        // shared helper so the old result is pinned directly.
        $content = [
            'require' => $composerJson['require'],
            'config' => ['platform' => $composerJson['config']['platform']],
        ];
        ksort($content);
        $legacyHash = md5(json_encode($content));

        self::assertSame($legacyHash, $this->contentHash($composerJson));

        $appPath = $this->makeApp($composerJson, $legacyHash);

        $findings = (new LockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
    }

    /**
     * Builds a temporary app directory with the given composer.json and a
     * composer.lock carrying the given content-hash, and returns its path.
     *
     * @param array<string, mixed> $composerJson
     */
    private function makeApp(array $composerJson, string $contentHash): string
    {
        $appPath = sys_get_temp_dir() . '/proctor-lock-committed-' . uniqid('', true);
        self::assertTrue(mkdir($appPath, 0777, true), sprintf('could not create temp dir "%s"', $appPath));
        $this->appDirs[] = $appPath;

        file_put_contents($appPath . '/composer.json', json_encode($composerJson));
        file_put_contents($appPath . '/composer.lock', json_encode([
            'packages' => [],
            'packages-dev' => [],
            'content-hash' => $contentHash,
        ]));

        return $appPath;
    }

    /**
     * Builds a temporary app directory from raw file contents; either may be
     * null to leave the file out, and the path is returned.
     */
    private function makeRawApp(?string $composerJsonRaw, ?string $lockRaw): string
    {
        $appPath = sys_get_temp_dir() . '/proctor-lock-committed-' . uniqid('', true);
        self::assertTrue(mkdir($appPath, 0777, true), sprintf('could not create temp dir "%s"', $appPath));
        $this->appDirs[] = $appPath;

        if ($composerJsonRaw !== null) {
            file_put_contents($appPath . '/composer.json', $composerJsonRaw);
        }
        if ($lockRaw !== null) {
            file_put_contents($appPath . '/composer.lock', $lockRaw);
        }

        return $appPath;
    }

    /**
     * The content-hash Composer computes for the given composer.json data:
     * the relevant keys only, plus config.platform and config.policy when
     * set, ksorted and md5-hashed as JSON.
     *
     * @param array<string, mixed> $composerJson
     */
    private function contentHash(array $composerJson): string
    {
        $content = [];
        foreach (self::RELEVANT_KEYS as $key) {
            if (isset($composerJson[$key])) {
                $content[$key] = $composerJson[$key];
            }
        }
        if (isset($composerJson['config']['platform'])) {
            $content['config']['platform'] = $composerJson['config']['platform'];
        }
        if (isset($composerJson['config']['policy'])) {
            $content['config']['policy'] = $composerJson['config']['policy'];
        }
        ksort($content);

        return md5(json_encode($content));
    }

    private function contextFor(string $appPath): CheckContext
    {
        return new CheckContext(
            $appPath,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY),
        );
    }

    /**
     * Removes a directory tree.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
