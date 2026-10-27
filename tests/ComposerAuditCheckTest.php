<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\ComposerAuditCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\ProcessResult;
use Waynestate\Proctor\Check\ProcessRunnerInterface;
use Waynestate\Proctor\Policy\JsonFilePolicySource;

/**
 * Tests the ComposerAuditCheck with a stub ProcessRunner, so the advisory,
 * ignore and abandoned handling is verified without running composer.
 */
final class ComposerAuditCheckTest extends TestCase
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

    public function testAdvisoriesThresholdIgnoredAndAbandoned(): void
    {
        $this->putFakeComposerOnPath();

        $json = json_encode([
            'advisories' => [
                'vendor/critical' => [
                    [
                        'severity' => 'critical',
                        'advisory' => ['cve' => 'CVE-2024-0001', 'advisory_id' => 'GHSA-0001'],
                    ],
                ],
                'vendor/low' => [
                    [
                        'severity' => 'low',
                        'advisory' => ['cve' => 'CVE-2024-0002', 'advisory_id' => 'GHSA-0002'],
                    ],
                ],
                'vendor/ignored' => [
                    [
                        'severity' => 'critical',
                        'advisory' => ['cve' => 'CVE-2024-0003', 'advisory_id' => 'GHSA-0003'],
                    ],
                ],
            ],
            'abandoned' => [
                'vendor/abandoned' => true,
                'vendor/abandoned-replaced' => 'vendor/replacement',
            ],
        ]);

        $check = new ComposerAuditCheck(new class($json) implements ProcessRunnerInterface {
            public function __construct(private readonly string $json)
            {
            }

            public function run(array $command, string $cwd): ProcessResult
            {
                return new ProcessResult(1, $this->json, '');
            }
        });

        $context = new CheckContext(
            __DIR__ . '/fixtures/apps/audit-app',
            new JsonFilePolicySource(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27')
        );

        $findings = $check->run($context);

        $byMessage = [];
        foreach ($findings as $finding) {
            $byMessage[$finding->message] = $finding->status;
        }

        self::assertSame(Finding::STATUS_FAIL, $byMessage['vendor/critical CVE-2024-0001 (critical)']);
        self::assertSame(Finding::STATUS_WARNING, $byMessage['vendor/low CVE-2024-0002 (low)']);
        self::assertSame(Finding::STATUS_WARNING, $byMessage['vendor/ignored CVE-2024-0003 (critical)']);
        self::assertSame(Finding::STATUS_WARNING, $byMessage['vendor/abandoned is abandoned']);
        self::assertSame(Finding::STATUS_WARNING, $byMessage['vendor/abandoned-replaced is abandoned (use vendor/replacement instead)']);
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
