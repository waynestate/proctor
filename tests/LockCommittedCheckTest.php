<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\LockCommittedCheck;
use Waynestate\Proctor\Check\ProcessResult;
use Waynestate\Proctor\Check\ProcessRunnerInterface;
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

        $findings = $this->checkWith($this->silentGit())->run($this->contextFor($appPath));

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

        $findings = $this->checkWith($this->silentGit())->run($this->contextFor($appPath));

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

        $findings = $this->checkWith($this->silentGit())->run($this->contextFor($appPath));

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

        $findings = $this->checkWith($this->silentGit())->run($this->contextFor($appPath));

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
            $findings = $this->checkWith($this->silentGit())->run($this->contextFor($appPath));
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

        $findings = $this->checkWith($this->silentGit())->run($this->contextFor($appPath));

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

        $findings = $this->checkWith($this->silentGit())->run($this->contextFor($appPath));

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

        $findings = $this->checkWith($this->silentGit())->run($this->contextFor($appPath));

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

        $findings = $this->checkWith($this->silentGit())->run($this->contextFor($appPath));

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

        $findings = $this->checkWith($this->silentGit())->run($this->contextFor($appPath));

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame(Finding::STATUS_WARNING, $findings[1]->status);
        self::assertSame('proctor/lock-committed/out-of-sync', $findings[1]->ruleId);
    }

    public function testAnUntrackedLockAddsTheUntrackedWarning(): void
    {
        $composerJson = ['require' => ['php' => '^8.3']];
        $appPath = $this->makeApp($composerJson, $this->contentHash($composerJson));
        $fake = new class implements ProcessRunnerInterface {
            /** @var list<list<string>> */
            public array $commands = [];

            public function run(array $command, string $cwd, int $timeout = 300, ?array $env = null): ProcessResult
            {
                $this->commands[] = $command;

                return new ProcessResult(0, '', '');
            }
        };

        $findings = $this->checkWith($fake)->run($this->contextFor($appPath));

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);

        self::assertSame(Finding::STATUS_WARNING, $findings[1]->status);
        self::assertSame('untracked', $findings[1]->rule);
        self::assertSame('proctor/lock-committed/untracked', $findings[1]->ruleId);
        self::assertSame(
            'composer.lock is on disk but git does not track it; commit it (git add composer.lock) so the release installs the tree you tested',
            $findings[1]->message,
        );
        self::assertSame('composer.lock', $findings[1]->path);
        self::assertSame([['git', '-C', $appPath, 'ls-files', '-z', '--', 'composer.lock']], $fake->commands);
    }

    public function testATrackedLockAddsNoWarning(): void
    {
        $composerJson = ['require' => ['php' => '^8.3']];
        $appPath = $this->makeApp($composerJson, $this->contentHash($composerJson));
        $fake = new class implements ProcessRunnerInterface {
            public function run(array $command, string $cwd, int $timeout = 300, ?array $env = null): ProcessResult
            {
                return new ProcessResult(0, "composer.lock\0", '');
            }
        };

        $findings = $this->checkWith($fake)->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
        self::assertSame('composer.lock is present', $findings[0]->message);
    }

    public function testOutsideARepositoryTheCheckFallsBackToTheFileOnDisk(): void
    {
        $composerJson = ['require' => ['php' => '^8.3']];
        $appPath = $this->makeApp($composerJson, $this->contentHash($composerJson));
        $fake = new class implements ProcessRunnerInterface {
            public function run(array $command, string $cwd, int $timeout = 300, ?array $env = null): ProcessResult
            {
                return new ProcessResult(128, '', 'not a git repository');
            }
        };

        $findings = $this->checkWith($fake)->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
    }

    public function testAMissingLockNeverAsksGit(): void
    {
        $appPath = $this->makeRawApp(json_encode(['require' => ['php' => '^8.3']]), null);
        $fake = new class implements ProcessRunnerInterface {
            public int $calls = 0;

            public function run(array $command, string $cwd, int $timeout = 300, ?array $env = null): ProcessResult
            {
                $this->calls++;

                return new ProcessResult(0, '', '');
            }
        };

        $findings = $this->checkWith($fake)->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('composer.lock is missing', $findings[0]->message);
        self::assertSame('composer.lock', $findings[0]->path);
        self::assertSame(0, $fake->calls);
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
     * the relevant keys only, plus config.platform when set, ksorted and
     * md5-hashed as JSON.
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
        ksort($content);

        return md5(json_encode($content));
    }

    /**
     * The check under test with a fake runner and git pinned to "git", so
     * the tests never depend on git being on PATH.
     */
    private function checkWith(ProcessRunnerInterface $runner): LockCommittedCheck
    {
        return new LockCommittedCheck($runner, 'git');
    }

    /**
     * A fake runner that answers every git call with a non-zero exit, so
     * the check falls back to the file on disk no matter where the temp
     * dir sits.
     */
    private function silentGit(): ProcessRunnerInterface
    {
        return new class implements ProcessRunnerInterface {
            public function run(array $command, string $cwd, int $timeout = 300, ?array $env = null): ProcessResult
            {
                return new ProcessResult(128, '', 'not a git repository');
            }
        };
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
