<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\NpmLockCommittedCheck;
use Waynestate\Proctor\Creed\Policy;

/**
 * Calls the npm-lock-committed check directly on temporary app directories,
 * so the manifest and lock cases can be built around real package.json
 * files.
 */
final class NpmLockCommittedCheckTest extends TestCase
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

    public function testAnAppWithoutAPackageJsonSaysNothing(): void
    {
        $appPath = $this->makeRawApp(null, null);

        $findings = (new NpmLockCommittedCheck())->run($this->contextFor($appPath));

        self::assertSame([], $findings);
    }

    public function testAPackageJsonWithoutDependencyMapsSaysNothing(): void
    {
        $appPath = $this->makeRawApp(json_encode(['name' => 'acme/app', 'private' => true]), null);

        $findings = (new NpmLockCommittedCheck())->run($this->contextFor($appPath));

        self::assertSame([], $findings);
    }

    public function testEmptyDependencyMapsSayNothing(): void
    {
        $appPath = $this->makeRawApp(json_encode([
            'name' => 'acme/app',
            'dependencies' => [],
            'devDependencies' => [],
            'optionalDependencies' => [],
        ]), null);

        $findings = (new NpmLockCommittedCheck())->run($this->contextFor($appPath));

        self::assertSame([], $findings);
    }

    public function testDependencyMapsThatAreNotObjectsSayNothing(): void
    {
        $appPath = $this->makeRawApp(json_encode([
            'dependencies' => 'vue',
            'devDependencies' => 3,
            'optionalDependencies' => null,
        ]), null);

        $findings = (new NpmLockCommittedCheck())->run($this->contextFor($appPath));

        self::assertSame([], $findings);
    }

    public function testADependencyWithoutALockWarns(): void
    {
        $appPath = $this->makeRawApp(json_encode(['dependencies' => ['vue' => '^3.0.0']]), null);

        $findings = (new NpmLockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertNull($findings[0]->rule);
        self::assertSame('proctor/npm-lock-committed', $findings[0]->ruleId);
        self::assertSame(
            'package.json declares dependencies but package-lock.json is missing; run npm install and commit the lock so `npm ci` can install it',
            $findings[0]->message,
        );
        self::assertSame('package-lock.json', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    public function testADevDependencyWithoutALockWarns(): void
    {
        $appPath = $this->makeRawApp(json_encode(['devDependencies' => ['eslint' => '^9.0.0']]), null);

        $findings = (new NpmLockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/npm-lock-committed', $findings[0]->ruleId);
        self::assertSame('package-lock.json', $findings[0]->path);
    }

    public function testAnOptionalDependencyWithoutALockWarns(): void
    {
        $appPath = $this->makeRawApp(json_encode(['optionalDependencies' => ['fsevents' => '^2.3.0']]), null);

        $findings = (new NpmLockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/npm-lock-committed', $findings[0]->ruleId);
        self::assertSame('package-lock.json', $findings[0]->path);
    }

    public function testAValidLockPasses(): void
    {
        $appPath = $this->makeRawApp(
            json_encode(['dependencies' => ['vue' => '^3.0.0']]),
            json_encode(['name' => 'acme/app', 'lockfileVersion' => 3, 'packages' => []]),
        );

        $findings = (new NpmLockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
        self::assertSame('proctor/npm-lock-committed', $findings[0]->ruleId);
        self::assertSame('package-lock.json is present', $findings[0]->message);
        self::assertSame('package-lock.json', $findings[0]->path);
    }

    public function testAValidLockThatIsNotAnObjectPasses(): void
    {
        // Valid JSON is valid JSON: the check grades the parse, not the shape.
        $appPath = $this->makeRawApp(
            json_encode(['dependencies' => ['vue' => '^3.0.0']]),
            '"just a string"',
        );

        $findings = (new NpmLockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('package-lock.json is present', $findings[0]->message);
    }

    public function testAnInvalidLockWarns(): void
    {
        $appPath = $this->makeRawApp(
            json_encode(['dependencies' => ['vue' => '^3.0.0']]),
            'not json at all',
        );

        $findings = (new NpmLockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertNull($findings[0]->rule);
        self::assertSame('proctor/npm-lock-committed', $findings[0]->ruleId);
        self::assertSame(
            'package.json declares dependencies but package-lock.json is not valid JSON; run npm install to regenerate it and commit the lock',
            $findings[0]->message,
        );
        self::assertSame('package-lock.json', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    public function testAnEmptyLockWarns(): void
    {
        $appPath = $this->makeRawApp(
            json_encode(['dependencies' => ['vue' => '^3.0.0']]),
            '',
        );

        $findings = (new NpmLockCommittedCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/npm-lock-committed', $findings[0]->ruleId);
    }

    public function testAnUnparsablePackageJsonSaysNothing(): void
    {
        $appPath = $this->makeRawApp('not json at all', json_encode(['lockfileVersion' => 3]));

        $findings = (new NpmLockCommittedCheck())->run($this->contextFor($appPath));

        self::assertSame([], $findings);
    }

    public function testAPackageJsonThatIsNotAnObjectSaysNothing(): void
    {
        $appPath = $this->makeRawApp('"just a string"', null);

        $findings = (new NpmLockCommittedCheck())->run($this->contextFor($appPath));

        self::assertSame([], $findings);
    }

    /**
     * Builds a temporary app directory from raw file contents; either may be
     * null to leave the file out, and the path is returned.
     */
    private function makeRawApp(?string $packageJsonRaw, ?string $lockRaw): string
    {
        $appPath = sys_get_temp_dir() . '/proctor-npm-lock-committed-' . uniqid('', true);
        self::assertTrue(mkdir($appPath, 0777, true), sprintf('could not create temp dir "%s"', $appPath));
        $this->appDirs[] = $appPath;

        if ($packageJsonRaw !== null) {
            file_put_contents($appPath . '/package.json', $packageJsonRaw);
        }
        if ($lockRaw !== null) {
            file_put_contents($appPath . '/package-lock.json', $lockRaw);
        }

        return $appPath;
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
