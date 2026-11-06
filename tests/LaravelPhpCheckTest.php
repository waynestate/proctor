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

    public function testConstraintInsideRangePasses(): void
    {
        $stdout = $this->runProctor($this->app('laravel-php-pass-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^PASS laravel-php: php constraint "~8\.4\.0" fits the 8\.2 to 8\.5 range of laravel 12$/m',
            $stdout,
        );
    }

    public function testConstraintBelowRangeFails(): void
    {
        $stdout = $this->runProctor($this->app('laravel-php-below-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^FAIL laravel-php: php constraint "\^8\.1" allows PHP outside the 8\.2 to 8\.5 range of laravel 12$/m',
            $stdout,
        );
    }

    public function testConstraintAboveRangeFails(): void
    {
        $stdout = $this->runProctor($this->app('laravel-php-above-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^FAIL laravel-php: php constraint "\^8\.4" allows PHP outside the 8\.2 to 8\.5 range of laravel 12$/m',
            $stdout,
        );
    }

    public function testOverrideDowngradesToWarning(): void
    {
        $stdout = $this->runProctor($this->app('laravel-php-override-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^WARN laravel-php: php constraint "\^8\.1" allows PHP outside the 8\.2 to 8\.5 range of laravel 12 \(overridden: fixture: PHP 8\.1 still runs on the staging container\)$/m',
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
