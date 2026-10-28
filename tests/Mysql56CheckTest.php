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
            new DateTimeImmutable(self::TODAY)
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
            static fn (Finding $finding): array => [
                explode(':', $finding->message, 2)[0],
                (string) $finding->path,
                $finding->line,
            ],
            $findings
        );
    }

    public function testMysql56AppGivesExactlyEightWarnings(): void
    {
        $findings = (new Mysql56Check())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/mysql56-app')
        );

        self::assertCount(8, $findings);
        foreach ($findings as $finding) {
            self::assertSame(Finding::STATUS_WARNING, $finding->status);
        }

        // The check sorts by path, then line, so the eight warnings come
        // out in this order.
        self::assertSame(
            [
                ['mysql56-json', 'app/Models/Thing.php', 11],
                ['mysql56-string-default', 'app/Providers/AppServiceProvider.php', 1],
                ['mysql56-json', 'database/migrations/2026_01_01_000000_create_things_table.php', 14],
                ['mysql56-string-length', 'database/migrations/2026_01_01_000000_create_things_table.php', 15],
                ['mysql56-current-date', 'database/migrations/2026_01_01_000000_create_things_table.php', 16],
                ['mysql56-rename', 'database/migrations/2026_01_01_000000_create_things_table.php', 21],
                ['mysql56-raw-sql', 'database/migrations/2026_01_01_000000_create_things_table.php', 24],
                ['mysql56-command', 'routes/console.php', 11],
            ],
            $this->triples($findings)
        );
    }

    public function testCleanAppGivesExactlyOnePassFinding(): void
    {
        $findings = (new Mysql56Check())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/mysql56-clean-app')
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no MySQL 5.6 blockers found', $findings[0]->message);
    }

    public function testAppWithoutMigrationsGivesTheSkippedPassFinding(): void
    {
        // portability-app has no database/ folder at all.
        $findings = (new Mysql56Check())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/portability-app')
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no Laravel migrations found; skipped', $findings[0]->message);
    }
}
