<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check (text format) on the mysql-eol fixture app with the
 * mysql fixture policy and asserts the mysql-eol findings (or their absence).
 */
final class MysqlEolCheckTest extends ProctorTestCase
{
    private function policy(): string
    {
        return __DIR__ . '/fixtures/policy-mysql.json';
    }

    /**
     * The mysql-eol lines of a text report, in output order.
     *
     * @return list<string>
     */
    private function mysqlLines(string $stdout): array
    {
        preg_match_all('/^(?:PASS|WARN|FAIL|INFO) mysql-eol: .*$/m', $stdout, $matches);

        return $matches[0];
    }

    public function testNamedVersionsAreGradedThroughTheStateTable(): void
    {
        $result = $this->runProctor($this->app('mysql-eol-app'), 'text', $this->policy());

        // The third line is the digest-pinned image: its tag 5.7 is graded
        // even though the reference carries a sha256 digest.
        self::assertSame([
            'WARN mysql-eol: mysql 5.6 (docker-compose.yml) is eol_grace_expired (security support ended 2021-02-28)',
            'WARN mysql-eol: mysql 8.0 (docker-compose.yml) is eol_grace_expired (security support ended 2026-04-30)',
            'WARN mysql-eol: mysql 5.7 (docker-compose.yml) is eol_grace_expired (security support ended 2023-10-31)',
            'WARN mysql-eol: mysql 8.0 (.env.squire.example) is eol_grace_expired (security support ended 2026-04-30)',
        ], $this->mysqlLines($result['stdout']));
    }

    public function testSecurityOnlySupportWarns(): void
    {
        $stdout = $this->runProctor($this->app('mysql-eol-app'), 'text', $this->policy(), null, '2025-10-01')['stdout'];

        self::assertMatchesRegularExpression(
            '/^WARN mysql-eol: mysql 8\.0 \(docker-compose\.yml\) is in security-only support until 2026-04-30 \(\d+ days left\)$/m',
            $stdout,
        );
        self::assertMatchesRegularExpression(
            '/^WARN mysql-eol: mysql 8\.0 \(\.env\.squire\.example\) is in security-only support until 2026-04-30 \(\d+ days left\)$/m',
            $stdout,
        );
    }

    public function testActiveVersionIsSilent(): void
    {
        $lines = $this->mysqlLines($this->runProctor($this->app('mysql-eol-app'), 'text', $this->policy())['stdout']);

        foreach ($lines as $line) {
            self::assertStringNotContainsString('8.4', $line);
        }
    }

    public function testDigestPinnedImageIsGradedByItsTag(): void
    {
        $stdout = $this->runProctor($this->app('mysql-eol-app'), 'text', $this->policy())['stdout'];

        self::assertMatchesRegularExpression(
            '/^WARN mysql-eol: mysql 5\.7 \(docker-compose\.yml\) is eol_grace_expired \(security support ended 2023-10-31\)$/m',
            $stdout,
        );
    }

    public function testRuleIdsInJson(): void
    {
        $data = json_decode($this->runProctor($this->app('mysql-eol-app'), 'json', $this->policy())['stdout'], true);
        self::assertIsArray($data);

        $ids = [];
        foreach ($data['findings'] as $finding) {
            if ($finding['check_id'] === 'mysql-eol') {
                $ids[] = $finding['rule_id'] . ' ' . $finding['path'] . ':' . $finding['line'];
            }
        }
        self::assertSame([
            'proctor/mysql-eol docker-compose.yml:3',
            'proctor/mysql-eol docker-compose.yml:5',
            'proctor/mysql-eol docker-compose.yml:9',
            'proctor/mysql-eol .env.squire.example:1',
        ], $ids);
    }

    public function testSilentWhenThePolicyHasNoMysql(): void
    {
        $stdout = $this->runProctor($this->app('mysql-eol-app'))['stdout'];

        self::assertSame([], $this->mysqlLines($stdout));
    }

    public function testSilentWhenTheRepoNamesNoMysql(): void
    {
        $stdout = $this->runProctor($this->app('pass-app'), 'text', $this->policy())['stdout'];

        self::assertSame([], $this->mysqlLines($stdout));
    }

    public function testBareMajorTagIsGraded(): void
    {
        $dir = sys_get_temp_dir() . '/proctor-mysql-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/docker-compose.yml', "services:\n  db:\n    image: mysql:8\n");

        $lines = $this->mysqlLines($this->runProctor($dir, 'text', $this->policy(), null, '2026-11-08')['stdout']);

        // A bare major (mysql:8) is a floating tag: the policy keys on
        // major.minor, so 8 resolves to no line and the reader does not
        // invent a mapping. The policy lists lines in major 8 (8.0 and 8.4),
        // so the check warns instead of staying silent, naming the highest
        // listed line as the pin advice (MysqlEolCheck.php:25-29).
        self::assertSame([
            'WARN mysql-eol: mysql 8 (docker-compose.yml) is a floating tag; pin a major.minor such as 8.4 so the check can grade it',
        ], $lines);

