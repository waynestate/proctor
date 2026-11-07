<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check (text format) on the world-writable fixture apps
 * and asserts the world-writable findings.
 */
final class WorldWritableCheckTest extends ProctorTestCase
{
    private const TAIL = ', which makes it world-writable: any process on the host can change it. Give the web server user write access to the folders it needs (storage/ and bootstrap/cache/ in Laravel) and nothing more';

    /**
     * The world-writable lines of a text report, in output order.
     *
     * @return list<string>
     */
    private function writableLines(string $stdout): array
    {
        preg_match_all('/^(?:PASS|WARN|FAIL|INFO) world-writable: .*$/m', $stdout, $matches);

        return $matches[0];
    }

    public function testMode777WarnsInScriptsDockerfilesComposerScriptsAndPhp(): void
    {
        $stdout = $this->runProctor($this->app('world-writable-app'))['stdout'];

        // Not flagged: the comment line, the sticky 1777, chmod 755, and
        // mkdir() with 0777 (the umask still applies to it).
        self::assertSame([
            'WARN world-writable: Dockerfile:2 runs chmod a+rwx /var/www/html/storage' . self::TAIL,
            'WARN world-writable: app/Support/Files.php:9 runs chmod(..., 0777)' . self::TAIL,
            'WARN world-writable: composer.json:4 runs chmod ugo+rwx bootstrap/cache' . self::TAIL,
            'WARN world-writable: deploy/release.sh:3 runs chmod 777 storage' . self::TAIL,
            'WARN world-writable: deploy/release.sh:5 runs chmod 0777 bootstrap/cache' . self::TAIL,
        ], $this->writableLines($stdout));
    }

    public function testRuleId(): void
    {
        $data = json_decode($this->runProctor($this->app('world-writable-app'), 'json')['stdout'], true);
        self::assertIsArray($data);

        $ids = [];
        foreach ($data['findings'] as $finding) {
            if ($finding['check_id'] === 'world-writable') {
                $ids[$finding['rule_id']] = true;
            }
        }
        self::assertSame(['proctor/world-writable'], array_keys($ids));
    }

    public function testRepoWithoutMode777Passes(): void
    {
        $stdout = $this->runProctor($this->app('pass-app'))['stdout'];

        self::assertSame(['PASS world-writable: no chmod 777 found'], $this->writableLines($stdout));
    }
}
