<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\ComposerVersionCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\ProcessResult;
use Waynestate\Proctor\Check\ProcessRunnerInterface;
use Waynestate\Proctor\Creed\Policy;

/**
 * Tests the ComposerVersionCheck with a stub ProcessRunner, so the floor
 * decisions are verified without running composer.
 */
final class ComposerVersionCheckTest extends TestCase
{
    private string $originalPath = '';

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);
    }

    public function testComposerTwoTenThreePasses(): void
    {
        $this->assertVersion('2.10.3', Finding::STATUS_PASS, 'composer 2.10.3 is at or above the 2.7.7 floor');
    }

    public function testComposerTwoSevenSixFailsBelowTheMainlineFloor(): void
    {
        $this->assertVersion(
            '2.7.6',
            Finding::STATUS_FAIL,
            'composer 2.7.6 is below the 2.7.7 floor (CVE-2024-35241, CVE-2024-35242)',
        );
    }

    public function testComposerTwoSevenSevenPassesAtTheMainlineFloor(): void
    {
        $this->assertVersion('2.7.7', Finding::STATUS_PASS, 'composer 2.7.7 is at or above the 2.7.7 floor');
    }

    public function testComposerTwoTwoTwentyThreeFailsBelowTheLtsFloor(): void
    {
        $this->assertVersion(
            '2.2.23',
            Finding::STATUS_FAIL,
            'composer 2.2.23 is below the 2.2.24 floor (CVE-2024-35241, CVE-2024-35242)',
        );
    }

    public function testComposerTwoTwoTwentyFourPassesAtTheLtsFloor(): void
    {
        $this->assertVersion('2.2.24', Finding::STATUS_PASS, 'composer 2.2.24 is at or above the 2.2.24 floor');
    }

    public function testComposerOneFailsAsEndOfLife(): void
    {
        $this->assertVersion('1.10.27', Finding::STATUS_FAIL, 'composer 1.10.27: Composer 1 is end of life');
    }

    public function testUnparseableOutputWarns(): void
    {
        $this->putFakeComposerOnPath();

        $check = new ComposerVersionCheck(new class implements ProcessRunnerInterface {
            public function run(array $command, string $cwd, int $timeout = 300): ProcessResult
            {
                return new ProcessResult(0, 'Composer could not be initialized', '');
            }
        });

        $finding = $this->runCheck($check);

        self::assertSame(Finding::STATUS_WARNING, $finding->status);
        self::assertSame(
            'composer version could not be determined: no error output',
            $finding->message,
        );
        self::assertNull($check->composerVersion);
    }

    public function testMissingComposerWarns(): void
    {
        putenv('PATH=' . __DIR__ . '/fixtures/no-tools');

        $check = new ComposerVersionCheck();
        $finding = $this->runCheck($check);

        self::assertSame(Finding::STATUS_WARNING, $finding->status);
        self::assertSame(
            'composer executable not found on PATH; composer version check skipped',
            $finding->message,
        );
        self::assertNull($check->composerVersion);
    }

    /**
     * Runs the check against a stub runner that prints the given version and
     * asserts the finding's status, message and the version kept on the check.
     */
    private function assertVersion(string $version, string $expectedStatus, string $expectedMessage): void
    {
        $this->putFakeComposerOnPath();

        $check = new ComposerVersionCheck(new class ($version) implements ProcessRunnerInterface {
            public function __construct(private readonly string $version) {}

            public function run(array $command, string $cwd, int $timeout = 300): ProcessResult
            {
                return new ProcessResult(
                    0,
                    sprintf("Composer version %s 2025-05-19 13:50:22\n", $this->version),
                    '',
                );
            }
        });

        $finding = $this->runCheck($check);

        self::assertSame($expectedStatus, $finding->status);
        self::assertSame($expectedMessage, $finding->message);
        self::assertSame($version, $check->composerVersion);
    }

    /**
     * Runs the check on the audit-app fixture and returns its single finding.
     */
    private function runCheck(ComposerVersionCheck $check): Finding
    {
        $context = new CheckContext(
            __DIR__ . '/fixtures/apps/audit-app',
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
        );

        $findings = $check->run($context);
        self::assertCount(1, $findings);

        return $findings[0];
    }

    /**
     * Puts a fake composer executable on PATH so findComposer() succeeds.
     */
    private function putFakeComposerOnPath(): void
    {
        $dir = sys_get_temp_dir() . '/proctor-fake-composer';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $composer = $dir . '/composer';
        file_put_contents($composer, "#!/bin/sh\nexit 0\n");
        chmod($composer, 0755);
        putenv('PATH=' . $dir . PATH_SEPARATOR . $this->originalPath);
    }
}
