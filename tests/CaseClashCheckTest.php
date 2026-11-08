<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Proctor\Check\CaseClashCheck;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;

/**
 * Calls the case-clash check directly, the way bin/proctor builds its
 * context: the fixture policy and a fixed --today. Every fixture is written
 * into a temp directory, and the directory is removed after each test. On a
 * case-insensitive temp folder a clash cannot be created at all, so every
 * test skips there.
 */
final class CaseClashCheckTest extends TestCase
{
    /** The fixed date every run uses. */
    private const TODAY = '2026-10-27';

    /** The temp app directories this test created, removed in tearDown(). */
    private array $appPaths = [];

    protected function setUp(): void
    {
        // A file named probe exists as PROBE on a case-insensitive file
        // system, where README.md and readme.md cannot both exist either.
        $suffix = bin2hex(random_bytes(6));
        $lower = sys_get_temp_dir() . '/proctor-case-probe-' . $suffix;
        $upper = sys_get_temp_dir() . '/PROCTOR-CASE-PROBE-' . strtoupper($suffix);
        self::assertNotFalse(touch($lower));
        if (file_exists($upper)) {
            unlink($lower);
            $this->markTestSkipped('The temp folder ignores letter case, so a clash cannot be created here.');
        }
        unlink($lower);
    }

    protected function tearDown(): void
    {
        foreach ($this->appPaths as $appPath) {
            $this->removeDir($appPath);
        }
        $this->appPaths = [];
    }

    public function testTwoFilesThatDifferOnlyByCaseFail(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'README.md', "# App\n");
        $this->writeFile($appPath, 'readme.md', "# App\n");

        $findings = (new CaseClashCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame(
            'README.md and readme.md differ only by letter case; on macOS and Windows one silently replaces the other. Rename all but one.',
            $findings[0]->message,
        );
        self::assertSame('README.md', $findings[0]->path);
    }

    public function testDirectoryClashIsReportedOnceNotPerFile(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'Docs/a.md', "a\n");
        $this->writeFile($appPath, 'docs/a.md', "a\n");
        $this->writeFile($appPath, 'docs/b.md', "b\n");

        $findings = (new CaseClashCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame(
            'Docs and docs differ only by letter case; on macOS and Windows one silently replaces the other. Rename all but one.',
            $findings[0]->message,
        );
        self::assertSame('Docs', $findings[0]->path);
    }

    public function testThreeWayClashIsOneFinding(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'app/User.php', "<?php\n");
        $this->writeFile($appPath, 'app/user.php', "<?php\n");
        $this->writeFile($appPath, 'app/USER.php', "<?php\n");

        $findings = (new CaseClashCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertStringStartsWith(
            'app/USER.php and app/User.php and app/user.php differ',
            $findings[0]->message,
        );
        self::assertSame('app/USER.php', $findings[0]->path);
    }

    public function testVendorAndNodeModulesAreSkipped(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'vendor/x/Package.php', "<?php\n");
        $this->writeFile($appPath, 'vendor/x/package.php', "<?php\n");
        $this->writeFile($appPath, 'node_modules/pkg/Index.js', "// js\n");
        $this->writeFile($appPath, 'node_modules/pkg/index.js', "// js\n");

        $findings = (new CaseClashCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('No two paths differ only by letter case.', $findings[0]->message);
    }

    public function testDistinctNamesPass(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'a.md', "a\n");
        $this->writeFile($appPath, 'b.md', "b\n");
        $this->writeFile($appPath, 'src/A.php', "<?php\n");

        $findings = (new CaseClashCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('No two paths differ only by letter case.', $findings[0]->message);
    }

    /**
     * A fresh empty app directory in the system temp folder, tracked for
     * removal in tearDown().
     */
    private function makeApp(): string
    {
        $appPath = sys_get_temp_dir() . '/proctor-caseclash-' . uniqid('', true);
        self::assertTrue(is_dir($appPath) || mkdir($appPath, 0777, true));
        $this->appPaths[] = $appPath;

        return $appPath;
    }

    /**
     * Writes a file inside the app, creating parent directories as needed.
     */
    private function writeFile(string $appPath, string $relativePath, string $contents): void
    {
        $absolutePath = $appPath . '/' . $relativePath;
        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            self::assertTrue(mkdir($dir, 0777, true));
        }
        self::assertNotFalse(file_put_contents($absolutePath, $contents));
    }

    /**
     * Removes a directory tree.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }

    /**
     * @param string $appPath the app to check
     */
    private function contextFor(string $appPath): CheckContext
    {
        return new CheckContext(
            $appPath,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY),
        );
    }
}
