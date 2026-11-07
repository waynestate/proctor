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
}
