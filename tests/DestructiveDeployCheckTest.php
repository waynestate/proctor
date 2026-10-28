<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\DestructiveDeployCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Policy\JsonFilePolicySource;

/**
 * Calls the destructive-deploy check directly, the way bin/proctor builds
 * its context: the fixture policy and a fixed --today.
 */
final class DestructiveDeployCheckTest extends TestCase
{
    /** The fixed date every run uses. */
    private const TODAY = '2026-10-27';

    /**
     * @param string $appPath the app to check
     */
    private function contextFor(string $appPath): CheckContext
    {
        return new CheckContext(
            $appPath,
            new JsonFilePolicySource(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY),
        );
    }

    public function testDeployScriptWithMigrateFreshFailsOnTheCommandLine(): void
    {
        $findings = (new DestructiveDeployCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/destructive-deploy-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame(
            'migrate:fresh in a deploy script drops data on every run. Use `php artisan migrate --force`.',
            $findings[0]->message,
        );
        self::assertSame('deployments/deploy.sh', $findings[0]->path);
        self::assertSame(5, $findings[0]->line);
    }

    public function testNestedDeployScriptsDirectoryFails(): void
    {
        $findings = (new DestructiveDeployCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/destructive-deploy-nested-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame(
            'migrate:fresh in a deploy script drops data on every run. Use `php artisan migrate --force`.',
            $findings[0]->message,
        );
        self::assertSame('src/deploy_scripts/deploy_script.sh', $findings[0]->path);
        self::assertSame(4, $findings[0]->line);
    }

    public function testPlainMigratePasses(): void
    {
        $findings = (new DestructiveDeployCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/destructive-deploy-pass-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no destructive deploy commands found', $findings[0]->message);
    }

    public function testCommentedOutCommandPasses(): void
    {
        $findings = (new DestructiveDeployCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/destructive-deploy-comment-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no destructive deploy commands found', $findings[0]->message);
    }

    public function testComposerScriptWithDbWipeFailsOnTheScriptLine(): void
    {
        $findings = (new DestructiveDeployCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/destructive-deploy-composer-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame(
            'db:wipe in a deploy script drops data on every run. Use `php artisan migrate --force`.',
            $findings[0]->message,
        );
        self::assertSame('composer.json', $findings[0]->path);
        self::assertSame(9, $findings[0]->line);
    }

    public function testCommandsOutsideDeployPathsAreIgnored(): void
    {
        // tests/ is not a deploy path and vendor/ is skipped, so the
        // migrate:fresh lines in both files are not flagged.
        $findings = (new DestructiveDeployCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/destructive-deploy-ignored-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no destructive deploy commands found', $findings[0]->message);
    }
}
