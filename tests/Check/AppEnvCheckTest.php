<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\AppEnvCheck;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;

/**
 * Tests the AppEnvCheck on a temp app dir, so the warning findings, their
 * rule ids and their lines are verified without touching the fixture apps.
 */
final class AppEnvCheckTest extends TestCase
{
    private string $appPath = '';

    protected function tearDown(): void
    {
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
            $this->appPath = '';
        }
    }

    public function test_local_in_squire_env_template_warns(): void
    {
        $this->writeFile('.env.squire.example', "APP_NAME=Acme\nAPP_ENV=local\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/app-env/not-production', $findings[0]->ruleId);
        self::assertSame('.env.squire.example', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
        self::assertSame(
            "APP_ENV is 'local' in .env.squire.example; deployed environments must set APP_ENV=production (D-133)",
            $findings[0]->message,
        );
    }

    public function test_staging_in_deploy_file_warns(): void
    {
        $this->writeFile('deploy/config.ini', "[app]\nAPP_ENV=staging\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/app-env/not-production', $findings[0]->ruleId);
        self::assertSame('deploy/config.ini', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
        self::assertSame(
            "APP_ENV is 'staging' in deploy/config.ini; deployed environments must set APP_ENV=production (D-133)",
            $findings[0]->message,
        );
    }

    public function test_staging_in_gitlab_ci_warns(): void
    {
        $this->writeFile('.gitlab-ci.yml', "stages:\n  - build\n\nvariables:\n  APP_ENV: \"staging\"\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/app-env/not-production', $findings[0]->ruleId);
        self::assertSame('.gitlab-ci.yml', $findings[0]->path);
        self::assertSame(5, $findings[0]->line);
        self::assertSame(
            "APP_ENV is 'staging' in .gitlab-ci.yml; deployed environments must set APP_ENV=production (D-133)",
            $findings[0]->message,
        );
    }

    public function test_production_passes(): void
    {
        $this->writeFile('.env.production', "APP_ENV=production\n");
        $this->writeFile('deploy/config.ini', "APP_ENV=Production\n");
        $this->writeFile('.gitlab-ci.yml', "variables:\n  APP_ENV: \"production\"\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/app-env', $findings[0]->ruleId);
        self::assertNull($findings[0]->path);
        self::assertSame('no env, deploy or CI file sets APP_ENV to anything but production', $findings[0]->message);
    }

    public function test_env_example_is_ignored(): void
    {
        $this->writeFile('.env.example', "APP_ENV=local\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/app-env', $findings[0]->ruleId);
        self::assertSame('no env, deploy or CI file sets APP_ENV to anything but production', $findings[0]->message);
    }

    public function test_variable_reference_is_not_flagged(): void
    {
        $this->writeFile('.env.production', "APP_ENV=\${APP_ENV}\n");
        $this->writeFile('deploy/release.sh', "#!/bin/sh\nset -e\nexport APP_ENV=\$APP_ENV\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/app-env', $findings[0]->ruleId);
    }

    public function test_comment_line_is_skipped(): void
    {
        $this->writeFile('.env.production', "# APP_ENV=staging\nAPP_NAME=Acme\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/app-env', $findings[0]->ruleId);
    }

    public function test_vendor_is_skipped(): void
    {
        $this->writeFile('deploy/vendor/tool/settings.ini', "APP_ENV=staging\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/app-env', $findings[0]->ruleId);
    }

    public function test_one_finding_per_file_on_first_line(): void
    {
        $this->writeFile('.env.production', "APP_ENV=staging\nFOO=bar\nAPP_ENV=local\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/app-env/not-production', $findings[0]->ruleId);
        self::assertSame('.env.production', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame(
            "APP_ENV is 'staging' in .env.production; deployed environments must set APP_ENV=production (D-133)",
            $findings[0]->message,
        );
    }

    private function freshApp(): void
    {
        $this->appPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('proctor-appenv-', true);
        mkdir($this->appPath, 0777, true);
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        if ($this->appPath === '') {
            $this->freshApp();
        }
        $path = $this->appPath . '/' . $relativePath;
        $parent = dirname($path);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function appDir(): string
    {
        if ($this->appPath === '') {
            $this->freshApp();
        }

        return $this->appPath;
    }

    /**
     * Runs the AppEnvCheck against the temp app dir.
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

        return (new AppEnvCheck())->run($context);
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
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
}
