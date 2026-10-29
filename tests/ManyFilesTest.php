<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A large app must not exhaust memory: the banned-pattern scan parses every
 * PHP file, and it once kept every tree alive until the run ended (exit 255,
 * "Allowed memory size exhausted"). Scans 2000 generated files in a child
 * process capped at 128M.
 */
final class ManyFilesTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $this->removeTree($this->dir);
        }
    }

    public function testScanningTwoThousandPhpFilesStaysInsideA128MLimit(): void
    {
        $this->dir = sys_get_temp_dir() . '/proctor-many-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/app/Services', 0777, true);
        for ($i = 0; $i < 2000; $i++) {
            file_put_contents(
                $this->dir . '/app/Services/Service' . $i . '.php',
                "<?php\n\nnamespace App\Services;\n\nfinal class Service{$i}\n{\n    public function run(int \$n): int\n    {\n        return \$n + {$i};\n    }\n}\n",
            );
        }

        $cmd = [
            PHP_BINARY,
            '-d', 'memory_limit=128M',
            dirname(__DIR__) . '/bin/proctor',
            'check',
            '--path=' . $this->dir,
            '--format=json',
            '--policy=' . __DIR__ . '/fixtures/policy.json',
            '--today=2026-10-27',
        ];
        $env = getenv();
        foreach (array_keys($env) as $key) {
            if (strcasecmp((string) $key, 'PATH') === 0 || strcasecmp((string) $key, 'PROCTOR_POLICY') === 0) {
                unset($env[$key]);
            }
        }
        $env['PATH'] = __DIR__ . '/fixtures/no-tools';

        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process, 'could not start bin/proctor');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertStringNotContainsString('Allowed memory size', $stderr . $stdout);
        self::assertNotSame(255, $exit, $stderr);
        self::assertIsArray(json_decode($stdout, true), 'the report must be valid JSON: ' . $stderr);
    }

    private function removeTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeTree($full) : unlink($full);
        }
        rmdir($path);
    }
}
