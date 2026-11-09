<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\DisplayErrorsCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;

/**
 * Tests the DisplayErrorsCheck on a temp app dir, so the warning findings,
 * their rule ids and their lines are verified without touching the fixture
 * apps.
 */
final class DisplayErrorsCheckTest extends TestCase
{
    private string $appPath = '';

    protected function tearDown(): void
    {
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
            $this->appPath = '';
        }
    }

    public function testIniDisplayErrorsOnWarnsAndNamesTheFileAndLine(): void
    {
        $this->writeFile('php.ini', "[PHP]\ndisplay_errors = On\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/display-errors/on', $findings[0]->ruleId);
        self::assertSame('php.ini', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
        self::assertSame(
            'display_errors is On in php.ini:2; production must keep it Off and log_errors On (CREED runtime-and-headers)',
            $findings[0]->message,
        );
    }

    public function testIniDisplayErrorsOffPasses(): void
    {
        $this->writeFile('php.ini', "display_errors = Off\nlog_errors = On\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/display-errors', $findings[0]->ruleId);
        self::assertNull($findings[0]->path);
        self::assertSame('no committed php.ini, .user.ini or .htaccess turns display_errors on or log_errors off', $findings[0]->message);
    }

    public function testHtaccessPhpFlagDisplayErrorsOnUnderPublicWarns(): void
    {
        $this->writeFile('public/.htaccess', "<IfModule mod_php.c>\nphp_flag display_errors on\n</IfModule>\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/display-errors/on', $findings[0]->ruleId);
        self::assertSame('public/.htaccess', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
        self::assertSame(
            'display_errors is On in public/.htaccess:2; production must keep it Off and log_errors On (CREED runtime-and-headers)',
            $findings[0]->message,
        );
    }

    public function testIniLogErrorsOffWarns(): void
    {
        $this->writeFile('deploy/php.ini', "display_errors = Off\nlog_errors = Off\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/display-errors/log-off', $findings[0]->ruleId);
        self::assertSame('deploy/php.ini', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
        self::assertSame(
            'log_errors is Off in deploy/php.ini:2; production must keep it On (CREED runtime-and-headers)',
            $findings[0]->message,
        );
    }

    public function testADevNamedIniIsNotRead(): void
    {
        $this->writeFile('php.dev.ini', "display_errors = On\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/display-errors', $findings[0]->ruleId);
    }

    public function testACommentedOutLineIsIgnored(): void
    {
        $this->writeFile('php.ini', "; display_errors = On\n# log_errors = Off\ndisplay_errors = Off\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/display-errors', $findings[0]->ruleId);
    }

    public function testAFileUnderVendorIsNotRead(): void
    {
        $this->writeFile('vendor/pkg/php.ini', "display_errors = On\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/display-errors', $findings[0]->ruleId);
    }

    public function testAUserIniAtTheRootWarns(): void
    {
        $this->writeFile('.user.ini', "display_errors = 1\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/display-errors/on', $findings[0]->ruleId);
        self::assertSame('.user.ini', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    public function testAnHtaccessAtTheRootWarns(): void
    {
        $this->writeFile('.htaccess', "php_value display_errors 1\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/display-errors/on', $findings[0]->ruleId);
        self::assertSame('.htaccess', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    public function testNestedScanDirectoriesWarn(): void
    {
        foreach (['public/assets/.htaccess', 'deployments/conf/php.ini', 'docker/php.ini'] as $relative) {
            $this->freshApp();
            $this->writeFile($relative, $this->isHtaccess($relative) ? "php_flag display_errors on\n" : "display_errors = On\n");

            $findings = $this->runCheck();

            self::assertCount(1, $findings, $relative);
            self::assertSame('proctor/display-errors/on', $findings[0]->ruleId, $relative);
            self::assertSame($relative, $findings[0]->path, $relative);
        }
    }

    public function testTruthyValueVariantsWarn(): void
    {
        $variants = [
            'display_errors = On',
            'display_errors=ON',
            'display_errors = "on"',
            "display_errors = '1'",
            'display_errors = TRUE',
            'display_errors = yes',
            'display_errors = stdout',
            'display_errors = stderr',
        ];

        foreach ($variants as $line) {
            $this->freshApp();
            $this->writeFile('php.ini', $line . "\n");

            $findings = $this->runCheck();

            self::assertCount(1, $findings, $line);
            self::assertSame('proctor/display-errors/on', $findings[0]->ruleId, $line);
        }
    }

    public function testFalsyValuesAndLookalikeKeysPass(): void
    {
        $variants = [
            'display_errors = Off',
            'display_errors = 0',
            'display_errors = false',
            'display_errors = no',
            'display_errors =',
            'display_errors = onfoo',
            'DISPLAY_ERRORS = Off',
            'show_display_errors = On',
        ];

        foreach ($variants as $line) {
            $this->freshApp();
            $this->writeFile('php.ini', $line . "\n");

            $findings = $this->runCheck();

            self::assertCount(1, $findings, $line);
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status, $line);
        }
    }

    public function testHtaccessVariantsWarn(): void
    {
        $variants = [
            'php_flag display_errors on',
            'PHP_FLAG DISPLAY_ERRORS ON',
            'php_value display_errors 1',
            'Php_Value Display_Errors 1',
        ];

        foreach ($variants as $line) {
            $this->freshApp();
            $this->writeFile('.htaccess', $line . "\n");

            $findings = $this->runCheck();

            self::assertCount(1, $findings, $line);
            self::assertSame('proctor/display-errors/on', $findings[0]->ruleId, $line);
        }
    }

    public function testHtaccessOffValuesPass(): void
    {
        $variants = [
            'php_flag display_errors off',
            'php_value display_errors 0',
            'php_flag log_errors on',
            'php_value log_errors 1',
        ];

        foreach ($variants as $line) {
            $this->freshApp();
            $this->writeFile('.htaccess', $line . "\n");

            $findings = $this->runCheck();

            self::assertCount(1, $findings, $line);
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status, $line);
        }
    }

    public function testHtaccessLogErrorsOffWarns(): void
    {
        $this->writeFile('public/.htaccess', "php_flag log_errors off\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/display-errors/log-off', $findings[0]->ruleId);
        self::assertSame('public/.htaccess', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame(
            'log_errors is Off in public/.htaccess:1; production must keep it On (CREED runtime-and-headers)',
            $findings[0]->message,
        );
    }

    public function testOneWarningPerFilePerRuleUsesTheFirstMatchingLine(): void
    {
        $this->writeFile('php.ini', "display_errors = On\nlog_errors = Off\ndisplay_errors = 1\nlog_errors = 0\n");

        $findings = $this->runCheck();

        self::assertCount(2, $findings);
        self::assertSame('proctor/display-errors/on', $findings[0]->ruleId);
        self::assertSame(1, $findings[0]->line);
        self::assertSame('proctor/display-errors/log-off', $findings[1]->ruleId);
        self::assertSame(2, $findings[1]->line);
    }

    public function testAFileUnderTestsIsNotRead(): void
    {
        $this->writeFile('public/tests/fixtures/php.ini', "display_errors = On\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/display-errors', $findings[0]->ruleId);
    }

    public function testTheCheckIsNotInTheDeployGate(): void
    {
        self::assertNotContains('display-errors', CheckSet::GATE);
    }

    /**
     * Whether the relative path names an Apache .htaccess file.
     */
    private function isHtaccess(string $relative): bool
    {
        return str_ends_with($relative, '.htaccess');
    }

    /**
     * Removes the current temp app (if any) so the next write starts from an
     * empty directory.
     */
    private function freshApp(): void
    {
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
        }
        $this->appPath = '';
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
            $this->appPath = sys_get_temp_dir() . '/proctor-displayerrors-' . uniqid('', true);
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

        return (new DisplayErrorsCheck())->run($context);
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