        unlink($dir . '/docker-compose.yml');
        rmdir($dir);
    }

    public function testBareMajorOfAnEolLineWarnsToPin(): void
    {
        $dir = sys_get_temp_dir() . '/proctor-mysql-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/docker-compose.yml', "services:\n  db:\n    image: mysql:5\n");

        $lines = $this->mysqlLines($this->runProctor($dir, 'text', $this->policy(), null, '2026-11-08')['stdout']);

        // mysql:5 floats to 5.7, which is past end of life, but the check
        // does not grade it as a guessed line: it warns with the pin advice,
        // naming the highest 5.x line the fixture policy lists (5.7).
        self::assertSame([
            'WARN mysql-eol: mysql 5 (docker-compose.yml) is a floating tag; pin a major.minor such as 5.7 so the check can grade it',
        ], $lines);

        unlink($dir . '/docker-compose.yml');
        rmdir($dir);
    }

    public function testLastDayOfSecuritySupportStillWarns(): void
    {
        $dir = sys_get_temp_dir() . '/proctor-mysql-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/docker-compose.yml', "services:\n  db:\n    image: mysql:8.0\n");

        $lines = $this->mysqlLines($this->runProctor($dir, 'text', $this->policy(), null, '2026-04-30')['stdout']);

        // 2026-04-30 is the policy's security_until for 8.0 and the boundary
        // is inclusive (Policy.php:168, $day <= $securityUntil), so the state
        // is still Security on that day: MysqlEolCheck.php:179 warns every
        // graded state and MysqlEolCheck.php:248 reads the zero days as
        // "ends today".
        self::assertSame([
            'WARN mysql-eol: mysql 8.0 (docker-compose.yml) is in security-only support until 2026-04-30 (ends today)',
        ], $lines);

        unlink($dir . '/docker-compose.yml');
        rmdir($dir);
    }

    public function testMariadbImageIsHandled(): void
    {
        $dir = sys_get_temp_dir() . '/proctor-mysql-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/docker-compose.yml', "services:\n  db:\n    image: mariadb:10.6\n");

        $lines = $this->mysqlLines($this->runProctor($dir, 'text', $this->policy(), null, '2026-11-08')['stdout']);

        // The image pattern matches mariadb (MysqlEolCheck.php:55), but the
        // fixture policy has no mariadb component, so the image is ignored
        // silently (MysqlEolCheck.php:70-74).
        self::assertSame([], $lines);

        unlink($dir . '/docker-compose.yml');
        rmdir($dir);
    }

    public function test_an_end_of_life_mysql_warns_and_the_run_still_passes(): void
    {
        // A minimal app whose only finding is an eol MySQL image: the
        // composer pair mirrors pass-app (config.platform.php keeps
        // composer-config quiet, the release cooldown keeps
        // composer-cooldown quiet) and the GitLab pipeline runs composer ci
        // and includes the Preflight proctor gate, so preflight-gates passes.
        $dir = sys_get_temp_dir() . '/proctor-mysql-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/docker-compose.yml', "services:\n  db:\n    image: mysql:8.0\n");
        file_put_contents($dir . '/composer.json', "{\n    \"config\": {\n        \"platform\": {\n            \"php\": \"8.4.0\"\n        },\n        \"policy\": {\n            \"cooldown\": {\n                \"period\": \"3 days\",\n                \"ignore\": [\"waynestate/*\"]\n            }\n        }\n    },\n    \"require\": {\n        \"php\": \"^8.4\",\n        \"symfony/console\": \"^7.1\"\n    }\n}\n");
        file_put_contents($dir . '/composer.lock', "{\n    \"packages\": [],\n    \"packages-dev\": [],\n    \"content-hash\": \"fixture\"\n}\n");
        file_put_contents($dir . '/.gitlab-ci.yml', "# composer ci is the contract: the checks are defined there, and this pipeline only wraps them.\ninclude:\n  - component: \$CI_SERVER_FQDN/enterprise-apps/preflight/proctor@0.4.0\ncomposer-ci:\n  image: composer:2.10.3\n  script:\n    - composer install --no-interaction --no-progress --prefer-dist\n    - composer ci\n");

        // A stub composer on PATH answers the audit with a clean result and
        // the version probe with a current version, so composer-audit and
        // composer-version pass and the eol MySQL warning stays the run's
        // only note.
        $bin = sys_get_temp_dir() . '/proctor-mysql-bin-' . bin2hex(random_bytes(4));
        mkdir($bin);
        $composer = $bin . '/composer';
        file_put_contents($composer, "#!/bin/sh\nfor arg in \"\$@\"; do\n  if [ \"\$arg\" = audit ]; then\n    printf '{\"advisories\": [], \"abandoned\": []}\\n'\n    exit 0\n  fi\ndone\necho \"Composer version 2.10.3 2026-05-19 13:50:22\"\nexit 0\n");
        chmod($composer, 0755);

        $result = $this->runProctor($dir, 'text', $this->policy(), null, null, $bin);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertMatchesRegularExpression(
            '/^WARN mysql-eol: mysql 8\.0 \(docker-compose\.yml\) is eol_grace_expired \(security support ended 2026-04-30\)$/m',
            $result['stdout'],
        );
        self::assertSame(['mysql-eol'], $this->nonPassCheckIds($result['stdout']));

        unlink($composer);
        rmdir($bin);
        foreach (['docker-compose.yml', 'composer.json', 'composer.lock', '.gitlab-ci.yml'] as $file) {
            unlink($dir . '/' . $file);
        }
        rmdir($dir);
    }
}
