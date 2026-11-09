<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CrlfDeployScriptCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;

/**
 * Calls the crlf-deploy-script check directly, the way bin/proctor builds
 * its context: the fixture policy and a fixed --today. Every fixture is
 * written into a temp directory with real CR bytes, so git cannot normalise
 * it away, and the directory is removed after each test.
 */
final class CrlfDeployScriptCheckTest extends TestCase
{
    /** The fixed date every run uses. */
    private const TODAY = '2026-10-27';

    /** The temp app directories this test created, removed in tearDown(). */
    private array $appPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->appPaths as $appPath) {
            $this->removeDir($appPath);
        }
        $this->appPaths = [];
    }

    public function testTopLevelBuildScriptWithCrlfFailsOnTheFirstLine(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'build.sh', "#!/bin/sh\r\nset -e\r\n");
        $this->writeFile($appPath, '.gitattributes', "*.sh text eol=lf\n");

        $findings = (new CrlfDeployScriptCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame(
            'build.sh has Windows line endings (CRLF); bash on Linux reads the carriage return as part of each command. Convert it to LF and add "*.sh text eol=lf" to .gitattributes.',
            $findings[0]->message,
        );
        self::assertSame('build.sh', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    public function testCrlfOnALaterLineFailsOnThatLine(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'deployments/001-first.sh', "#!/bin/sh\nset -e\nrsync -a ./ /var/www/app/\r\n");
        $this->writeFile($appPath, '.gitattributes', "*.sh text eol=lf\n");

        $findings = (new CrlfDeployScriptCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame(
            'deployments/001-first.sh has Windows line endings (CRLF); bash on Linux reads the carriage return as part of each command. Convert it to LF and add "*.sh text eol=lf" to .gitattributes.',
            $findings[0]->message,
        );
        self::assertSame('deployments/001-first.sh', $findings[0]->path);
        self::assertSame(3, $findings[0]->line);
    }

    public function testLfDeployScriptPassesAndATopLevelReadmeIsNotScanned(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'scripts/release.sh', "#!/bin/sh\nset -e\n");
        $this->writeFile($appPath, 'README.md', "# App\r\nA readme with CRLF.\r\n");
        $this->writeFile($appPath, '.gitattributes', "*.sh text eol=lf\n");

        $findings = (new CrlfDeployScriptCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('No deploy script has Windows line endings.', $findings[0]->message);
    }

    public function testVendorAndNodeModulesAreSkipped(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'vendor/x/build.sh', "#!/bin/sh\r\n");
        $this->writeFile($appPath, 'node_modules/x/scripts/deploy.sh', "#!/bin/sh\r\n");

        $findings = (new CrlfDeployScriptCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('No deploy script has Windows line endings.', $findings[0]->message);
    }

    public function testEveryLineCrlfStillYieldsOneFindingPerFile(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'build.sh', "#!/bin/sh\r\nset -e\r\n");
        $this->writeFile($appPath, 'release.sh', "#!/bin/sh\r\necho done\r\n");
        $this->writeFile($appPath, '.gitattributes', "*.sh text eol=lf\n");

        $findings = (new CrlfDeployScriptCheck())->run($this->contextFor($appPath));

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('build.sh', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame(Finding::STATUS_FAIL, $findings[1]->status);
        self::assertSame('release.sh', $findings[1]->path);
        self::assertSame(1, $findings[1]->line);
    }

    public function testCleanDeployScriptWithoutGitattributesWarns(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'deploy/build.sh', "#!/bin/sh\nset -e\n");

        $findings = (new CrlfDeployScriptCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/crlf-deploy-script/no-gitattributes', $findings[0]->ruleId);
        self::assertSame(
            '.gitattributes does not pin deploy scripts to LF; add "*.sh text eol=lf" so a Windows checkout cannot bring CRLF back',
            $findings[0]->message,
        );
        self::assertSame('.gitattributes', $findings[0]->path);
    }

    public function testGitattributesPinningShFilesPasses(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'deploy/build.sh', "#!/bin/sh\nset -e\n");
        $this->writeFile($appPath, '.gitattributes', "*.sh text eol=lf\n");

        $findings = (new CrlfDeployScriptCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('No deploy script has Windows line endings.', $findings[0]->message);
    }

    public function testGitattributesPinningEveryFilePasses(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'deploy/build.sh', "#!/bin/sh\nset -e\n");
        $this->writeFile($appPath, '.gitattributes', "* text=auto eol=lf\n");

        $findings = (new CrlfDeployScriptCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('No deploy script has Windows line endings.', $findings[0]->message);
    }

    public function testCommentedOutGitattributesLineWarns(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'deploy/build.sh', "#!/bin/sh\nset -e\n");
        $this->writeFile($appPath, '.gitattributes', "# *.sh text eol=lf\n");

        $findings = (new CrlfDeployScriptCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/crlf-deploy-script/no-gitattributes', $findings[0]->ruleId);
        self::assertSame(
            '.gitattributes does not pin deploy scripts to LF; add "*.sh text eol=lf" so a Windows checkout cannot bring CRLF back',
            $findings[0]->message,
        );
        self::assertSame('.gitattributes', $findings[0]->path);
    }

    public function testNoDeployScriptAndNoGitattributesPasses(): void
    {
        $appPath = $this->makeApp();

        $findings = (new CrlfDeployScriptCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('No deploy script has Windows line endings.', $findings[0]->message);
    }

    public function testCrlfBuildScriptWithoutGitattributesFailsThenWarns(): void
    {
        $appPath = $this->makeApp();
        $this->writeFile($appPath, 'build.sh', "#!/bin/sh\r\nset -e\r\n");

        $findings = (new CrlfDeployScriptCheck())->run($this->contextFor($appPath));

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('build.sh', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame(Finding::STATUS_WARNING, $findings[1]->status);
        self::assertSame('proctor/crlf-deploy-script/no-gitattributes', $findings[1]->ruleId);
        self::assertSame(
            '.gitattributes does not pin deploy scripts to LF; add "*.sh text eol=lf" so a Windows checkout cannot bring CRLF back',
            $findings[1]->message,
        );
        self::assertSame('.gitattributes', $findings[1]->path);
    }

    /**
     * A fresh empty app directory in the system temp folder, tracked for
     * removal in tearDown().
     */
    private function makeApp(): string
    {
        $appPath = sys_get_temp_dir() . '/proctor-crlf-' . uniqid('', true);
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
