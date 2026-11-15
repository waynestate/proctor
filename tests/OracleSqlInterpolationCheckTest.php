<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use PHPUnit\Framework\Attributes\Test;

/**
 * Runs bin/proctor check (text format) on the oracle-sql-interpolation
 * fixture apps and asserts the oracle-sql-interpolation findings (or their
 * absence).
 */
final class OracleSqlInterpolationCheckTest extends ProctorTestCase
{
    /** The tail every oracle-sql-interpolation warning carries, after the call, file and line. */
    private const TAIL = ' builds its SQL from PHP values. Bind them instead (`:name` placeholders with `oci_bind_by_name`, or the bindings array), or read through TELLER\'s named queries: https://git.wayne.edu/enterprise-apps/teller';

    /**
     * The oracle-sql-interpolation lines of a text report, in output order.
     *
     * @return list<string>
     */
    private function sqlLines(string $stdout): array
    {
        preg_match_all('/^(?:PASS|WARN|FAIL|INFO) oracle-sql-interpolation: .*$/m', $stdout, $matches);

        return $matches[0];
    }

    #[Test]
    public function flagsInterpolatedSqlInOciParse(): void
    {
        $stdout = $this->runProctor($this->app('oracle-sql-interpolation-app'))['stdout'];
        $file = 'app/Legacy/Reports.php';

        self::assertContains(
            "WARN oracle-sql-interpolation: `oci_parse()` at {$file}:12" . self::TAIL,
            $this->sqlLines($stdout),
        );
    }

    #[Test]
    public function flagsConcatenatedSqlInOciParse(): void
    {
        $stdout = $this->runProctor($this->app('oracle-sql-interpolation-app'))['stdout'];
        $file = 'app/Legacy/Reports.php';

        self::assertContains(
            "WARN oracle-sql-interpolation: `ociparse()` at {$file}:18" . self::TAIL,
            $this->sqlLines($stdout),
        );
    }

    #[Test]
    public function flagsInterpolatedSqlInTheDbFacadeOnYajraApps(): void
    {
        $stdout = $this->runProctor($this->app('oracle-sql-interpolation-app'))['stdout'];
        $file = 'app/Legacy/Reports.php';

        self::assertContains(
            "WARN oracle-sql-interpolation: `DB::select()` at {$file}:41" . self::TAIL,
            $this->sqlLines($stdout),
        );
    }

    #[Test]
    public function ignoresTheDbFacadeWithoutYajra(): void
    {
        // The fixture really does interpolate a DB::select, so a clean result
        // here means the yajra gate worked, not that the fixture was empty.
        $app = $this->app('oracle-sql-interpolation-noyajra-app');
        self::assertStringContainsString(
            'DB::select("SELECT',
            (string) file_get_contents($app . '/app/Legacy/Reports.php'),
        );
        self::assertStringNotContainsString(
            'yajra/laravel-oci8',
            (string) file_get_contents($app . '/composer.json'),
        );
        $stdout = $this->runProctor($app)['stdout'];

        self::assertSame(
            ['PASS oracle-sql-interpolation: no Oracle SQL is built from PHP values'],
            $this->sqlLines($stdout),
        );
    }

    #[Test]
    public function ignoresLiteralsVariablesAndBoundSql(): void
    {
        $stdout = $this->runProctor($this->app('oracle-sql-interpolation-app'))['stdout'];

        // Lines 26 to 35 and 47 to 49 hold a literal, a bound placeholder, a
        // variable, a literal-only concatenation, a nowdoc, a sprintf result,
        // a method call named oci_parse and a query builder chain, so the
        // flagged lines are exactly the three built-SQL calls.
        preg_match_all('/at app\/Legacy\/Reports\.php:(\d+)/', implode("\n", $this->sqlLines($stdout)), $matches);
        self::assertSame(['12', '18', '41'], $matches[1] ?? []);
    }

    #[Test]
    public function skipsVendorAndTests(): void
    {
        // Both skipped files really do build SQL from a PHP value in an
        // interpolated oci_parse, so a clean result here means the skip list
        // worked, not that the fixture was empty.
        $app = $this->app('oracle-sql-interpolation-app');
        foreach (['vendor/acme/oracle/src/Driver.php', 'tests/Feature/helpers.php'] as $skipped) {
            self::assertStringContainsString('oci_parse($conn, "', (string) file_get_contents($app . '/' . $skipped));
        }
        $stdout = $this->runProctor($app)['stdout'];

        self::assertSame(
            [],
            array_values(array_filter(
                $this->sqlLines($stdout),
                static fn(string $line): bool => str_contains($line, 'vendor/') || str_contains($line, 'tests/'),
            )),
        );
    }

    #[Test]
    public function neverFails(): void
    {
        $stdout = $this->runProctor($this->app('oracle-sql-interpolation-app'))['stdout'];

        self::assertNotEmpty($this->sqlLines($stdout));
        self::assertFalse((bool) preg_match('/^FAIL oracle-sql-interpolation: /m', $stdout));
    }

    #[Test]
    public function aCleanAppHasNoFindings(): void
    {
        $stdout = $this->runProctor($this->app('oracle-sql-interpolation-clean-app'))['stdout'];

        self::assertSame(
            ['PASS oracle-sql-interpolation: no Oracle SQL is built from PHP values'],
            $this->sqlLines($stdout),
        );
    }
}
