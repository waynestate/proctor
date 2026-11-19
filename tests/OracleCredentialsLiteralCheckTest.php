<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use PHPUnit\Framework\Attributes\Test;

/**
 * Runs bin/proctor check (text format) on the oracle-credentials-literal
 * fixture apps and asserts the oracle-credentials-literal findings (or their
 * absence).
 */
final class OracleCredentialsLiteralCheckTest extends ProctorTestCase
{
    /** The secret the fixtures write; it must never reach a report line. */
    private const SECRET = 'not-a-real-password';

    /**
     * The oracle-credentials-literal lines of a text report, in output order.
     *
     * @return list<string>
     */
    private function credLines(string $stdout): array
    {
        preg_match_all('/^(?:PASS|WARN|FAIL|INFO) oracle-credentials-literal: .*$/m', $stdout, $matches);

        return $matches[0];
    }

    #[Test]
    public function flagsALiteralPasswordInOciConnect(): void
    {
        $stdout = $this->runProctor($this->app('oracle-credentials-literal-app'))['stdout'];
        $file = 'app/Legacy/Connect.php';

        self::assertContains(
            "WARN oracle-credentials-literal: `oci_connect()` at {$file}:10 has its password written in the code. Read it from the environment (`TELLER_PASSWORD` or `TELLER_PASSWORD_FILE` with TELLER): https://git.wayne.edu/enterprise-apps/teller",
            $this->credLines($stdout),
        );
    }

    #[Test]
    public function flagsAnEzconnectStringWithAPassword(): void
    {
        $stdout = $this->runProctor($this->app('oracle-credentials-literal-app'))['stdout'];
        $file = 'app/Legacy/Connect.php';

        self::assertContains(
            "WARN oracle-credentials-literal: A connect string at {$file}:27 carries a password. Read it from the environment instead: https://git.wayne.edu/enterprise-apps/teller",
            $this->credLines($stdout),
        );
    }

    #[Test]
    public function flagsACommittedWallet(): void
    {
        $stdout = $this->runProctor($this->app('oracle-credentials-literal-app'))['stdout'];

        self::assertContains(
            'WARN oracle-credentials-literal: `config/oracle/wallet/cwallet.sso` is an Oracle wallet committed to the repository. Remove it from git and mount it at deploy time: https://git.wayne.edu/enterprise-apps/teller',
            $this->credLines($stdout),
        );
    }

    #[Test]
    public function flagsATnsnamesFileWithAPassword(): void
    {
        $stdout = $this->runProctor($this->app('oracle-credentials-literal-app'))['stdout'];

        self::assertContains(
            'WARN oracle-credentials-literal: `config/oracle/tnsnames.ora` line 6 carries a password. Keep only the connect descriptors in the repository and read the password from the environment',
            $this->credLines($stdout),
        );
    }

    #[Test]
    public function ignoresPasswordsFromEnvAndVariables(): void
    {
        // Lines 17 to 20 hold a getenv result, a variable, an empty string
        // and a method call named like the OCI function, and line 33 holds a
        // plain path, so the flagged lines of the file are exactly the
        // literal-password call and the connect string.
        $stdout = $this->runProctor($this->app('oracle-credentials-literal-app'))['stdout'];
        preg_match_all('/at app\/Legacy\/Connect\.php:(\d+)/', implode("\n", $this->credLines($stdout)), $matches);
        self::assertSame(['10', '27'], $matches[1] ?? []);
    }

    #[Test]
    public function skipsVendorAndTests(): void
    {
        // Both skipped files really do write the password into an
        // oci_connect, so a clean result here means the skip list worked,
        // not that the fixture was empty.
        $app = $this->app('oracle-credentials-literal-app');
        foreach (['vendor/acme/oracle/src/Driver.php', 'tests/Feature/helpers.php'] as $skipped) {
            self::assertStringContainsString(
                "oci_connect('report_ro', '" . self::SECRET,
                (string) file_get_contents($app . '/' . $skipped),
            );
        }
        $stdout = $this->runProctor($app)['stdout'];

        self::assertSame(
            [],
            array_values(array_filter(
                $this->credLines($stdout),
                static fn(string $line): bool => str_contains($line, 'vendor/') || str_contains($line, 'tests/'),
            )),
        );
    }

    #[Test]
    public function neverPrintsTheSecret(): void
    {
        $stdout = $this->runProctor($this->app('oracle-credentials-literal-app'))['stdout'];
        $lines = $this->credLines($stdout);
        self::assertNotEmpty($lines);

        foreach ($lines as $line) {
            self::assertStringNotContainsString(self::SECRET, $line);
        }
    }

    #[Test]
    public function neverFails(): void
    {
        $stdout = $this->runProctor($this->app('oracle-credentials-literal-app'))['stdout'];

        self::assertNotEmpty($this->credLines($stdout));
        self::assertFalse((bool) preg_match('/^FAIL oracle-credentials-literal: /m', $stdout));
    }

    #[Test]
    public function aCleanAppHasNoFindings(): void
    {
        $stdout = $this->runProctor($this->app('oracle-credentials-literal-clean-app'))['stdout'];

        self::assertSame(
            ['PASS oracle-credentials-literal: no Oracle passwords are written into the repository'],
            $this->credLines($stdout),
        );
    }
}
