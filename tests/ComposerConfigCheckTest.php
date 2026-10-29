<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\ComposerConfigCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;

/**
 * Calls the composer-config check directly, the way bin/proctor builds
 * its context: the fixture policy and a fixed --today.
 */
final class ComposerConfigCheckTest extends TestCase
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
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY),
        );
    }

    public function testEveryRuleFiresOnceOnTheBadApp(): void
    {
        $findings = (new ComposerConfigCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/composer-config-bad-app'),
        );

        $expected = [
            ['allow-plugins-all', Finding::STATUS_WARNING],
            ['secure-http-off', Finding::STATUS_WARNING],
            ['lock-off', Finding::STATUS_WARNING],
            ['audit-blocking-off', Finding::STATUS_WARNING],
            ['platform-php-unset', Finding::STATUS_INFO],
        ];

        self::assertCount(count($expected), $findings);
        foreach ($expected as $i => [$rule, $status]) {
            self::assertSame($rule, $findings[$i]->rule, sprintf('finding %d', $i + 1));
            self::assertSame($status, $findings[$i]->status, sprintf('finding %d', $i + 1));
            self::assertNotSame(Finding::STATUS_FAIL, $findings[$i]->status, 'a composer-config finding must never fail');
            self::assertSame('composer.json', $findings[$i]->path);
            self::assertSame(1, $findings[$i]->line);
        }
    }

    public function testTheGoodAppPasses(): void
    {
        $findings = (new ComposerConfigCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/composer-config-good-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
        self::assertSame('composer.json', $findings[0]->path);
    }

    public function testAnAppWithoutComposerJsonPasses(): void
    {
        // The tests directory itself has no composer.json.
        $findings = (new ComposerConfigCheck())->run($this->contextFor(__DIR__));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertNull($findings[0]->rule);
    }
}
