<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\DevOnlyPackagesCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;

/**
 * Tests the DevOnlyPackagesCheck on a temp app dir, so the fail and warning
 * findings, their rule ids and their lines are verified without touching the
 * fixture apps.
 */
final class DevOnlyPackagesCheckTest extends TestCase
{
    private string $appPath = '';

    protected function tearDown(): void
    {
        if ($this->appPath !== '') {
            @unlink($this->appPath . '/composer.json');
            @rmdir($this->appPath);
            $this->appPath = '';
        }
    }

    public function testBoostInRequireFailsWithExposesInternals(): void
    {
        $this->writeComposerJson([
            'require' => [
                'laravel/framework' => '^11.0',
                'laravel/boost' => '^1.0',
            ],
        ]);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('proctor/dev-only-packages/exposes-internals', $findings[0]->ruleId);
        self::assertSame(
            'laravel/boost is in require, so it installs in production; move it to require-dev (composer remove laravel/boost && composer require --dev laravel/boost)',
            $findings[0]->message,
        );
        self::assertSame('composer.json', $findings[0]->path);
    }

    public function testDebugbarVariantsFailWithExposesInternals(): void
    {
        foreach (['barryvdh/laravel-debugbar', 'fruitcake/laravel-debugbar'] as $package) {
            $this->writeComposerJson([
                'require' => [$package => '^3.0'],
            ]);

            $findings = $this->runCheck();

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
            self::assertSame('proctor/dev-only-packages/exposes-internals', $findings[0]->ruleId);
            self::assertStringStartsWith($package . ' is in require', $findings[0]->message);
        }
    }

    public function testTelescopeInRequireWarnsAndKeepsItsExtraClause(): void
    {
        $this->writeComposerJson([
            'require' => [
                'laravel/telescope' => '^5.0',
            ],
        ]);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/dev-only-packages/exposes-internals', $findings[0]->ruleId);
        self::assertSame(
            "laravel/telescope is in require, so it installs in production; move it to require-dev (composer remove laravel/telescope && composer require --dev laravel/telescope), or keep it in require only if the app registers it for local use as Laravel's Telescope docs describe",
            $findings[0]->message,
        );
    }

    public function testEachTestToolInRequireWarnsWithTestTool(): void
    {
        $tools = [
            'phpunit/phpunit',
            'pestphp/pest',
            'mockery/mockery',
            'laravel/sail',
            'laravel/pail',
            'nunomaduro/collision',
        ];

        foreach ($tools as $package) {
            $this->writeComposerJson([
                'require' => [$package => '*'],
            ]);

            $findings = $this->runCheck();

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
            self::assertSame('proctor/dev-only-packages/test-tool', $findings[0]->ruleId);
            self::assertStringContainsString($package, $findings[0]->message);
        }
    }

    public function testBoostOnlyInRequireDevPasses(): void
    {
        $this->writeComposerJson([
            'require' => [
                'laravel/framework' => '^11.0',
            ],
            'require-dev' => [
                'laravel/boost' => '^1.0',
                'phpunit/phpunit' => '^11.5',
            ],
        ]);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/dev-only-packages', $findings[0]->ruleId);
        self::assertSame('no development tool is in require', $findings[0]->message);
    }

    public function testCleanAppPasses(): void
    {
        $this->writeComposerJson([
            'require' => [
                'laravel/framework' => '^11.0',
                'monolog/monolog' => '^3.0',
            ],
        ]);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/dev-only-packages', $findings[0]->ruleId);
        self::assertSame('no development tool is in require', $findings[0]->message);
    }

    public function testSeveralDevelopmentPackagesGiveOneFindingEach(): void
    {
        $this->writeComposerJson([
            'require' => [
                'laravel/boost' => '^1.0',
                'laravel/telescope' => '^5.0',
                'phpunit/phpunit' => '^11.5',
            ],
        ]);

        $findings = $this->runCheck();

        self::assertCount(3, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('proctor/dev-only-packages/exposes-internals', $findings[0]->ruleId);
        self::assertSame(Finding::STATUS_WARNING, $findings[1]->status);
        self::assertSame('proctor/dev-only-packages/exposes-internals', $findings[1]->ruleId);
        self::assertSame(Finding::STATUS_WARNING, $findings[2]->status);
        self::assertSame('proctor/dev-only-packages/test-tool', $findings[2]->ruleId);
    }

    public function testFindingNamesTheLineOfThePackage(): void
    {
        $this->writeComposerJson([
            'require' => [
                'vendor/pkg' => '^1.0',
                'laravel/boost' => '^1.0',
            ],
        ]);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        // The boost key sits on line 4 of the pretty-printed file.
        self::assertSame(4, $findings[0]->line);
    }

    public function testMissingComposerJsonGivesTheNotApplicablePass(): void
    {
        $this->appPath = sys_get_temp_dir() . '/proctor-devonly-' . uniqid('', true);
        self::assertTrue(is_dir($this->appPath) || mkdir($this->appPath, 0777, true));

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/dev-only-packages', $findings[0]->ruleId);
        self::assertSame('no composer.json, so no development packages to check', $findings[0]->message);
        self::assertSame('composer.json', $findings[0]->path);
    }

    /**
     * Writes a composer.json into the temp app dir (created on first use) and
     * remembers its path for the cleanup.
     *
     * @param array<string, mixed> $data
     */
    private function writeComposerJson(array $data): void
    {
        if ($this->appPath === '') {
            $this->appPath = sys_get_temp_dir() . '/proctor-devonly-' . uniqid('', true);
            self::assertTrue(is_dir($this->appPath) || mkdir($this->appPath, 0777, true));
        }

        file_put_contents($this->appPath . '/composer.json', json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * Runs the check on the temp app and returns its findings.
     *
     * @return list<Finding>
     */
    private function runCheck(): array
    {
        $check = new DevOnlyPackagesCheck();
        $context = new CheckContext(
            $this->appPath,
            Policy::fromFile(__DIR__ . '/../fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
        );

        return $check->run($context);
    }
}
