<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The front page's supported-versions table must never drift from the policy
 * PROCTOR ships: bin/supported-versions.php writes it, and its --check mode is
 * what composer ci runs against a copy of the real files in a temp dir.
 */
final class SupportedVersionsTableTest extends TestCase
{
    private const MARKER_START = '<!-- supported-versions:start -->';
    private const MARKER_END = '<!-- supported-versions:end -->';

    /**
     * Runs the generator against a copy of the repository in a temp dir.
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: string} exit code and stdout
     */
    private function runScript(string $root, array $args = []): array
    {
        $cmd = [PHP_BINARY, 'bin/supported-versions.php'];
        foreach ($args as $arg) {
            $cmd[] = $arg;
        }
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, trim((string) $stdout . (string) $stderr)];
    }

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/proctor-supported-versions-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/bin', 0777, true);
        mkdir($this->tempDir . '/src/Creed', 0777, true);
        mkdir($this->tempDir . '/site', 0777, true);
        copy(__DIR__ . '/../bin/supported-versions.php', $this->tempDir . '/bin/supported-versions.php');
        copy(__DIR__ . '/../src/Creed/policy.json', $this->tempDir . '/src/Creed/policy.json');
        copy(__DIR__ . '/../site/index.html', $this->tempDir . '/site/index.html');
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->tempDir);
    }

    public function testTheCommittedRegionMatchesTheBundledPolicy(): void
    {
        [$exit, $output] = $this->runScript($this->tempDir, ['--check']);

        self::assertSame(0, $exit, $output);
        self::assertSame('supported-versions: current', $output);
    }

    public function testAnAlteredRowIsStale(): void
    {
        $html = file_get_contents($this->tempDir . '/site/index.html');
        $row = '            <tr><th scope="row">PHP</th><td>8.1</td><td>2023-11-25</td><td>2025-12-31</td><td><span class="mark mark--fail">End of life</span></td></tr>';
        self::assertNotFalse(strpos($html, $row), 'the committed table must carry the PHP 8.1 row the check would flag');
        file_put_contents($this->tempDir . '/site/index.html', str_replace($row, str_replace('8.1', '9.9', $row), $html));

        [$exit, $output] = $this->runScript($this->tempDir, ['--check']);

        self::assertSame(1, $exit);
        self::assertSame('supported-versions: site/index.html is stale; run composer site:versions', $output);
    }

    public function testMissingMarkersAreAToolError(): void
    {
        $html = file_get_contents($this->tempDir . '/site/index.html');
        file_put_contents($this->tempDir . '/site/index.html', str_replace([self::MARKER_START, self::MARKER_END], '', $html));

        [$exit, $output] = $this->runScript($this->tempDir, ['--check']);

        self::assertSame(2, $exit);
        self::assertStringStartsWith('supported-versions: ', $output);
    }

    public function testStatusesFollowThePolicyDates(): void
    {
        $policy = [
            'refreshed_on' => '2026-06-01',
            'components' => [
                'php' => [
                    'versions' => [
                        '8.1' => ['active_until' => '2023-11-25', 'security_until' => '2025-12-31'],
                        '8.3' => ['active_until' => '2025-12-31', 'security_until' => '2027-12-31'],
                        '8.5' => ['active_until' => '2027-12-31', 'security_until' => '2029-12-31'],
                    ],
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/src/Creed/policy.json', json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($this->tempDir . '/site/index.html', '<!doctype html><html><body>' . "\n" . self::MARKER_START . "\n" . self::MARKER_END . "\n</body></html>\n");

        [$exit, $output] = $this->runScript($this->tempDir);
        self::assertSame(0, $exit, $output);
        self::assertSame('supported-versions: site/index.html updated', $output);

        $html = file_get_contents($this->tempDir . '/site/index.html');
        self::assertSame(1, substr_count($html, '>End of life</span>'));
        self::assertSame(1, substr_count($html, '>Security fixes only</span>'));
        self::assertSame(1, substr_count($html, '>Supported</span>'));
        self::assertStringContainsString('<p>From the policy bundled with this release (refreshed 2026-06-01).', $html);

        [$exit, $output] = $this->runScript($this->tempDir, ['--check']);
        self::assertSame(0, $exit, $output);
        self::assertSame('supported-versions: current', $output);
    }
}
