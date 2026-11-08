<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use Waynestate\Proctor\Check\CheckSet;

/**
 * Runs bin/proctor gate on fixture apps: the verdict, the exit code, the
 * ratchet against a baseline (a recorded report or a directory), the
 * overrides, the record file and the tool errors. Composer is never on PATH
 * here, so composer-audit's "not audited" fail is waived in every run.
 */
final class GateCommandTest extends ProctorTestCase
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
        foreach ($this->tempDirs as $dir) {
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
    private function gate(string $app, array $extra = [], ?string $policy = null, ?string $today = null): array
    {
        return $this->runGate([
            '--path=' . $this->app($app),
            '--policy=' . ($policy ?? __DIR__ . '/fixtures/policy.json'),
            '--today=' . ($today ?? self::TODAY),
            ...$extra,
        ]);
    }

    private function tempFile(string $contents = ''): string
    {
        $file = tempnam(sys_get_temp_dir(), 'proctor-gate-');
        self::assertIsString($file);
        file_put_contents($file, $contents);
        $this->tempFiles[] = $file;

        return $file;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $stdout): array
    {
        $data = json_decode($stdout, true);
        self::assertIsArray($data, $stdout);

        return $data;
    }

    public function testACleanAppIsAGo(): void
    {
        $result = $this->gate('pass-app');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertMatchesRegularExpression('/^proctor \S+ gate: GO$/m', $result['stdout']);
        self::assertMatchesRegularExpression('/^RESULT: go \(0 blocked, 0 held, 1 waived, \d+ warnings, \d+ passed\)$/m', $result['stdout']);
    }

    public function testMissingAdvisoryDataIsWaivedNotBlocked(): void
    {
        $stdout = $this->gate('pass-app')['stdout'];

        self::assertMatchesRegularExpression(
            '/^WAIVE composer-audit: not audited: composer executable not found on PATH; install Composer or record an override \[waived: no advisory data at deploy time, so it only warns here\]$/m',
            $stdout,
        );
    }

    public function testAFirstDeployWithAFailureIsANoGo(): void
    {
        $result = $this->gate('eol-app');

        self::assertSame(1, $result['exit']);
        self::assertMatchesRegularExpression('/^proctor \S+ gate: NO-GO$/m', $result['stdout']);
        self::assertStringContainsString('Baseline: none, so this is a first deploy and every failure blocks', $result['stdout']);
        self::assertMatchesRegularExpression('/^BLOCK php-floor: .* \[new app: no baseline, so every failure blocks\]$/m', $result['stdout']);
    }

    public function testOnlyTheDeployTimeChecksRun(): void
    {
        // unbounded-app fails unbounded-constraints under `proctor check`; the gate does not run that check.
        $result = $this->gate('unbounded-app', ['--format=json']);
        $data = $this->json($result['stdout']);

        self::assertSame(0, $result['exit'], $result['stdout']);
        self::assertSame(CheckSet::GATE, $data['checks']);
        foreach ($data['findings'] as $finding) {
            self::assertContains($finding['check_id'], CheckSet::GATE);
        }
    }

    public function testAFailureTheBaselineReportAlsoHadIsHeld(): void
    {
        $baseline = $this->tempFile((string) json_encode([
            'tool' => 'proctor',
            'findings' => [['check_id' => 'php-floor', 'rule_id' => 'proctor/php-floor', 'status' => 'fail']],
        ]));

        $result = $this->gate('eol-app', ['--baseline=' . $baseline]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertMatchesRegularExpression('/^HOLD php-floor: .* \[held: it failed on the baseline too, so the ratchet lets it through\]$/m', $result['stdout']);
        self::assertMatchesRegularExpression('/^Baseline: report .+, 1 failing rule$/m', $result['stdout']);
    }

    public function testAFailureTheBaselineDidNotHaveIsARegression(): void
    {
        $baseline = $this->tempFile((string) json_encode([
            'tool' => 'proctor',
            'findings' => [['check_id' => 'php-floor', 'rule_id' => 'proctor/php-floor', 'status' => 'pass']],
        ]));

        $result = $this->gate('eol-app', ['--baseline=' . $baseline]);

        self::assertSame(1, $result['exit']);
        self::assertMatchesRegularExpression('/^BLOCK php-floor: .* \[regression: this rule passed on the baseline\]$/m', $result['stdout']);
    }

    public function testABaselineDirectoryIsCheckedWithTheSameRules(): void
    {
        $held = $this->gate('eol-app', ['--baseline=' . $this->app('eol-app')]);
        self::assertSame(0, $held['exit'], $held['stdout'] . $held['stderr']);
        self::assertMatchesRegularExpression('/^Baseline: directory .+eol-app, 2 failing rules$/m', $held['stdout']);

        $regression = $this->gate('eol-app', ['--baseline=' . $this->app('pass-app')]);
        self::assertSame(1, $regression['exit']);
        self::assertStringContainsString('[regression: this rule passed on the baseline]', $regression['stdout']);
    }

    public function testTheRecordIsJsonAndServesAsTheNextBaseline(): void
    {
        $record = sys_get_temp_dir() . '/proctor-gate-record-' . bin2hex(random_bytes(4)) . '/gate.json';
        $this->tempFiles[] = $record;
        $this->tempDirs[] = dirname($record);

        $first = $this->gate('eol-app', ['--record=' . $record]);
        self::assertSame(1, $first['exit']);
        self::assertStringContainsString('gate: NO-GO', $first['stdout'], 'text on stdout, JSON in the record');
        $data = $this->json((string) file_get_contents($record));
        self::assertSame('no-go', $data['verdict']);
        self::assertSame(1, $data['exit']);

        $second = $this->gate('eol-app', ['--baseline=' . $record]);
        self::assertSame(0, $second['exit'], $second['stdout'] . $second['stderr']);
    }

    public function testTheJsonVerdictNamesEachOutcomeAndReason(): void
    {
        $result = $this->gate('eol-app', ['--format=json']);
        $data = $this->json($result['stdout']);

        self::assertSame(1, $result['exit']);
        self::assertSame('gate', $data['command']);
        self::assertSame('no-go', $data['verdict']);
        self::assertNull($data['baseline']);
        self::assertSame(1, $data['summary']['blocked']);
        self::assertSame(1, $data['summary']['waived']);
        $floor = array_values(array_filter($data['findings'], static fn(array $f): bool => 'php-floor' === $f['check_id']))[0];
        self::assertSame('fail', $floor['status'], 'the check status is kept, so the file works as a baseline');
        self::assertSame('blocked', $floor['gate']);
        self::assertSame('new-app', $floor['reason']);
    }

    public function testAnExpiredOverrideBlocksEvenWhenTheBaselineHadIt(): void
    {
        $baseline = $this->tempFile((string) json_encode([
            'tool' => 'proctor',
            'findings' => [['rule_id' => 'proctor/overrides', 'status' => 'fail']],
        ]));

        $result = $this->gate('expired-override-app', ['--baseline=' . $baseline]);

        self::assertSame(1, $result['exit']);
        self::assertMatchesRegularExpression('/^BLOCK overrides: override for "lock-committed" expired on 2026-01-01 \[expired override: renew it or fix the failure it covered\]$/m', $result['stdout']);
    }

    public function testAnExpiredOverrideForACheckTheGateSkipsStillBlocks(): void
    {
        $result = $this->gate('gate-expired-other-override-app');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('BLOCK overrides: override for "portability" expired on 2026-01-01', $result['stdout']);
    }

    public function testAnActiveOverrideForACheckTheGateSkipsIsAcceptedAndNotCalledUnused(): void
    {
        $result = $this->gate('gate-other-override-app');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('matched no failure', $result['stdout']);
        self::assertStringContainsString('OVERRIDE portability: fixture: a check the gate does not run', $result['stdout']);
    }

    public function testAnActiveOverrideTurnsAFailIntoAWarningAndTheGateGoes(): void
    {
        $result = $this->gate('laravel-eol-override-app', [], __DIR__ . '/fixtures/policy-laravel.json', '2026-04-01');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertMatchesRegularExpression('/^WARN laravel-eol: .*\(overridden: /m', $result['stdout']);
    }

    public function testAnOverrideForAnUnknownCheckIsAToolError(): void
    {
        $result = $this->gate('override-unknown-check-app');

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('proctor gate could not decide: policy-overrides.json names unknown check "no-such-check"', $result['stderr']);
    }

    public function testAMissingBaselineIsAToolErrorWithAJsonVerdict(): void
    {
        $result = $this->gate('pass-app', ['--baseline=' . $this->app('no-such-dir'), '--format=json']);
        $data = $this->json($result['stdout']);

        self::assertSame(2, $result['exit']);
        self::assertSame('error', $data['verdict']);
        self::assertSame(2, $data['exit']);
        self::assertStringContainsString('does not exist', $data['error']);
    }

    public function testAMissingBaselineIsAToolErrorWithATextReport(): void
    {
        $result = $this->gate('pass-app', ['--baseline=' . $this->app('no-such-dir')]);

        self::assertSame(2, $result['exit']);
        self::assertMatchesRegularExpression('/^proctor \S+ gate: ERROR$/m', $result['stdout']);
        self::assertMatchesRegularExpression('/^FAIL proctor: Baseline ".*" does not exist\./m', $result['stdout']);
        self::assertMatchesRegularExpression('/^RESULT: error \(the gate could not decide\)$/m', $result['stdout']);
    }

    public function testABaselineThatIsNotAProctorReportIsAToolError(): void
    {
        $notJson = $this->gate('pass-app', ['--baseline=' . $this->tempFile('not json')]);
        self::assertSame(2, $notJson['exit']);
        self::assertStringContainsString('is not valid JSON', $notJson['stderr']);

        $otherTool = $this->gate('pass-app', ['--baseline=' . $this->tempFile('{"tool":"other","findings":[]}')]);
        self::assertSame(2, $otherTool['exit']);
        self::assertStringContainsString('is not a PROCTOR JSON report with a findings list', $otherTool['stderr']);
    }

    public function testAToolErrorIsRecordedToo(): void
    {
        $record = $this->tempFile();

        $result = $this->gate('override-unknown-check-app', ['--record=' . $record]);

        self::assertSame(2, $result['exit']);
        $data = $this->json((string) file_get_contents($record));
        self::assertSame('error', $data['verdict']);
    }

    public function testAnUnknownFormatIsAToolError(): void
    {
        $result = $this->gate('pass-app', ['--format=sarif']);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('Invalid format "sarif". Allowed values: text, json.', $result['stderr']);
    }

    public function testTodayIsRefusedInCiLikeCheck(): void
    {
        $result = $this->runGate(
            ['--path=' . $this->app('pass-app'), '--today=' . self::TODAY],
            ['CI' => 'true'],
        );

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('Refused: --today is not accepted in CI', $result['stderr']);
    }
}
