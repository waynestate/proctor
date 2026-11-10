<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\LockTrackedCheck;
use Waynestate\Proctor\Check\ProcessResult;
use Waynestate\Proctor\Check\ProcessRunnerInterface;
use Waynestate\Proctor\Creed\Policy;

/**
 * Calls the lock-tracked check directly on temporary app directories with a
 * fake runner and git pinned to "git", so the cases never depend on git
 * being on PATH or on where the temp dir sits.
 */
final class LockTrackedCheckTest extends TestCase
{
    /** The fixed date every run uses. */
    private const TODAY = '2026-10-27';

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

    public function testAnUntrackedLockAddsTheWarning(): void
    {
        $appPath = $this->makeApp();
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

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertNull($findings[0]->rule);
        self::assertSame('proctor/lock-tracked', $findings[0]->ruleId);
        self::assertSame(
            'composer.lock is on disk but git does not track it; commit it (git add composer.lock) so the release installs the tree you tested',
            $findings[0]->message,
        );
        self::assertSame('composer.lock', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame([['git', '-C', $appPath, 'ls-files', '-z', '--', 'composer.lock']], $fake->commands);
    }

    public function testATrackedLockGivesThePassFinding(): void
    {
        $appPath = $this->makeApp();
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
        self::assertSame('proctor/lock-tracked', $findings[0]->ruleId);
        self::assertSame('composer.lock is tracked by git', $findings[0]->message);
        self::assertSame('composer.lock', $findings[0]->path);
    }

    public function testOutsideARepositoryTheCheckSaysNothing(): void
    {
        $appPath = $this->makeApp();
        $fake = new class implements ProcessRunnerInterface {
            public function run(array $command, string $cwd, int $timeout = 300, ?array $env = null): ProcessResult
            {
                return new ProcessResult(128, '', 'not a git repository');
            }
        };

        $findings = $this->checkWith($fake)->run($this->contextFor($appPath));

        self::assertSame([], $findings);
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

        self::assertSame([], $findings);
        self::assertSame(0, $fake->calls);
    }

    /**
     * Builds a temporary app directory with a composer.json and a
     * composer.lock, and returns its path.
     */
    private function makeApp(): string
    {
        $appPath = sys_get_temp_dir() . '/proctor-lock-tracked-' . uniqid('', true);
        self::assertTrue(mkdir($appPath, 0777, true), sprintf('could not create temp dir "%s"', $appPath));
        $this->appDirs[] = $appPath;

        file_put_contents($appPath . '/composer.json', json_encode(['require' => ['php' => '^8.3']]));
        file_put_contents($appPath . '/composer.lock', json_encode([
            'packages' => [],
            'packages-dev' => [],
        ]));

        return $appPath;
    }

    /**
     * Builds a temporary app directory from raw file contents; either may be
     * null to leave the file out, and the path is returned.
     */
    private function makeRawApp(?string $composerJsonRaw, ?string $lockRaw): string
    {
        $appPath = sys_get_temp_dir() . '/proctor-lock-tracked-' . uniqid('', true);
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
     * The check under test with a fake runner and git pinned to "git", so
     * the tests never depend on git being on PATH.
     */
    private function checkWith(ProcessRunnerInterface $runner): LockTrackedCheck
    {
        return new LockTrackedCheck($runner, 'git');
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
