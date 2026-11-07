<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check (text format) on the app-key fixture apps and
 * asserts the app-key findings. The CLI tests run with a PATH that finds no
 * git, so "committed" means the files on disk; a real env file is graded
 * only when the run says it is in CI.
 */
final class AppKeyCheckTest extends ProctorTestCase
{
    private const KEY_TAIL = 'Whoever holds the key can forge encrypted payloads the app will unserialize, which can mean code execution. Remove it and rotate the key (php artisan key:generate) on every environment that used it';

    private const COOKIE_TAIL = 'keeps each session in a cookie sealed with the APP_KEY, so a leaked key opens every session; use the database or redis driver';

    /**
     * The app-key lines of a text report, in output order.
     *
     * @return list<string>
     */
    private function keyLines(string $stdout): array
    {
        preg_match_all('/^(?:PASS|WARN|FAIL|INFO) app-key: .*$/m', $stdout, $matches);

        return $matches[0];
    }

    public function testExampleAndConfigFilesAreGradedOutsideCi(): void
    {
        $stdout = $this->runProctor($this->app('app-key-leaky-app'))['stdout'];

        // .env is not graded: outside CI and without git, PROCTOR cannot tell
        // a committed .env from the one on a developer's laptop. .env.testing
        // holds a throwaway key and is skipped.
        self::assertSame([
            'WARN app-key: .env.example:2 commits a real APP_KEY (APP_KEY). ' . self::KEY_TAIL,
            'WARN app-key: .env.example:3: SESSION_DRIVER=cookie ' . self::COOKIE_TAIL,
            'WARN app-key: config/app.php:4 commits a real APP_KEY (a literal key). ' . self::KEY_TAIL,
            "WARN app-key: config/session.php:4: the session driver defaults to 'cookie' " . self::COOKIE_TAIL,
        ], $this->keyLines($stdout));
        self::assertStringNotContainsString('cHJvY3Rvci1maXh0dXJl', $stdout, 'the key itself is never printed');
    }

    public function testCommittedEnvIsGradedInCi(): void
    {
        $stdout = $this->runProctor(
            $this->app('app-key-leaky-app'),
            'text',
            null,
            null,
            null,
            null,
            ['CI' => 'true', 'PROCTOR_ALLOW_OVERRIDES' => '1'],
        )['stdout'];
        $lines = $this->keyLines($stdout);

        self::assertSame(
            'WARN app-key: .env is committed; an env file holds the real secrets, the APP_KEY among them. Run git rm --cached .env, add it to .gitignore, commit a .env.example with placeholders, and rotate every secret it held',
            $lines[0],
        );
        self::assertSame('WARN app-key: .env:1 commits a real APP_KEY (APP_KEY). ' . self::KEY_TAIL, $lines[1]);
        self::assertCount(6, $lines);
    }

    public function testRuleIds(): void
    {
        $data = json_decode($this->runProctor($this->app('app-key-leaky-app'), 'json')['stdout'], true);
        self::assertIsArray($data);

        $ids = [];
        foreach ($data['findings'] as $finding) {
            if ($finding['check_id'] === 'app-key') {
                $ids[] = $finding['rule_id'];
            }
        }
        self::assertSame([
            'proctor/app-key/committed-key',
            'proctor/app-key/cookie-sessions',
            'proctor/app-key/committed-key',
            'proctor/app-key/cookie-sessions',
        ], $ids);
    }

    public function testPlaceholdersPass(): void
    {
        $stdout = $this->runProctor($this->app('app-key-clean-app'))['stdout'];

        self::assertSame(
            ['PASS app-key: no committed env file, APP_KEY or cookie session driver found'],
            $this->keyLines($stdout),
        );
    }

    public function testAppWithoutEnvOrConfigProducesNoFinding(): void
    {
        $stdout = $this->runProctor($this->app('pass-app'))['stdout'];

        self::assertSame([], $this->keyLines($stdout));
    }
}
