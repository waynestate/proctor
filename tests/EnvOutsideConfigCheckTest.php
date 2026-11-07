<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check (text format) on the env-outside-config fixture
 * apps and asserts the env-outside-config findings (or their absence).
 */
final class EnvOutsideConfigCheckTest extends ProctorTestCase
{
    /**
     * The env-outside-config lines of a text report, in output order.
     *
     * @return list<string>
     */
    private function envLines(string $stdout): array
    {
        preg_match_all('/^(?:PASS|WARN|FAIL|INFO) env-outside-config: .*$/m', $stdout, $matches);

        return $matches[0];
    }

    public function testCallsOutsideConfigWarnOncePerCall(): void
    {
        $stdout = $this->runProctor($this->app('env-outside-config-app'))['stdout'];
        $tail = ', outside config/; after php artisan config:cache it returns null. Read the variable in a config file and use config() here';

        // Not flagged: config/, tests/, bootstrap/cache/, a comment, a
        // string, a method named env, an imported function aliased from
        // another namespace, and Blade's @env directive. A call with
        // Larastan's ignore marker for the rule is an info note.
        self::assertSame([
            "WARN env-outside-config: env('APP_URL') is called in app/Http/Controllers/HomeController.php:12" . $tail,
            "WARN env-outside-config: env('APP_NAME') is called in resources/views/welcome.blade.php:1" . $tail,
            "WARN env-outside-config: env('APP_DEBUG') is called in routes/web.php:5" . $tail,
            "INFO env-outside-config: env('TRUSTED_PROXIES') in routes/web.php:10 carries @phpstan-ignore larastan.noEnvCallsOutsideOfConfig, so it is reviewed and not counted",
        ], $this->envLines($stdout));
    }

    public function testFindingsCarryPathLineAndRuleId(): void
    {
        $data = json_decode($this->runProctor($this->app('env-outside-config-app'), 'json')['stdout'], true);
        self::assertIsArray($data);

        $seen = [];
        foreach ($data['findings'] as $finding) {
            if ($finding['check_id'] === 'env-outside-config') {
                $seen[] = sprintf('%s %s %s:%d', $finding['rule_id'], $finding['status'], $finding['path'], $finding['line']);
            }
        }
        self::assertSame([
            'proctor/env-outside-config warning app/Http/Controllers/HomeController.php:12',
            'proctor/env-outside-config warning resources/views/welcome.blade.php:1',
            'proctor/env-outside-config warning routes/web.php:5',
            'proctor/env-outside-config/suppressed info routes/web.php:10',
        ], $seen);
    }

    public function testConfigOnlyAppPasses(): void
    {
        $stdout = $this->runProctor($this->app('env-outside-config-clean-app'))['stdout'];

        self::assertSame(
            ['PASS env-outside-config: env() is called only from config files'],
            $this->envLines($stdout),
        );
    }

    public function testAppWithoutLaravelProducesNoFinding(): void
    {
        $stdout = $this->runProctor($this->app('env-outside-config-raw-app'))['stdout'];

        self::assertSame([], $this->envLines($stdout));
    }
}
