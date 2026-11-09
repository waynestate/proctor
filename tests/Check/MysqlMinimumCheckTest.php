<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\MysqlMinimumCheck;
use Waynestate\Proctor\Creed\Policy;

/**
 * Tests the MysqlMinimumCheck on a temp app dir, so the warning and pass
 * findings, their rule ids and their messages are verified against the
 * bundled policy's recorded minimums without touching the fixture apps.
 */
final class MysqlMinimumCheckTest extends TestCase
{
    private string $appPath = '';

    protected function tearDown(): void
    {
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
            $this->appPath = '';
        }
    }

    public function testMysqlBelowTheLaravelLineMinimumWarns(): void
    {
        $this->writeLaravelLock('v12.71.0');
        $this->writeFile('docker-compose.yml', "services:\n  db:\n    image: mysql:5.6\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/mysql-minimum/below', $findings[0]->ruleId);
        self::assertSame('docker-compose.yml', $findings[0]->path);
        self::assertSame(3, $findings[0]->line);
        self::assertSame(
            'mysql 5.6 is below the 5.7 minimum Laravel 12 needs (docker-compose.yml:3)',
            $findings[0]->message,
        );
    }

    public function testMysqlAtOrAboveTheLineMinimumPasses(): void
    {
        $this->writeLaravelLock('v12.71.0');
        $this->writeFile('docker-compose.yml', "services:\n  db:\n    image: mysql:8.4\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/mysql-minimum', $findings[0]->ruleId);
        self::assertSame('docker-compose.yml', $findings[0]->path);
        self::assertSame(3, $findings[0]->line);
        self::assertSame(
            'mysql 8.4 meets the 5.7 minimum Laravel 12 needs',
            $findings[0]->message,
        );
    }

    public function testMariaDbBelowTheLineMinimumInAnEnvExampleWarns(): void
    {
        $this->writeLaravelLock('v12.71.0');
        $this->writeFile('.env.squire.example', "SQUIRE_MYSQL_IMAGE=mariadb:10.2\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/mysql-minimum/below', $findings[0]->ruleId);
        self::assertSame('.env.squire.example', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame(
            'mariadb 10.2 is below the 10.3 minimum Laravel 12 needs (.env.squire.example:1)',
            $findings[0]->message,
        );
    }

    public function testNoDatabaseImageIsSilent(): void
    {
        $this->writeLaravelLock('v12.71.0');
        $this->writeFile('docker-compose.yml', "services:\n  web:\n    image: php:8.4-cli\n");

        $findings = $this->runCheck();

        self::assertSame([], $findings);
    }

    public function testNoLaravelInTheLockIsSilent(): void
    {
        $this->writeFile('composer.lock', json_encode([
            'packages' => [['name' => 'symfony/console', 'version' => 'v7.0.0']],
            'packages-dev' => [],
        ]) . "\n");
        $this->writeFile('docker-compose.yml', "services:\n  db:\n    image: mysql:5.6\n");

        $findings = $this->runCheck();

        self::assertSame([], $findings);
    }

    public function testTheCheckIsNotInTheDeployGate(): void
    {
        self::assertNotContains('mysql-minimum', CheckSet::GATE);
    }

    /**
     * Writes a composer.lock with one laravel/framework entry at the given
     * version.
     */
    private function writeLaravelLock(string $version): void
    {
        $this->writeFile('composer.lock', json_encode([
            'packages' => [['name' => 'laravel/framework', 'version' => $version]],
            'packages-dev' => [],
        ]) . "\n");
    }

    /**
     * Writes a file into the temp app dir (created on first use), making any
     * missing parent directory along the way.
     */
    private function writeFile(string $relative, string $content): void
    {
        $this->appDir();
        $absolute = $this->appPath . '/' . $relative;
        $parent = dirname($absolute);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($absolute, $content);
    }

    /**
     * The temp app dir, created on first use and remembered for cleanup.
     */
    private function appDir(): string
    {
        if ($this->appPath === '') {
            $this->appPath = sys_get_temp_dir() . '/proctor-mysqlminimum-' . uniqid('', true);
            self::assertTrue(is_dir($this->appPath) || mkdir($this->appPath, 0777, true));
        }

        return $this->appPath;
    }

    /**
     * Runs the check on the temp app against the bundled policy and returns
     * its findings.
     *
     * @return list<Finding>
     */
    private function runCheck(): array
    {
        $context = new CheckContext(
            $this->appDir(),
            Policy::bundled(),
            new DateTimeImmutable('2026-11-08'),
        );

        return (new MysqlMinimumCheck())->run($context);
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
