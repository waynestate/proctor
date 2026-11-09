<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\DirectoryListingCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Policy\PolicyResolver;

/**
 * Tests the DirectoryListingCheck on a temp app dir, so the warning
 * findings, their rule ids and their lines are verified without touching
 * the fixture apps.
 */
final class DirectoryListingCheckTest extends TestCase
{
    private string $appPath = '';

    protected function tearDown(): void
    {
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
            $this->appPath = '';
        }
    }

    public function testOptionsPlusIndexesWarnsAndNamesTheFileAndLine(): void
    {
        $this->writeFile('public/.htaccess', "RewriteEngine On\nOptions +Indexes\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/directory-listing/indexes', $findings[0]->ruleId);
        self::assertSame('public/.htaccess', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
        self::assertSame(
            'public/.htaccess:2 turns on directory listing; the web server must not list directory contents (CREED production)',
            $findings[0]->message,
        );
    }

    public function testOptionsAllWarns(): void
    {
        $this->writeFile('.htaccess', "Options All\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/directory-listing/indexes', $findings[0]->ruleId);
        self::assertSame('.htaccess', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    public function testOptionsIndexesFollowSymLinksWarns(): void
    {
        $this->writeFile('deploy/public/.htaccess', "Options Indexes FollowSymLinks\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/directory-listing/indexes', $findings[0]->ruleId);
        self::assertSame('deploy/public/.htaccess', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    public function testNginxAutoindexOnInADockerConfWarns(): void
    {
        $this->writeFile('docker/nginx.conf', "server {\n    autoindex on;\n}\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/directory-listing/indexes', $findings[0]->ruleId);
        self::assertSame('docker/nginx.conf', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
        self::assertSame(
            'docker/nginx.conf:2 turns on directory listing; the web server must not list directory contents (CREED production)',
            $findings[0]->message,
        );
    }

    public function testOptionsMinusIndexesPasses(): void
    {
        $this->writeFile('public/.htaccess', "Options -Indexes\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/directory-listing', $findings[0]->ruleId);
        self::assertNull($findings[0]->path);
        self::assertSame('no committed .htaccess or server config turns on directory listing', $findings[0]->message);
    }

    public function testOptionsFollowSymLinksPasses(): void
    {
        $this->writeFile('deploy/vhost.conf', "<Directory /var/www>\n    Options FollowSymLinks\n</Directory>\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/directory-listing', $findings[0]->ruleId);
    }

    public function testACommentedOutLineIsIgnored(): void
    {
        $this->writeFile('.htaccess', "# Options +Indexes\nOptions -Indexes\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/directory-listing', $findings[0]->ruleId);
    }

    public function testAFileUnderVendorIsNotRead(): void
    {
        $this->writeFile('vendor/pkg/.htaccess', "Options +Indexes\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/directory-listing', $findings[0]->ruleId);
    }

    public function testAnAllWithAMinusIndexesAfterItPasses(): void
    {
        $this->writeFile('public/.htaccess', "Options All -Indexes\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/directory-listing', $findings[0]->ruleId);
    }

    public function testAnIndexesAfterAMinusIndexesStillWarns(): void
    {
        $this->writeFile('public/.htaccess', "Options -Indexes +Indexes\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/directory-listing/indexes', $findings[0]->ruleId);
        self::assertSame('public/.htaccess', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    public function testAnAllAfterAMinusIndexesWarns(): void
    {
        $this->writeFile('public/.htaccess', "Options -Indexes All\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/directory-listing/indexes', $findings[0]->ruleId);
        self::assertSame(1, $findings[0]->line);
    }

    public function testAutoindexVariantsWarn(): void
    {
        $variants = [
            'autoindex on;',
            'autoindex on ;',
            'AUTOINDEX ON;',
            '  AutoIndex  On ;',
        ];

        foreach ($variants as $line) {
            $this->freshApp();
            $this->writeFile('docker/nginx.conf', $line . "\n");

            $findings = $this->runCheck();

            self::assertCount(1, $findings, $line);
            self::assertSame('proctor/directory-listing/indexes', $findings[0]->ruleId, $line);
        }
    }

    public function testAutoindexOffPasses(): void
    {
        $this->writeFile('deploy/nginx.conf', "autoindex off;\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/directory-listing', $findings[0]->ruleId);
    }

    public function testANestedConfUnderDeploymentsWarns(): void
    {
        $this->writeFile('deployments/conf.d/default.conf', "location / {\n    autoindex on;\n}\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/directory-listing/indexes', $findings[0]->ruleId);
        self::assertSame('deployments/conf.d/default.conf', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
    }

    public function testAConfAtTheRootOrUnderPublicIsNotRead(): void
    {
        $this->writeFile('nginx.conf', "autoindex on;\n");
        $this->writeFile('public/app.conf', "autoindex on;\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/directory-listing', $findings[0]->ruleId);
    }

    public function testOneWarningPerFileUsesTheFirstMatchingLine(): void
    {
        $this->writeFile('docker/nginx.conf', "autoindex off;\nautoindex on;\nautoindex on;\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/directory-listing/indexes', $findings[0]->ruleId);
        self::assertSame(2, $findings[0]->line);
    }

    public function testTheProctorRepoItselfPasses(): void
    {
        $context = new CheckContext(
            dirname(__DIR__, 2),
            Policy::fromFile(__DIR__ . '/../fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
        );

        $findings = (new DirectoryListingCheck())->run($context);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/directory-listing', $findings[0]->ruleId);
    }

    public function testRegisteredAndNotInTheDeployGate(): void
    {
        $ids = CheckSet::ids(CheckSet::all(PolicyResolver::resolve(null, dirname(__DIR__, 2))));

        self::assertContains('directory-listing', $ids);
        self::assertNotContains('directory-listing', CheckSet::GATE);
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
            $this->appPath = sys_get_temp_dir() . '/proctor-directorylisting-' . uniqid('', true);
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

        return (new DirectoryListingCheck())->run($context);
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
