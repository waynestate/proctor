<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\Mysql56CiCheck;
use Waynestate\Proctor\Creed\Policy;

/**
 * Tests the Mysql56CiCheck on temp app dirs: an app with migrations must run
 * them against MySQL 5.6 in CI (CREED databases), and the check stays silent
 * for an app without migrations, without a pipeline, or with Preflight's
 * mysql56-migrate component wired in.
 */
final class Mysql56CiCheckTest extends TestCase
{
    /** A pipeline that never runs the migrations against MySQL 5.6. */
    private const PIPELINE = "composer-ci:\n  script:\n    - composer ci\n";

    private string $appPath = '';

    protected function tearDown(): void
    {
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
            $this->appPath = '';
        }
    }

    public function testAnAppWithoutMigrationsIsSilent(): void
    {
        $this->writePipeline(self::PIPELINE);

        self::assertSame([], $this->runCheck());
    }

    public function testAnEmptyMigrationsFolderIsSilent(): void
    {
        mkdir($this->appDir() . '/database/migrations');
        $this->writePipeline(self::PIPELINE);

        self::assertSame([], $this->runCheck());
    }

    public function testMigrationsWithoutAPipelineAreSilent(): void
    {
        $this->writeMigration();

        self::assertSame([], $this->runCheck());
    }

    public function testAUnparsablePipelineIsSilent(): void
    {
        $this->writeMigration();
        $this->writePipeline("include:\n  - component: [unclosed\n");

        self::assertSame([], $this->runCheck());
    }

    public function testMigrationsWhosePipelineNeverRunsThemOn56WarnOnce(): void
    {
        $this->writeMigration();
        $this->writePipeline(self::PIPELINE);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/mysql56-ci/no-job', $findings[0]->ruleId);
        self::assertSame('.gitlab-ci.yml', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame(
            "migrations in database/migrations/ never run against MySQL 5.6 in CI: include Preflight's mysql56-migrate component (CREED databases.md)",
            $findings[0]->message,
        );
    }

    public function testAnIncludeOfTheMysql56MigrateComponentIsSilent(): void
    {
        $this->writeMigration();
        $this->writePipeline(<<<'YAML'
include:
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/php-test@0.4.0
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/mysql56-migrate@0.6.0
YAML);

        self::assertSame([], $this->runCheck());
    }

    public function testAJobNamedMysql56MigrateIsSilent(): void
    {
        $this->writeMigration();
        $this->writePipeline(<<<'YAML'
mysql56-migrate:
  image: mysql:5.6
  script:
    - php artisan migrate --force
YAML);

        self::assertSame([], $this->runCheck());
    }

    public function testTheCheckIsRegisteredAndNotInTheDeployGate(): void
    {
        self::assertNotContains('mysql56-ci', CheckSet::GATE);
    }

    /**
     * Runs the check on the temp app as built by the test.
     *
     * @return list<Finding>
     */
    private function runCheck(): array
    {
        return (new Mysql56CiCheck())->run($this->context());
    }

    /**
     * Writes one migration into the temp app's database/migrations/.
     */
    private function writeMigration(): void
    {
        $dir = $this->appDir() . '/database/migrations';
        self::assertTrue(is_dir($dir) || mkdir($dir, 0777, true));
        file_put_contents($dir . '/2026_01_01_000000_create_students_table.php', "<?php\n");
    }

    /**
     * Writes the pipeline into the temp app.
     */
    private function writePipeline(string $yml): void
    {
        file_put_contents($this->appDir() . '/.gitlab-ci.yml', $yml);
    }

    private function context(): CheckContext
    {
        return new CheckContext(
            $this->appDir(),
            Policy::bundled(),
            new DateTimeImmutable('2026-11-08'),
        );
    }

    /**
     * The temp app dir, created on first use and remembered for cleanup.
     */
    private function appDir(): string
    {
        if ($this->appPath === '') {
            $this->appPath = sys_get_temp_dir() . '/proctor-mysql56ci-' . uniqid('', true);
            self::assertTrue(is_dir($this->appPath) || mkdir($this->appPath, 0777, true));
        }

        return $this->appPath;
    }

    /**
     * Removes a file or directory tree.
     */
    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
