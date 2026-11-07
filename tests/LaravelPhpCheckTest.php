<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check (text format) on the laravel-php fixture apps with
 * the laravel fixture policy and asserts the laravel-php finding (or its
 * absence).
 */
final class LaravelPhpCheckTest extends ProctorTestCase
{
    /**
     * The path of the laravel fixture policy.
     */
    private function policy(): string
    {
        return __DIR__ . '/fixtures/policy-laravel.json';
    }

    public function testSkeletonConstraintPasses(): void
    {
        $stdout = $this->runProctor($this->app('laravel-php-caret-82-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^PASS laravel-php: php constraint "\^8\.2" fits the 8\.2 to 8\.5 range of laravel 12$/m',
            $stdout,
        );
    }

    public function testCaretAboveFloorPasses(): void
    {
        $stdout = $this->runProctor($this->app('laravel-php-caret-84-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^PASS laravel-php: php constraint "\^8\.4" fits the 8\.2 to 8\.5 range of laravel 12$/m',
            $stdout,
        );
    }

    public function testTildeConstraintPasses(): void
    {
        $stdout = $this->runProctor($this->app('laravel-php-pass-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^PASS laravel-php: php constraint "~8\.4\.0" fits the 8\.2 to 8\.5 range of laravel 12$/m',
            $stdout,
        );
    }

    public function testBelowFloorFails(): void
    {
        $stdout = $this->runProctor($this->app('laravel-php-below-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^FAIL laravel-php: php constraint ">=8\.0" allows PHP below 8\.2, the floor of laravel 12 \(8\.2 to 8\.5\)$/m',
            $stdout,
        );
    }

    public function testNoOverlapWithRangeFails(): void
    {
        $stdout = $this->runProctor($this->app('laravel-php-no-overlap-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^FAIL laravel-php: php constraint "\^7\.4" allows no PHP in the 8\.2 to 8\.5 range of laravel 12$/m',
            $stdout,
        );
    }

    public function testOverrideDowngradesToWarning(): void
    {
        $stdout = $this->runProctor($this->app('laravel-php-override-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^WARN laravel-php: php constraint "\^8\.1" allows PHP below 8\.2, the floor of laravel 12 \(8\.2 to 8\.5\) \(overridden: fixture: PHP 8\.1 still runs on the staging container\)$/m',
            $stdout,
        );
    }

    public function testAppWithoutLaravelProducesNoFinding(): void
    {
        $stdout = $this->runProctor($this->app('laravel-php-no-laravel-app'), 'text', $this->policy())['stdout'];

        self::assertDoesNotMatchRegularExpression('/^(?:WARN|PASS|FAIL|INFO) laravel-php: /m', $stdout);
    }

    public function testAppWithoutPhpConstraintProducesNoFinding(): void
    {
        $stdout = $this->runProctor($this->app('laravel-php-no-php-constraint-app'), 'text', $this->policy())['stdout'];

        self::assertDoesNotMatchRegularExpression('/^(?:WARN|PASS|FAIL|INFO) laravel-php: /m', $stdout);
    }
}
