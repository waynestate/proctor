<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check (text format) on the laravel-eol fixture apps with
 * the laravel fixture policy and asserts the laravel-eol finding (or its
 * absence).
 */
final class LaravelEolCheckTest extends ProctorTestCase
{
    /**
     * The path of the laravel fixture policy.
     */
    private function policy(): string
    {
        return __DIR__ . '/fixtures/policy-laravel.json';
    }

    public function testActiveVersionPasses(): void
    {
        $stdout = $this->runProctor($this->app('laravel-eol-active-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^PASS laravel-eol: laravel 13\.39\.0 is active until 2027-09-30$/m',
            $stdout,
        );
    }

    public function testSecurityOnlyVersionWarns(): void
    {
        $stdout = $this->runProctor($this->app('laravel-eol-security-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^WARN laravel-eol: laravel 12\.70\.2 is in security-only support until 2027-02-24 \(120 days left\)$/m',
            $stdout,
        );
    }

    public function testEolVersionFailsWithinGrace(): void
    {
        $stdout = $this->runProctor($this->app('laravel-eol-eol-app'), 'text', $this->policy(), null, '2026-04-01')['stdout'];

        self::assertMatchesRegularExpression(
            '/^FAIL laravel-eol: laravel 11\.57\.1 is eol \(security support ended 2026-03-12\)$/m',
            $stdout,
        );
    }

    public function testEolVersionFailsAfterGrace(): void
    {
        $stdout = $this->runProctor($this->app('laravel-eol-eol-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^FAIL laravel-eol: laravel 11\.57\.1 is eol_grace_expired \(security support ended 2026-03-12\)$/m',
            $stdout,
        );
    }

    public function testOverrideDowngradesEolToWarning(): void
    {
        $stdout = $this->runProctor($this->app('laravel-eol-override-app'), 'text', $this->policy(), null, '2026-04-01')['stdout'];

        self::assertMatchesRegularExpression(
            '/^WARN laravel-eol: laravel 11\.57\.1 is eol \(security support ended 2026-03-12\) \(overridden: fixture: upgrade to Laravel 12 scheduled for the next release\)$/m',
            $stdout,
        );
    }

    public function testOverrideDoesNotDowngradeGraceExpired(): void
    {
        $stdout = $this->runProctor($this->app('laravel-eol-override-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^FAIL laravel-eol: laravel 11\.57\.1 is eol_grace_expired \(security support ended 2026-03-12\)$/m',
            $stdout,
        );
    }

    public function testAppWithoutLaravelProducesNoFinding(): void
    {
        $stdout = $this->runProctor($this->app('laravel-eol-no-laravel-app'), 'text', $this->policy())['stdout'];

        self::assertDoesNotMatchRegularExpression('/^(?:WARN|PASS|FAIL|INFO) laravel-eol: /m', $stdout);
    }
}
