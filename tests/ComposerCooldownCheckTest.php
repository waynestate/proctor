<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\ComposerCooldownCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;

/**
 * Calls the composer-cooldown check directly on temporary app directories,
 * so every cooldown shape can be built around a real composer.json.
 */
final class ComposerCooldownCheckTest extends TestCase
{
    /** The fixed date every run uses. */
    private const TODAY = '2026-10-27';

    /**
     * The temporary app directories this test creates.
     *
     * @var list<string>
     */
    private array $appDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->appDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->appDirs = [];
    }

    public function testAnAppWithoutComposerJsonPasses(): void
    {
        $appPath = $this->makeApp(null);

        $findings = (new ComposerCooldownCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
        self::assertSame('no composer.json, so no config to check', $findings[0]->message);
        self::assertSame('composer.json', $findings[0]->path);
    }

    public function testASetPeriodPassesAndNamesIt(): void
    {
        $appPath = $this->makeApp([
            'require' => ['php' => '^8.3'],
            'config' => ['policy' => ['cooldown' => [
                'period' => '3 days',
                'ignore' => ['waynestate/*'],
            ]]],
        ]);

        $findings = (new ComposerCooldownCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
        self::assertSame('composer.json sets a release cooldown of 3 days', $findings[0]->message);
        self::assertSame('composer.json', $findings[0]->path);
    }

    public function testNoConfigBlockWarnsMissing(): void
    {
        $appPath = $this->makeApp(['require' => ['php' => '^8.3']]);

        $this->assertMissingWarning((new ComposerCooldownCheck())->run($this->contextFor($appPath)));
    }

    public function testAConfigWithoutPolicyWarnsMissing(): void
    {
        $appPath = $this->makeApp([
            'require' => ['php' => '^8.3'],
            'config' => ['secure-http' => true],
        ]);

        $this->assertMissingWarning((new ComposerCooldownCheck())->run($this->contextFor($appPath)));
    }

    public function testAPolicyWithoutCooldownWarnsMissing(): void
    {
        $appPath = $this->makeApp([
            'require' => ['php' => '^8.3'],
            'config' => ['policy' => ['malware' => true]],
        ]);

        $this->assertMissingWarning((new ComposerCooldownCheck())->run($this->contextFor($appPath)));
    }

    public function testANullPeriodWarnsMissing(): void
    {
        $appPath = $this->makeApp([
            'require' => ['php' => '^8.3'],
            'config' => ['policy' => ['cooldown' => ['period' => null]]],
        ]);

        $this->assertMissingWarning((new ComposerCooldownCheck())->run($this->contextFor($appPath)));
    }

    public function testAnEmptyPeriodWarnsMissing(): void
    {
        $appPath = $this->makeApp([
            'require' => ['php' => '^8.3'],
            'config' => ['policy' => ['cooldown' => ['period' => '']]],
        ]);

        $this->assertMissingWarning((new ComposerCooldownCheck())->run($this->contextFor($appPath)));
    }

    public function testABlockOffWithAPeriodWarnsNotBlocking(): void
    {
        $appPath = $this->makeApp([
            'require' => ['php' => '^8.3'],
            'config' => ['policy' => ['cooldown' => [
                'period' => '3 days',
                'block' => false,
            ]]],
        ]);

        $findings = (new ComposerCooldownCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('not-blocking', $findings[0]->rule);
        self::assertSame('proctor/composer-cooldown/not-blocking', $findings[0]->ruleId);
        self::assertSame(
            'composer.json sets a release cooldown but turns blocking off (policy.cooldown.block is false), so Composer installs new versions anyway; remove "block": false',
            $findings[0]->message,
        );
        self::assertSame('composer.json', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    public function testABlockOnWithAPeriodPasses(): void
    {
        $appPath = $this->makeApp([
            'require' => ['php' => '^8.3'],
            'config' => ['policy' => ['cooldown' => [
                'period' => '1 day',
                'block' => true,
            ]]],
        ]);

        $findings = (new ComposerCooldownCheck())->run($this->contextFor($appPath));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
        self::assertSame('composer.json sets a release cooldown of 1 day', $findings[0]->message);
    }

    public function testNoFindingIsEverAFail(): void
    {
        $apps = [
            null, // no composer.json
            ['require' => ['php' => '^8.3']], // no cooldown
            ['config' => ['policy' => ['cooldown' => ['period' => '', 'block' => false]]]], // empty period
            ['config' => ['policy' => ['cooldown' => ['period' => '3 days', 'block' => false]]]], // not blocking
            ['config' => ['policy' => ['cooldown' => ['period' => '3 days']]]], // clean
        ];
        foreach ($apps as $composerJson) {
            $appPath = $this->makeApp($composerJson);
            $findings = (new ComposerCooldownCheck())->run($this->contextFor($appPath));
            foreach ($findings as $finding) {
                self::assertNotSame(Finding::STATUS_FAIL, $finding->status, 'a composer-cooldown finding must never fail');
            }
        }
    }

    /**
     * Builds a temporary app directory with the given composer.json, or none
     * when null, and returns its path.
     *
     * @param array<string, mixed>|null $composerJson
     */
    private function makeApp(?array $composerJson): string
    {
        $appPath = sys_get_temp_dir() . '/proctor-composer-cooldown-' . uniqid('', true);
        self::assertTrue(mkdir($appPath, 0777, true), sprintf('could not create temp dir "%s"', $appPath));
        $this->appDirs[] = $appPath;

        if ($composerJson !== null) {
            file_put_contents($appPath . '/composer.json', json_encode($composerJson));
        }

        return $appPath;
    }

    /**
     * The one warning the missing cases must give, exactly.
     *
     * @param list<Finding> $findings
     */
    private function assertMissingWarning(array $findings): void
    {
        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('missing', $findings[0]->rule);
        self::assertSame('proctor/composer-cooldown/missing', $findings[0]->ruleId);
        self::assertSame(
            'composer.json sets no release cooldown; add "config": {"policy": {"cooldown": {"period": "3 days", "ignore": ["waynestate/*"]}}} so Composer waits before it installs a newly published version (CREED practice 40)',
            $findings[0]->message,
        );
        self::assertSame('composer.json', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    private function contextFor(string $appPath): CheckContext
    {
        return new CheckContext(
            $appPath,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY),
        );
    }

    /**
     * Removes a directory tree.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
