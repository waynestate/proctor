<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\EntrypointMigrateCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;

/**
 * Tests the EntrypointMigrateCheck on a temp app dir, so the warning
 * findings, their rule ids and their lines are verified without touching the
 * fixture apps.
 */
final class EntrypointMigrateCheckTest extends TestCase
{
    private string $appPath = '';

    protected function tearDown(): void
    {
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
            $this->appPath = '';
        }
    }

    public function testATopLevelMigrateWarnsAndNamesTheFileAndLine(): void
    {
        $this->writeFile('entrypoint.sh', "#!/bin/sh\nset -e\nphp artisan migrate --force\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/entrypoint-migrate/runs', $findings[0]->ruleId);
        self::assertSame('entrypoint.sh', $findings[0]->path);
        self::assertSame(3, $findings[0]->line);
        self::assertSame(
            'entrypoint runs migrations at container start (entrypoint.sh:3); run them once per release as a job',
            $findings[0]->message,
        );
    }

    public function testAMigrateCaseBranchPasses(): void
    {
        $this->writeFile(
            'docker/app-entrypoint.sh',
            "#!/bin/sh\nset -e\ncase \"$1\" in\n  migrate)\n    php artisan migrate --force\n    ;;\n  *)\n    exec \"$@\"\n    ;;\nesac\n",
        );

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/entrypoint-migrate', $findings[0]->ruleId);
        self::assertSame('no container entrypoint runs migrations at start', $findings[0]->message);
    }

    public function testMigrateSubcommandsPass(): void
    {
        $this->writeFile('entrypoint.sh', "#!/bin/sh\nphp artisan migrate:status\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/entrypoint-migrate', $findings[0]->ruleId);
    }

    public function testACommentedOutMigratePasses(): void
    {
        $this->writeFile('entrypoint.sh', "#!/bin/sh\n# php artisan migrate --force\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/entrypoint-migrate', $findings[0]->ruleId);
    }

    public function testAFileWithoutEntrypointInItsNameIsNotRead(): void
    {
        $this->writeFile('start.sh', "#!/bin/sh\nphp artisan migrate --force\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/entrypoint-migrate', $findings[0]->ruleId);
    }

    public function testTwoEntrypointsThatBothMigrateWarnForEachSortedByPath(): void
    {
        $this->writeFile('docker/app-entrypoint.sh', "#!/bin/sh\nphp artisan migrate --force\n");
        $this->writeFile('entrypoint.sh', "#!/bin/sh\nphp artisan migrate --force\n");

        $findings = $this->runCheck();

        self::assertCount(2, $findings);
        self::assertSame('docker/app-entrypoint.sh', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
        self::assertSame('proctor/entrypoint-migrate/runs', $findings[0]->ruleId);
        self::assertSame('entrypoint.sh', $findings[1]->path);
        self::assertSame(2, $findings[1]->line);
        self::assertSame('proctor/entrypoint-migrate/runs', $findings[1]->ruleId);
    }

    public function testAnEntrypointUnderVendorIsNotRead(): void
    {
        $this->writeFile('vendor/entrypoint.sh', "#!/bin/sh\nphp artisan migrate --force\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/entrypoint-migrate', $findings[0]->ruleId);
    }

    public function testTheCheckIsNotInTheDeployGate(): void
    {
        self::assertNotContains('entrypoint-migrate', CheckSet::GATE);
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
            $this->appPath = sys_get_temp_dir() . '/proctor-entrypoint-' . uniqid('', true);
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

        return (new EntrypointMigrateCheck())->run($context);
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
