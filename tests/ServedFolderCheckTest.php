<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check (text format) on the served-folder fixture apps and
 * asserts the served-folder findings (or their absence).
 */
final class ServedFolderCheckTest extends ProctorTestCase
{
    /**
     * The served-folder lines of a text report, in output order.
     *
     * @return list<string>
     */
    private function servedLines(string $stdout): array
    {
        preg_match_all('/^(?:PASS|WARN|FAIL|INFO) served-folder: .*$/m', $stdout, $matches);

        return $matches[0];
    }

    public function testDebugDumpBackupAndPhpinfoFilesWarn(): void
    {
        $stdout = $this->runProctor($this->app('served-folder-app'))['stdout'];
        $tail = ' in the served folder, so anyone can download it. Move it out of public/ (or delete it) and keep it out of the repository';

        // public/index.php mentions phpinfo() only in a comment.
        self::assertSame([
            'WARN served-folder: public/adminer-4.8.1.php is a database admin tool' . $tail,
            'WARN served-folder: public/backup.sql is a database dump' . $tail,
            'WARN served-folder: public/index.php.bak is a backup or editor copy' . $tail,
            'WARN served-folder: public/info.php calls phpinfo() in the served folder, which shows strangers the server configuration and environment. Delete the file',
        ], $this->servedLines($stdout));
    }

    public function testRuleIdsAndLines(): void
    {
        $data = json_decode($this->runProctor($this->app('served-folder-app'), 'json')['stdout'], true);
        self::assertIsArray($data);

        $seen = [];
        foreach ($data['findings'] as $finding) {
            if ($finding['check_id'] === 'served-folder') {
                $seen[] = sprintf('%s %s:%d', $finding['rule_id'], $finding['path'], $finding['line']);
            }
        }
        self::assertSame([
            'proctor/served-folder/debug-file public/adminer-4.8.1.php:1',
            'proctor/served-folder/debug-file public/backup.sql:1',
            'proctor/served-folder/debug-file public/index.php.bak:1',
            'proctor/served-folder/phpinfo public/info.php:3',
        ], $seen);
    }

    public function testCleanPublicFolderPasses(): void
    {
        $stdout = $this->runProctor($this->app('served-folder-clean-app'))['stdout'];

        self::assertSame(
            ['PASS served-folder: no debug, dump, backup or env files in public/'],
            $this->servedLines($stdout),
        );
    }

    public function testAppWithoutPublicFolderProducesNoFinding(): void
    {
        $stdout = $this->runProctor($this->app('pass-app'))['stdout'];

        self::assertSame([], $this->servedLines($stdout));
    }
}
