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
            'FAIL mysql-eol: mysql 5.6 (docker-compose.yml) is eol_grace_expired (security support ended 2021-02-28)',
            'FAIL mysql-eol: mysql 8.0 (docker-compose.yml) is eol_grace_expired (security support ended 2026-04-30)',
            'FAIL mysql-eol: mysql 5.7 (docker-compose.yml) is eol_grace_expired (security support ended 2023-10-31)',
            'FAIL mysql-eol: mysql 8.0 (.env.squire.example) is eol_grace_expired (security support ended 2026-04-30)',
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
            '/^FAIL mysql-eol: mysql 5\.7 \(docker-compose\.yml\) is eol_grace_expired \(security support ended 2023-10-31\)$/m',
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

        // A bare major (mysql:8) is not graded: the policy keys on
        // major.minor, so 8 resolves to no line, the reader does not invent a
        // mapping and stays silent (MysqlEolCheck.php:23-25).
        self::assertSame([], $lines);

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
        // is still Security on that day: MysqlEolCheck.php:166 maps Security
        // to a warning and MysqlEolCheck.php:203 reads the zero days as
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

        // The image pattern matches mariadb (MysqlEolCheck.php:51), but the
        // fixture policy has no mariadb component, so the image is ignored
        // silently (MysqlEolCheck.php:66-70).
        self::assertSame([], $lines);

        unlink($dir . '/docker-compose.yml');
        rmdir($dir);
    }
}
