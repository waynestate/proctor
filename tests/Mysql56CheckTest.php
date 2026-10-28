<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\Mysql56Check;
use Waynestate\Proctor\Policy\JsonFilePolicySource;

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
            new JsonFilePolicySource(__DIR__ . '/fixtures/policy.json'),
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
