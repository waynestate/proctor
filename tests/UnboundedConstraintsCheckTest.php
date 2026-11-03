<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\UnboundedConstraintsCheck;
use Waynestate\Proctor\Creed\Policy;

/**
 * Tests the minimum-stability rule of the UnboundedConstraintsCheck on a
 * temp app dir, so the warning, its rule id and its line are verified
 * without touching the fixture apps.
 */
final class UnboundedConstraintsCheckTest extends TestCase
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

    public function testDevMinimumStabilityGivesOneWarningFinding(): void
    {
        $this->writeComposerJson([
            'require' => [
                'vendor/pkg' => '^1.0',
            ],
            'minimum-stability' => 'dev',
        ]);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/unbounded-constraints/minimum-stability', $findings[0]->ruleId);
        self::assertSame(
            'minimum-stability is "dev": every package may resolve to a pre-release; set it to stable and name a stability only where one package needs it',
            $findings[0]->message,
        );
        self::assertSame('composer.json', $findings[0]->path);
        // The key sits on line 5 of the written file.
        self::assertSame(5, $findings[0]->line);
    }

    public function testStableMinimumStabilityGivesOnlyThePassFinding(): void
    {
        $this->writeComposerJson([
            'require' => [
                'vendor/pkg' => '^1.0',
            ],
            'minimum-stability' => 'stable',
        ]);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/unbounded-constraints', $findings[0]->ruleId);
    }

    public function testAbsentMinimumStabilityGivesOnlyThePassFinding(): void
    {
        $this->writeComposerJson([
            'require' => [
                'vendor/pkg' => '^1.0',
            ],
        ]);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/unbounded-constraints', $findings[0]->ruleId);
    }

    /**
     * Writes a composer.json into a fresh temp app dir and remembers its
     * path for the cleanup.
     *
     * @param array<string, mixed> $data
     */
    private function writeComposerJson(array $data): void
    {
        $this->appPath = sys_get_temp_dir() . '/proctor-minstab-' . uniqid('', true);
        self::assertTrue(is_dir($this->appPath) || mkdir($this->appPath, 0777, true));
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
        $check = new UnboundedConstraintsCheck();
        $context = new CheckContext(
            $this->appPath,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
        );

        return $check->run($context);
    }
}
