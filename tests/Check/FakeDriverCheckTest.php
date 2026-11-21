<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\FakeDriverCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;

/**
 * Tests the FakeDriverCheck on a temp app dir, so the failure findings,
 * their rule ids and their lines are verified without touching the fixture
 * apps.
 */
final class FakeDriverCheckTest extends TestCase
{
    private string $appPath = '';

    protected function tearDown(): void
    {
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
            $this->appPath = '';
        }
    }

    public function test_fake_driver_in_squire_env_template_fails(): void
    {
        $this->writeFile('.env.squire.example', "APP_NAME=Acme\nSESAME_DRIVER=fake\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('proctor/fake-driver/enabled', $findings[0]->ruleId);
        self::assertSame('.env.squire.example', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
        self::assertSame(
            'the SESAME fake driver is allowed in .env.squire.example:2: it signs anyone in without an identity provider; a production config must use cas (or another real driver) and must never set SESAME_FAKE_ALLOWED.',
            $findings[0]->message,
        );
    }

    public function test_fake_allowed_in_deploy_file_fails(): void
    {
        $this->writeFile('deploy/config.ini', "[sesame]\nSESAME_FAKE_ALLOWED=true\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('proctor/fake-driver/enabled', $findings[0]->ruleId);
        self::assertSame('deploy/config.ini', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
    }

    public function test_fake_driver_in_gitlab_ci_fails(): void
    {
        $this->writeFile('.gitlab-ci.yml', "stages:\n  - build\n\nvariables:\n  SESAME_DRIVER: \"fake\"\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('proctor/fake-driver/enabled', $findings[0]->ruleId);
        self::assertSame('.gitlab-ci.yml', $findings[0]->path);
        self::assertSame(5, $findings[0]->line);
    }

    public function test_env_example_is_ignored(): void
    {
        $this->writeFile('.env.example', "SESAME_DRIVER=fake\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/fake-driver', $findings[0]->ruleId);
        self::assertSame('no env, deploy or CI file allows the SESAME fake driver', $findings[0]->message);
    }

    public function test_cas_driver_passes(): void
    {
        $this->writeFile('.env.production', "SESAME_DRIVER=cas\n");
        $this->writeFile('deploy/config.ini', "SESAME_FAKE_ALLOWED=false\n");
        $this->writeFile('.gitlab-ci.yml', "variables:\n  SESAME_DRIVER: \"cas\"\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/fake-driver', $findings[0]->ruleId);
        self::assertNull($findings[0]->path);
        self::assertSame('no env, deploy or CI file allows the SESAME fake driver', $findings[0]->message);
    }

    public function test_vendor_is_skipped(): void
    {
        $this->writeFile('deploy/vendor/sesame/driver.env', "SESAME_DRIVER=fake\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/fake-driver', $findings[0]->ruleId);
    }

    public function test_one_finding_per_file_on_first_line(): void
    {
        $this->writeFile('.env.production', "SESAME_DRIVER=fake\nFOO=bar\nSESAME_FAKE_ALLOWED=true\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/fake-driver/enabled', $findings[0]->ruleId);
        self::assertSame(1, $findings[0]->line);
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
            $this->appPath = sys_get_temp_dir() . '/proctor-fakedriver-' . uniqid('', true);
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

        return (new FakeDriverCheck())->run($context);
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
