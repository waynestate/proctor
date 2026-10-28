<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check (text format) on the unpatched fixture apps with the
 * unpatched fixture policy and asserts the unpatched finding (or its absence).
 */
final class UnpatchedCheckTest extends ProctorTestCase
{
    /**
     * The path of the unpatched fixture policy.
     */
    private function policy(): string
    {
        return __DIR__ . '/fixtures/policy-unpatched.json';
    }

    public function testLaravelBehindWarns(): void
    {
        $stdout = $this->runProctor($this->app('unpatched-laravel-behind-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^WARN unpatched: laravel 12\.1\.3 is behind 12\.4\.7 \(checked 2026-10-28\); update to the latest patch\.$/m',
            $stdout,
        );
    }

    public function testLaravelCurrentPasses(): void
    {
        $stdout = $this->runProctor($this->app('unpatched-laravel-current-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^PASS unpatched: laravel 12\.4\.7 is up to date \(latest patch 12\.4\.7\)$/m',
            $stdout,
        );
    }

    public function testNoLockFileProducesNoFinding(): void
    {
        $stdout = $this->runProctor($this->app('unpatched-no-lock-app'), 'text', $this->policy())['stdout'];

        self::assertDoesNotMatchRegularExpression('/^(?:WARN|PASS|FAIL|INFO) unpatched: /m', $stdout);
    }

    public function testVueBehindWarns(): void
    {
        $stdout = $this->runProctor($this->app('unpatched-vue-behind-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^WARN unpatched: vue 3\.4\.10 is behind 3\.4\.38 \(checked 2026-10-28\); update to the latest patch\.$/m',
            $stdout,
        );
    }

    public function testInertiaBehindWarns(): void
    {
        $stdout = $this->runProctor($this->app('unpatched-inertia-behind-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^WARN unpatched: inertia 1\.2\.3 is behind 1\.2\.6 \(checked 2026-10-28\); update to the latest patch\.$/m',
            $stdout,
        );
    }

    public function testPhpBehindWithFlagWarns(): void
    {
        $stdout = $this->runProctor($this->app('unpatched-php-app'), 'text', $this->policy(), '8.2.10')['stdout'];

        self::assertMatchesRegularExpression(
            '/^WARN unpatched: php 8\.2\.10 is behind 8\.2\.35 \(checked 2026-10-28\); update to the latest patch\.$/m',
            $stdout,
        );
    }

    public function testPhpWithoutFlagProducesNoFinding(): void
    {
        $stdout = $this->runProctor($this->app('unpatched-php-app'), 'text', $this->policy())['stdout'];

        self::assertDoesNotMatchRegularExpression('/^(?:WARN|PASS|FAIL|INFO) unpatched: /m', $stdout);
    }

    public function testLineWithoutLatestPatchProducesNoFinding(): void
    {
        $stdout = $this->runProctor($this->app('unpatched-no-latest-patch-app'), 'text', $this->policy())['stdout'];

        self::assertDoesNotMatchRegularExpression('/^(?:WARN|PASS|FAIL|INFO) unpatched: /m', $stdout);
    }

    public function testInvalidPhpRuntimeExitsTwo(): void
    {
        $result = $this->runProctor($this->app('unpatched-php-app'), 'text', $this->policy(), '8.2');

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('Invalid --php-runtime value "8.2"', $result['stderr']);
    }
}
