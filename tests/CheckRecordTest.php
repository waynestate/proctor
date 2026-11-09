<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check with --record: the file holds exactly what
 * --format=json prints, whatever format stdout gets, so one CI run feeds
 * both the merge request (codequality) and Rollcall (the JSON artifact).
 * The exit code is the check's own, except that a record PROCTOR cannot
 * write is a tool error (exit 2), never a pass.
 */
final class CheckRecordTest extends ProctorTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
        foreach (array_reverse($this->tempDirs) as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        $this->tempDirs = [];
    }

    /**
     * @param list<string> $extra
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function check(string $app, array $extra = []): array
    {
        return $this->runCheck([
            '--path=' . $this->app($app),
            '--policy=' . __DIR__ . '/fixtures/policy.json',
            '--today=' . self::TODAY,
            ...$extra,
        ]);
    }

    /**
     * A record path in a fresh folder that does not exist yet, cleaned up
     * after the test.
     */
    private function recordPath(): string
    {
        $dir = sys_get_temp_dir() . '/proctor-check-record-' . bin2hex(random_bytes(4));
        $record = $dir . '/reports/proctor.json';
        $this->tempFiles[] = $record;
        $this->tempDirs[] = $dir;
        $this->tempDirs[] = $dir . '/reports';

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $text): array
    {
        $data = json_decode($text, true);
        self::assertIsArray($data, 'not JSON: ' . $text);

        return $data;
    }

    public function testTextOnStdoutAndTheJsonReportInTheRecordWithItsFolderCreated(): void
    {
        $record = $this->recordPath();

        $result = $this->check('eol-app', ['--record=' . $record]);

        self::assertSame(1, $result['exit'], 'the exit code is the check\'s own');
        self::assertSame(['php-floor', 'composer-audit'], $this->failCheckIds($result['stdout']), 'text on stdout');
        self::assertFileExists($record);
        $data = $this->json((string) file_get_contents($record));
        self::assertSame('proctor', $data['tool']);
        self::assertSame('fail', $data['result']);
        self::assertSame(1, $data['exit']);
    }

    public function testCodeQualityOnStdoutAndTheJsonReportInTheRecord(): void
    {
        $record = $this->recordPath();

        $result = $this->check('eol-app', ['--format=codequality', '--record=' . $record]);

        self::assertSame(1, $result['exit']);
        $issues = $this->json($result['stdout']);
        self::assertTrue(array_is_list($issues), 'codequality on stdout is a list of issues');
        self::assertContains('proctor/php-floor', array_column($issues, 'check_name'));
        $data = $this->json((string) file_get_contents($record));
        self::assertSame('proctor', $data['tool']);
        self::assertContains('php-floor', array_column($data['findings'], 'check_id'));
    }

    public function testTheRecordIsByteForByteWhatFormatJsonPrints(): void
    {
        $plain = $this->check('eol-app', ['--format=json']);
        $fromText = $this->recordPath();
        $fromCodeQuality = $this->recordPath();
        $fromJson = $this->recordPath();

        $this->check('eol-app', ['--record=' . $fromText]);
        $this->check('eol-app', ['--format=codequality', '--record=' . $fromCodeQuality]);
        $json = $this->check('eol-app', ['--format=json', '--record=' . $fromJson]);

        self::assertSame($plain['stdout'], (string) file_get_contents($fromText));
        self::assertSame($plain['stdout'], (string) file_get_contents($fromCodeQuality));
        self::assertSame($plain['stdout'], (string) file_get_contents($fromJson));
        self::assertSame($plain['stdout'], $json['stdout'], '--record leaves stdout alone');
    }

    public function testARecordThatCannotBeWrittenIsAToolErrorNotAPass(): void
    {
        // A folder cannot be created under a regular file, not even by root
        // (the CI container), so this path is unwritable everywhere.
        $file = tempnam(sys_get_temp_dir(), 'proctor-check-record-');
        self::assertIsString($file);
        $this->tempFiles[] = $file;

        $result = $this->check('pass-app', ['--record=' . $file . '/reports/proctor.json']);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('Could not create the folder for --record', $result['stderr']);
        self::assertMatchesRegularExpression('/^FAIL proctor: Could not create the folder for --record /m', $result['stdout']);
        self::assertStringNotContainsString('PASS php-floor', $result['stdout'], 'no report next to exit 2');
    }

    public function testAToolErrorIsRecordedToo(): void
    {
        $record = $this->recordPath();

        $result = $this->check('override-unknown-check-app', ['--format=codequality', '--record=' . $record]);

        self::assertSame(2, $result['exit']);
        $data = $this->json((string) file_get_contents($record));
        self::assertSame('error', $data['result']);
        self::assertSame(2, $data['exit']);
        self::assertStringContainsString('no-such-check', $data['error']);
    }
}
