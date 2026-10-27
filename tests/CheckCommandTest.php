<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check (text format) on each fixture app and asserts the
 * exit code and the check ids that fail.
 */
final class CheckCommandTest extends ProctorTestCase
{
    public function testPassAppExitsZeroWithNoFailures(): void
    {
        $result = $this->runProctor($this->app('pass-app'));

        self::assertSame(0, $result['exit']);
        self::assertSame([], $this->failCheckIds($result['stdout']));
    }

    public function testPassAppPassesFloorUnboundedAndLock(): void
    {
        $stdout = $this->runProctor($this->app('pass-app'))['stdout'];

        self::assertMatchesRegularExpression('/^PASS php-floor: /m', $stdout);
        self::assertMatchesRegularExpression('/^PASS unbounded-constraints: /m', $stdout);
        self::assertMatchesRegularExpression('/^PASS lock-committed: /m', $stdout);
    }

    public function testPassAppReportsInfoForComposerAudit(): void
    {
        $stdout = $this->runProctor($this->app('pass-app'))['stdout'];

        self::assertMatchesRegularExpression('/^INFO composer-audit: /m', $stdout);
    }

    public function testEolAppExitsOneAndFailsOnlyPhpFloor(): void
    {
        $result = $this->runProctor($this->app('eol-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(['php-floor'], $this->failCheckIds($result['stdout']));
    }

    public function testUnboundedAppFailsUnboundedConstraintsForDevMasterAndStar(): void
    {
        $result = $this->runProctor($this->app('unbounded-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(
            ['unbounded-constraints', 'unbounded-constraints'],
            $this->failCheckIds($result['stdout'])
        );
        self::assertStringContainsString('vendor/dev constraint "dev-master" is unbounded', $result['stdout']);
        self::assertStringContainsString('vendor/any constraint "*" is unbounded', $result['stdout']);
    }

    public function testExpiredOverrideAppFailsOverridesAndPrintsTheOverrideLine(): void
    {
        $result = $this->runProctor($this->app('expired-override-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(['overrides'], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^OVERRIDE lock-committed: fixture: expired \(expires 2026-01-01, approved by tester\)$/m',
            $result['stdout']
        );
    }
}
