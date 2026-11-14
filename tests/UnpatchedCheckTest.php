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

    /**
     * The path of the minimum-patch fixture policy.
     */
    private function minimumPatchPolicy(): string
    {
        return __DIR__ . '/fixtures/policy-minimum-patch.json';
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

    public function testPhpBelowMinimumPatchWarnsInsteadOfBehind(): void
    {
        $stdout = $this->runProctor($this->app('unpatched-php-app'), 'text', $this->minimumPatchPolicy(), '8.3.10')['stdout'];

        self::assertStringContainsString(
            "WARN unpatched: php 8.3.10 is below the stack's minimum patch 8.3.31 for 8.3; update to at least 8.3.31.",
            $stdout,
        );
        self::assertDoesNotMatchRegularExpression('/^(?:WARN|PASS|FAIL|INFO) unpatched: .*is behind/m', $stdout);
    }

    public function testPhpAtMinimumPatchStillWarnsBehind(): void
    {
        $stdout = $this->runProctor($this->app('unpatched-php-app'), 'text', $this->minimumPatchPolicy(), '8.3.31')['stdout'];

        self::assertMatchesRegularExpression(
            '/^WARN unpatched: php 8\.3\.31 is behind 8\.3\.32 \(checked 2026-10-28\); update to the latest patch\.$/m',
            $stdout,
        );
        self::assertDoesNotMatchRegularExpression('/below the stack\'s minimum patch/m', $stdout);
    }

    public function testPhpCurrentPassesWithMinimumPatch(): void
    {
        $stdout = $this->runProctor($this->app('unpatched-php-app'), 'text', $this->minimumPatchPolicy(), '8.3.32')['stdout'];

        self::assertMatchesRegularExpression(
            '/^PASS unpatched: php 8\.3\.32 is up to date \(latest patch 8\.3\.32\)$/m',
            $stdout,
        );
        self::assertDoesNotMatchRegularExpression('/^(?:WARN|FAIL) unpatched: /m', $stdout);
    }

    public function testPhpBelowMinimumPatchWarnsWithoutLatestPatch(): void
    {
        $stdout = $this->runProctor($this->app('unpatched-php-app'), 'text', $this->minimumPatchPolicy(), '8.4.5')['stdout'];

        self::assertStringContainsString(
            "WARN unpatched: php 8.4.5 is below the stack's minimum patch 8.4.21 for 8.4; update to at least 8.4.21.",
            $stdout,
        );
    }

    public function testPhpAboveMinimumPatchProducesNoFindingWithoutLatestPatch(): void
    {
        $stdout = $this->runProctor($this->app('unpatched-php-app'), 'text', $this->minimumPatchPolicy(), '8.4.30')['stdout'];

        self::assertDoesNotMatchRegularExpression('/^(?:WARN|PASS|FAIL|INFO) unpatched: /m', $stdout);
    }

    public function testPhpBelowMinimumPatchCarriesTheRuleIdInJson(): void
    {
        $data = json_decode($this->runProctor($this->app('unpatched-php-app'), 'json', $this->minimumPatchPolicy(), '8.3.10')['stdout'], true);
        self::assertIsArray($data);

        $ruleIds = [];
        foreach ($data['findings'] as $finding) {
            if ($finding['check_id'] === 'unpatched') {
                $ruleIds[] = $finding['rule_id'];
            }
        }
        self::assertSame(['proctor/unpatched/below-minimum'], $ruleIds);
    }

    public function testInvalidPhpRuntimeExitsTwo(): void
    {
        $result = $this->runProctor($this->app('unpatched-php-app'), 'text', $this->policy(), '8.2');

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('Invalid --php-runtime value "8.2"', $result['stderr']);
    }
}
