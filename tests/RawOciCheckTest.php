<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use PHPUnit\Framework\Attributes\Test;

/**
 * Runs bin/proctor check (text format) on the raw-oci fixture apps and
 * asserts the raw-oci findings (or their absence).
 */
final class RawOciCheckTest extends ProctorTestCase
{
    /** The tail every raw-oci warning carries, after the call, file and line. */
    private const TAIL = ' talks to Oracle directly. Read through TELLER\'s named queries instead (bound values, a read-only transaction, row caps): https://git.wayne.edu/enterprise-apps/teller';

    /**
     * The raw-oci lines of a text report, in output order.
     *
     * @return list<string>
     */
    private function ociLines(string $stdout): array
    {
        preg_match_all('/^(?:PASS|WARN|FAIL|INFO) raw-oci: .*$/m', $stdout, $matches);

        return $matches[0];
    }

    #[Test]
    public function flagsEachDirectOracleCall(): void
    {
        $stdout = $this->runProctor($this->app('raw-oci-app'))['stdout'];
        $file = 'app/Legacy/Directory.php';

        self::assertSame([
            "WARN raw-oci: `oci_connect()` at {$file}:10" . self::TAIL,
            "WARN raw-oci: `ocilogon()` at {$file}:11" . self::TAIL,
            "WARN raw-oci: `oci_parse()` at {$file}:12" . self::TAIL,
            "WARN raw-oci: `new PDO('oci:...')` at {$file}:21" . self::TAIL,
        ], $this->ociLines($stdout));
    }

    #[Test]
    public function flagsAPdoOciDsn(): void
    {
        $data = json_decode($this->runProctor($this->app('raw-oci-app'), 'json')['stdout'], true);
        self::assertIsArray($data);

        $seen = [];
        foreach ($data['findings'] as $finding) {
            if ($finding['check_id'] === 'raw-oci') {
                $seen[] = sprintf('%s %s %s:%d', $finding['rule_id'], $finding['status'], $finding['path'], $finding['line']);
            }
        }
        self::assertSame([
            'proctor/raw-oci warning app/Legacy/Directory.php:10',
            'proctor/raw-oci warning app/Legacy/Directory.php:11',
            'proctor/raw-oci warning app/Legacy/Directory.php:12',
            'proctor/raw-oci warning app/Legacy/Directory.php:21',
        ], $seen);
    }

    #[Test]
    public function ignoresCommentsStringsAndMethodCalls(): void
    {
        $stdout = $this->runProctor($this->app('raw-oci-app'))['stdout'];

        // Lines 9 (a comment), 13 (a log string) and 15 ($conn->oci_parse())
        // name or call oci_parse without talking to Oracle, so the flagged
        // lines are exactly the four real calls.
        preg_match_all('/at app\/Legacy\/Directory\.php:(\d+)/', implode("\n", $this->ociLines($stdout)), $matches);
        self::assertSame(['10', '11', '12', '21'], $matches[1] ?? []);
    }

    #[Test]
    public function skipsVendorAndTests(): void
    {
        // Both skipped files really do call oci_connect, so a clean result
        // here means the skip list worked, not that the fixture was empty.
        $app = $this->app('raw-oci-app');
        foreach (['vendor/acme/oracle/src/Driver.php', 'tests/Feature/helpers.php'] as $skipped) {
            self::assertStringContainsString('oci_connect(', (string) file_get_contents($app . '/' . $skipped));
        }
        $stdout = $this->runProctor($app)['stdout'];

        self::assertSame(
            [],
            array_values(array_filter(
                $this->ociLines($stdout),
                static fn(string $line): bool => str_contains($line, 'vendor/') || str_contains($line, 'tests/'),
            )),
        );
    }

    #[Test]
    public function neverFails(): void
    {
        $stdout = $this->runProctor($this->app('raw-oci-app'))['stdout'];

        self::assertNotEmpty($this->ociLines($stdout));
        self::assertFalse((bool) preg_match('/^FAIL raw-oci: /m', $stdout));
    }

    #[Test]
    public function aCleanAppHasNoFindings(): void
    {
        $stdout = $this->runProctor($this->app('raw-oci-clean-app'))['stdout'];

        self::assertSame(
            ['PASS raw-oci: no app code talks to Oracle directly'],
            $this->ociLines($stdout),
        );
    }
}
