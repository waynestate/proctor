<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\ScriptPairCheck;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Policy\PolicyResolver;

/**
 * Tests the script-pair check on a temp app dir. Every fixture is written at
 * run time and the dir is removed after each test.
 */
final class ScriptPairCheckTest extends TestCase
{
    private const PASS = 'Every developer script in scripts/ ships as a .ps1 and .sh pair.';

    private string $appPath = '';

    protected function tearDown(): void
    {
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
            $this->appPath = '';
        }
    }

    public function testAnAppWithNoScriptsDirectoryPasses(): void
    {
        $this->writeFile('public/index.php', "<?php\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/script-pair', $findings[0]->ruleId);
        self::assertSame(self::PASS, $findings[0]->message);
    }

    public function testAPairedScriptPasses(): void
    {
        $this->writeFile('scripts/a.sh', "#!/bin/sh\necho a\n");
        $this->writeFile('scripts/a.ps1', "Write-Output 'a'\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/script-pair', $findings[0]->ruleId);
        self::assertSame(self::PASS, $findings[0]->message);
    }

    public function testAShWithoutItsPs1TwinWarns(): void
    {
        $this->writeFile('scripts/a.sh', "#!/bin/sh\necho a\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/script-pair/missing-ps1', $findings[0]->ruleId);
        self::assertSame('scripts/a.sh', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame(
            'scripts/a.sh has no scripts/a.ps1 twin; a developer script ships as a .ps1 and .sh pair, or as one PHP command (CREED every-os)',
            $findings[0]->message,
        );
    }

    public function testAPs1WithoutItsShTwinWarns(): void
    {
        $this->writeFile('scripts/b.ps1', "Write-Output 'b'\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/script-pair/missing-sh', $findings[0]->ruleId);
        self::assertSame('scripts/b.ps1', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame(
            'scripts/b.ps1 has no scripts/b.sh twin; a developer script ships as a .ps1 and .sh pair, or as one PHP command (CREED every-os)',
            $findings[0]->message,
        );
    }

    public function testATwinInAnotherFolderDoesNotCount(): void
    {
        $this->writeFile('scripts/c.sh', "#!/bin/sh\necho c\n");
        $this->writeFile('scripts/c.ps1', "Write-Output 'c'\n");
        $this->writeFile('scripts/sub/c.sh', "#!/bin/sh\necho sub\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/script-pair/missing-ps1', $findings[0]->ruleId);
        self::assertSame('scripts/sub/c.sh', $findings[0]->path);
        self::assertSame(
            'scripts/sub/c.sh has no scripts/sub/c.ps1 twin; a developer script ships as a .ps1 and .sh pair, or as one PHP command (CREED every-os)',
            $findings[0]->message,
        );
    }

    public function testScriptsOutsideTheScriptsDirectoryAreNotScanned(): void
    {
        $this->writeFile('deploy/release.sh', "#!/bin/sh\ntrue\n");
        $this->writeFile('bin/run.sh', "#!/bin/sh\ntrue\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame(self::PASS, $findings[0]->message);
    }

    public function testVendorAndNodeModulesAreSkippedAtAnyDepth(): void
    {
        $this->writeFile('scripts/node_modules/x.sh', "#!/bin/sh\ntrue\n");
        $this->writeFile('scripts/vendor/y.sh', "#!/bin/sh\ntrue\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame(self::PASS, $findings[0]->message);
    }

    public function testNonScriptFilesAreIgnored(): void
    {
        $this->writeFile('scripts/README.md', "# Scripts\n");
        $this->writeFile('scripts/build.mjs', "console.log('build');\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame(self::PASS, $findings[0]->message);
    }

    public function testExtensionsAreCaseSensitive(): void
    {
        $this->writeFile('scripts/UPPER.SH', "#!/bin/sh\ntrue\n");
        $this->writeFile('scripts/upper.PS1', "Write-Output 'upper'\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame(self::PASS, $findings[0]->message);
    }

    public function testFindingsAreSortedByPath(): void
    {
        $this->writeFile('scripts/b.sh', "#!/bin/sh\ntrue\n");
        $this->writeFile('scripts/a.ps1', "Write-Output 'a'\n");

        $findings = $this->runCheck();

        self::assertCount(2, $findings);
        self::assertSame('proctor/script-pair/missing-sh', $findings[0]->ruleId);
        self::assertSame('scripts/a.ps1', $findings[0]->path);
        self::assertSame('proctor/script-pair/missing-ps1', $findings[1]->ruleId);
        self::assertSame('scripts/b.sh', $findings[1]->path);
    }

    public function testRegisteredAfterGitattributesEolAndNotInTheDeployGate(): void
    {
        $ids = CheckSet::ids(CheckSet::all(PolicyResolver::resolve(null, dirname(__DIR__, 2))));

        self::assertContains('script-pair', $ids);
        self::assertSame(array_search('gitattributes-eol', $ids, true) + 1, array_search('script-pair', $ids, true));
        self::assertNotContains('script-pair', CheckSet::GATE);
    }

    /**
     * Writes a file into the temp app dir (created on first use), making any
     * missing parent directory along the way.
     */
    private function writeFile(string $relative, string $content): void
    {
        $this->appDir();
        $absolute = $this->appPath . '/' . $relative;
        $parent = dirname($absolute);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($absolute, $content);
    }

    /**
     * The temp app dir, created on first use and remembered for cleanup.
     */
    private function appDir(): string
    {
        if ($this->appPath === '') {
            $this->appPath = sys_get_temp_dir() . '/proctor-script-pair-' . uniqid('', true);
            self::assertTrue(is_dir($this->appPath) || mkdir($this->appPath, 0777, true));
        }

        return $this->appPath;
    }

    /**
     * Runs the check on the temp app and returns its findings.
     *
     * @return list<Finding>
     */
    private function runCheck(): array
    {
        $context = new CheckContext(
            $this->appDir(),
            Policy::fromFile(__DIR__ . '/../fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
        );

        return (new ScriptPairCheck())->run($context);
    }

    /**
     * Removes a file or directory tree.
     */
    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
