<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\Mysql56Check;
use Waynestate\Proctor\Creed\Policy;

/**
 * Calls the mysql56 check directly, the way bin/proctor builds its
 * context: the fixture policy and a fixed --today.
 */
final class Mysql56CheckTest extends TestCase
{
    /** The fixed date every run uses. */
    private const TODAY = '2026-10-27';

    /**
     * @param string $appPath the app to check
     */
    private function contextFor(string $appPath): CheckContext
    {
        return new CheckContext(
            $appPath,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY),
        );
    }

    /**
     * The (rule id, path, line) triples of the findings, in order.
     *
     * @param list<Finding> $findings
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function triples(array $findings): array
    {
        return array_map(
            static fn(Finding $finding): array => [
                explode(':', $finding->message, 2)[0],
                (string) $finding->path,
                $finding->line,
            ],
            $findings,
        );
    }

    public function testMysql56AppGivesExactlyTwelveWarnings(): void
    {
        $findings = (new Mysql56Check())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/mysql56-app'),
        );

        self::assertCount(12, $findings);
        foreach ($findings as $finding) {
            self::assertSame(Finding::STATUS_WARNING, $finding->status);
        }

        // Every rule id is covered by the fixture; the four rules added
        // for coverage appear exactly once.
        foreach (
            [
                'mysql56-generated',
                'mysql56-newer-column',
                'mysql56-introspection',
                'mysql56-upsert-alias',
            ] as $ruleId
        ) {
            self::assertCount(
                1,
                array_filter(
                    $findings,
                    static fn(Finding $finding): bool => str_starts_with($finding->message, $ruleId . ':'),
                ),
            );
        }

        // The check sorts by path, then line, so the twelve warnings come
        // out in this order.
        self::assertSame(
            [
                ['mysql56-json', 'app/Models/Thing.php', 12],
                ['mysql56-introspection', 'app/Models/Thing.php', 15],
                ['mysql56-string-default', 'app/Providers/AppServiceProvider.php', 1],
                ['mysql56-upsert-alias', 'config/database.php', 5],
                ['mysql56-json', 'database/migrations/2026_01_01_000000_create_things_table.php', 14],
                ['mysql56-string-length', 'database/migrations/2026_01_01_000000_create_things_table.php', 15],
                ['mysql56-current-date', 'database/migrations/2026_01_01_000000_create_things_table.php', 16],
                ['mysql56-generated', 'database/migrations/2026_01_01_000000_create_things_table.php', 17],
                ['mysql56-newer-column', 'database/migrations/2026_01_01_000000_create_things_table.php', 18],
                ['mysql56-rename', 'database/migrations/2026_01_01_000000_create_things_table.php', 23],
                ['mysql56-raw-sql', 'database/migrations/2026_01_01_000000_create_things_table.php', 26],
                ['mysql56-command', 'routes/console.php', 19],
            ],
            $this->triples($findings),
        );
    }

    public function testHttpJsonAndUnindexedLongStringGiveNoWarning(): void
    {
        // ->json() in app/ is an HTTP response or client, and the long
        // string in the migration has no key on its column, so neither
        // warns and the app passes clean.
        $findings = (new Mysql56Check())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/mysql56-scope-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no MySQL 5.6 blockers found', $findings[0]->message);
    }

    public function testOnlyIndexedLongStringsWarn(): void
    {
        // slug is unique() in its own chain and code gets a key from the
        // later $table->index('code') statement, so both warn on their
        // string() lines; note never gets a key and does not warn.
        $findings = (new Mysql56Check())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/mysql56-scope-index-app'),
        );

        self::assertCount(2, $findings);
        self::assertSame(
            [
                ['mysql56-string-length', 'database/migrations/2026_02_02_000000_create_keys_table.php', 13],
                ['mysql56-string-length', 'database/migrations/2026_02_02_000000_create_keys_table.php', 14],
            ],
            $this->triples($findings),
        );
    }

    public function testOwnMethodAppGivesOneNewerColumnWarningInTheMigration(): void
    {
        // Embedder's own vector() and self::instant() are app methods, not
        // columns: only the Blueprint call in the migration warns.
        $findings = (new Mysql56Check())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/mysql56-own-method-app'),
        );

        $newerColumn = array_filter(
            $findings,
            static fn(Finding $finding): bool => str_starts_with($finding->message, 'mysql56-newer-column:'),
        );
        foreach ($newerColumn as $finding) {
            self::assertSame(Finding::STATUS_WARNING, $finding->status);
        }
        self::assertSame(
            [
                ['mysql56-newer-column', 'database/migrations/2026_03_01_000000_create_docs_table.php', 13],
            ],
            $this->triples(array_values($newerColumn)),
        );
    }

    public function testAnAppMethodNamedVectorPasses(): void
    {
        // Only $this->vector() and self::instant() in an app class: no
        // receiver is a Blueprint, so the newer-column rule sees nothing.
        $appPath = sys_get_temp_dir() . '/proctor-mysql56-' . uniqid('', true);
        self::assertTrue(
            is_dir($appPath . '/app/Services') || mkdir($appPath . '/app/Services', 0777, true),
        );
        self::assertTrue(
            is_dir($appPath . '/app/Providers') || mkdir($appPath . '/app/Providers', 0777, true),
        );
        self::assertTrue(
            is_dir($appPath . '/database/migrations') || mkdir($appPath . '/database/migrations', 0777, true),
        );
        file_put_contents(
            $appPath . '/app/Services/Embedder.php',
            <<<'PHP'
<?php

namespace App\Services;

class Embedder
{
    public function vector(array $a): array
    {
        return $a;
    }

    public static function instant(): string
    {
        return 'instant';
    }

    public function embed(array $a): array
    {
        return $this->vector($a);
    }

    public function stamp(): string
    {
        return self::instant();
    }
}
PHP,
        );
        file_put_contents(
            $appPath . '/app/Providers/AppServiceProvider.php',
            <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);
    }
}
PHP,
        );

        try {
            $findings = (new Mysql56Check())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
            self::assertSame('no MySQL 5.6 blockers found', $findings[0]->message);
        } finally {
            unlink($appPath . '/app/Services/Embedder.php');
            rmdir($appPath . '/app/Services');
            unlink($appPath . '/app/Providers/AppServiceProvider.php');
            rmdir($appPath . '/app/Providers');
            rmdir($appPath . '/app');
            rmdir($appPath . '/database/migrations');
            rmdir($appPath . '/database');
            rmdir($appPath);
        }
    }

    public function testCleanAppGivesExactlyOnePassFinding(): void
    {
        $findings = (new Mysql56Check())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/mysql56-clean-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no MySQL 5.6 blockers found', $findings[0]->message);
    }

    public function testAppWithoutMigrationsGivesTheSkippedPassFinding(): void
    {
        // portability-app has no database/ folder at all.
        $findings = (new Mysql56Check())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/portability-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no Laravel migrations found; skipped', $findings[0]->message);
    }

    public function testCommentOnlyDbShowIsNotFlagged(): void
    {
        // The db:show text appears only in a comment, so the AST-based
        // command rule must not flag it (the regex version did).
        $appPath = sys_get_temp_dir() . '/proctor-mysql56-' . uniqid('', true);
        self::assertTrue(
            is_dir($appPath . '/database/migrations') || mkdir($appPath . '/database/migrations', 0777, true),
        );
        file_put_contents(
            $appPath . '/database/migrations/2026_01_01_000000_create_things_table.php',
            <<<'PHP'
<?php

return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        // The db:show command is handy for inspecting the schema.
    }
};
PHP,
        );

        try {
            $findings = (new Mysql56Check())->run($this->contextFor($appPath));

            self::assertCount(
                0,
                array_filter(
                    $findings,
                    static fn(Finding $finding): bool => str_starts_with($finding->message, 'mysql56-command:'),
                ),
            );
        } finally {
            unlink($appPath . '/database/migrations/2026_01_01_000000_create_things_table.php');
            rmdir($appPath . '/database/migrations');
            rmdir($appPath . '/database');
            rmdir($appPath);
        }
    }

    public function testMultiLineRawSqlIsCaught(): void
    {
        // A DB::statement call that spans several lines is still one call to
        // the AST, so the raw-sql rule sees the whole SQL string (the regex
        // version only looked at the line that named DB::statement).
        $appPath = sys_get_temp_dir() . '/proctor-mysql56-' . uniqid('', true);
        self::assertTrue(
            is_dir($appPath . '/database/migrations') || mkdir($appPath . '/database/migrations', 0777, true),
        );
        file_put_contents(
            $appPath . '/database/migrations/2026_01_01_000000_create_things_table.php',
            <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE things
             ALGORITHM=INSTANT,
             ADD COLUMN x INT'
        );
    }
};
PHP,
        );

        try {
            $findings = (new Mysql56Check())->run($this->contextFor($appPath));

            self::assertCount(
                1,
                array_filter(
                    $findings,
                    static fn(Finding $finding): bool => str_starts_with($finding->message, 'mysql56-raw-sql:'),
                ),
            );
        } finally {
            unlink($appPath . '/database/migrations/2026_01_01_000000_create_things_table.php');
            rmdir($appPath . '/database/migrations');
            rmdir($appPath . '/database');
            rmdir($appPath);
        }
    }
}
