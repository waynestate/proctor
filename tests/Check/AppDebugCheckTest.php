<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\AppDebugCheck;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;

/**
 * Tests the AppDebugCheck on a temp app dir, so the warning findings, their
 * rule ids and their lines are verified without touching the fixture apps.
 */
final class AppDebugCheckTest extends TestCase
{
    private string $appPath = '';

    protected function tearDown(): void
    {
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
            $this->appPath = '';
        }
    }

    public function testEnvExampleTrueWarnsAndNamesTheFileAndLine(): void
    {
        $this->writeFile('.env.example', "APP_NAME=Acme\nAPP_DEBUG=true\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/app-debug/enabled', $findings[0]->ruleId);
        self::assertSame('.env.example', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
        self::assertSame(
            'APP_DEBUG=true in .env.example:2 shows stack traces and env values to users in production; set APP_DEBUG=false (Laravel reads it in config/app.php).',
            $findings[0]->message,
        );
    }

    public function testEachEnvTemplateTrueWarns(): void
    {
        foreach (['.env.example', '.env.production', '.env.squire.example'] as $name) {
            $this->freshApp();
            $this->writeFile($name, "APP_DEBUG=true\n");

            $findings = $this->runCheck();

            self::assertCount(1, $findings, $name);
            self::assertSame('proctor/app-debug/enabled', $findings[0]->ruleId, $name);
            self::assertSame($name, $findings[0]->path, $name);
            self::assertSame(1, $findings[0]->line, $name);
        }
    }

    public function testDeployFileTrueWarns(): void
    {
        $this->writeFile('deploy/config.ini', "[app]\nAPP_DEBUG=true\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/app-debug/enabled', $findings[0]->ruleId);
        self::assertSame('deploy/config.ini', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
    }

    public function testNestedDeploymentsFileTrueWarns(): void
    {
        $this->writeFile('deployments/scripts/release.sh', "#!/bin/sh\nset -e\nexport APP_DEBUG=1\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/app-debug/enabled', $findings[0]->ruleId);
        self::assertSame('deployments/scripts/release.sh', $findings[0]->path);
        self::assertSame(3, $findings[0]->line);
    }

    public function testGitlabCiVariablesTrueWarns(): void
    {
        $this->writeFile('.gitlab-ci.yml', "stages:\n  - build\n\nvariables:\n  APP_DEBUG: \"true\"\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/app-debug/enabled', $findings[0]->ruleId);
        self::assertSame('.gitlab-ci.yml', $findings[0]->path);
        self::assertSame(5, $findings[0]->line);
    }

    public function testGithubWorkflowTrueWarns(): void
    {
        $this->writeFile(
            '.github/workflows/ci.yml',
            "name: CI\n\non: push\n\njobs:\n  build:\n    runs-on: ubuntu-latest\n    env:\n      APP_DEBUG: 'true'\n",
        );

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/app-debug/enabled', $findings[0]->ruleId);
        self::assertSame('.github/workflows/ci.yml', $findings[0]->path);
        self::assertSame(9, $findings[0]->line);
    }

    public function testAllFalsePasses(): void
    {
        $this->writeFile('.env.example', "APP_DEBUG=false\n");
        $this->writeFile('deploy/config.ini', "APP_DEBUG=0\n");
        $this->writeFile('.gitlab-ci.yml', "variables:\n  APP_DEBUG: \"false\"\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/app-debug', $findings[0]->ruleId);
        self::assertNull($findings[0]->path);
        self::assertSame('no env, deploy or CI file sets APP_DEBUG=true', $findings[0]->message);
    }

    public function testABareEnvSettingTrueIsNotRead(): void
    {
        $this->writeFile('.env', "APP_DEBUG=true\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/app-debug', $findings[0]->ruleId);
    }

    public function testTruthyValueVariantsWarn(): void
    {
        $variants = [
            'APP_DEBUG=true',
            'APP_DEBUG=TRUE',
            'APP_DEBUG="true"',
            "APP_DEBUG='true'",
            'APP_DEBUG=1',
            'APP_DEBUG="1"',
            'APP_DEBUG = true',
            'APP_DEBUG: true',
        ];

        foreach ($variants as $line) {
            $this->freshApp();
            $this->writeFile('.env.example', $line . "\n");

            $findings = $this->runCheck();

            self::assertCount(1, $findings, $line);
            self::assertSame('proctor/app-debug/enabled', $findings[0]->ruleId, $line);
        }
    }

    public function testFalsyValuesAndLookalikeKeysPass(): void
    {
        $variants = [
            'APP_DEBUG=false',
            'APP_DEBUG=0',
            'APP_DEBUG="false"',
            'APP_DEBUG=truefoo',
            'APP_DEBUG=123',
            'APP_DEBUG=',
            'IS_APP_DEBUG=true',
            'MY_APP_DEBUG=true',
        ];

        foreach ($variants as $line) {
            $this->freshApp();
            $this->writeFile('.env.example', $line . "\n");

            $findings = $this->runCheck();

            self::assertCount(1, $findings, $line);
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status, $line);
        }
    }

    public function testACommentedOutLineIsIgnored(): void
    {
        $this->writeFile('.env.example', "# APP_DEBUG=true\nAPP_NAME=Acme\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testOneFindingPerFileUsesTheFirstTrueLine(): void
    {
        $this->writeFile('.env.example', "APP_DEBUG=true\nFOO=bar\nAPP_DEBUG=1\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(1, $findings[0]->line);
    }

    public function testTheCheckIsNotInTheDeployGate(): void
    {
        self::assertNotContains('app-debug', CheckSet::GATE);
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
            $this->appPath = sys_get_temp_dir() . '/proctor-appdebug-' . uniqid('', true);
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

        return (new AppDebugCheck())->run($context);
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
