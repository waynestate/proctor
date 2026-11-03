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
 * Tests the minimum-stability and repository-URL rules of the
 * UnboundedConstraintsCheck on a temp app dir, so the warnings, their rule
 * ids and their lines are verified without touching the fixture apps.
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

    public function testPlainHttpRepositoryUrlWarnsWithoutTheCredential(): void
    {
        $this->writeComposerJson([
            'require' => [
                'vendor/pkg' => '^1.0',
            ],
            'repositories' => [
                [
                    'type' => 'composer',
                    'url' => 'http://user:secret@packages.example.test:8080/path',
                ],
            ],
        ]);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/unbounded-constraints/insecure-url', $findings[0]->ruleId);
        self::assertStringContainsString('packages.example.test', $findings[0]->message);
        self::assertStringNotContainsString('secret', $findings[0]->message);
        self::assertStringNotContainsString('user', $findings[0]->message);
        self::assertStringNotContainsString('8080', $findings[0]->message);
        self::assertSame('composer.json', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    public function testHttpsRepositoryUrlGivesOnlyThePassFinding(): void
    {
        $this->writeComposerJson([
            'require' => [
                'vendor/pkg' => '^1.0',
            ],
            'repositories' => [
                [
                    'type' => 'composer',
                    'url' => 'https://packages.example.test/path',
                ],
            ],
        ]);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/unbounded-constraints', $findings[0]->ruleId);
    }

    public function testTmpEdgeCasesForInsecureUrl(): void
    {
        // Uppercase scheme is caught too (case-insensitive), host kept as written.
        $this->writeComposerJson([
            'require' => ['vendor/pkg' => '^1.0'],
            'repositories' => [
                ['type' => 'composer', 'url' => 'HTTP://EXAMPLE.TEST/repo'],
            ],
        ]);
        $findings = $this->runCheck();
        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/unbounded-constraints/insecure-url', $findings[0]->ruleId);
        self::assertSame(
            'repository "EXAMPLE.TEST" is served over plain http, so a network attacker can change what Composer downloads; use https',
            $findings[0]->message,
        );
        self::assertSame('composer.json', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);

        // No parseable host falls back to "a repository".
        $this->writeComposerJson([
            'require' => ['vendor/pkg' => '^1.0'],
            'repositories' => [
                ['type' => 'composer', 'url' => 'http://'],
            ],
        ]);
        $findings = $this->runCheck();
        self::assertCount(1, $findings);
        self::assertSame(
            'repository "a repository" is served over plain http, so a network attacker can change what Composer downloads; use https',
            $findings[0]->message,
        );

        // A vcs entry with an http url gives both warnings, the type one first.
        $this->writeComposerJson([
            'require' => ['vendor/pkg' => '^1.0'],
            'repositories' => [
                ['type' => 'vcs', 'url' => 'http://host.test/x'],
            ],
        ]);
        $findings = $this->runCheck();
        self::assertCount(2, $findings);
        self::assertSame('proctor/unbounded-constraints/repository', $findings[0]->ruleId);
        self::assertSame('proctor/unbounded-constraints/insecure-url', $findings[1]->ruleId);

        // A path repository gives only the type warning.
        $this->writeComposerJson([
            'require' => ['vendor/pkg' => '^1.0'],
            'repositories' => [
                ['type' => 'path', 'target' => '../lib'],
            ],
        ]);
        $findings = $this->runCheck();
        self::assertCount(1, $findings);
        self::assertSame('proctor/unbounded-constraints/repository', $findings[0]->ruleId);

        // A git@ url and an https url with credentials give nothing.
        $this->writeComposerJson([
            'require' => ['vendor/pkg' => '^1.0'],
            'repositories' => [
                ['type' => 'composer', 'url' => 'git@github.com:x/y.git'],
                ['type' => 'composer', 'url' => 'https://user:secret@packages.example.test/path'],
            ],
        ]);
        $findings = $this->runCheck();
        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);

        // Two http repositories give two warnings, one per entry.
        $this->writeComposerJson([
            'require' => ['vendor/pkg' => '^1.0'],
            'repositories' => [
                ['type' => 'composer', 'url' => 'http://one.test/a'],
                ['type' => 'composer', 'url' => 'http://two.test/b'],
            ],
        ]);
        $findings = $this->runCheck();
        self::assertCount(2, $findings);
        self::assertStringContainsString('one.test', $findings[0]->message);
        self::assertStringContainsString('two.test', $findings[1]->message);
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
