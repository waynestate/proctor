<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use Waynestate\Proctor\Report\Fingerprint;
use Waynestate\Proctor\Report\Report;

/**
 * Runs bin/proctor check --format=sarif on open-bound-app and asserts the
 * shape of the SARIF 2.1.0 log.
 *
 * open-bound-app is used because it produces both fail and warning findings
 * (unbounded constraints and unstable repositories) in a single run, and the
 * composer-audit check always yields a finding with no file of its own.
 */
final class SarifShapeTest extends ProctorTestCase
{
    public function testSarifOutputIsAValidLogWithOneRun(): void
    {
        $result = $this->runProctor($this->app('open-bound-app'), 'sarif');

        self::assertSame(1, $result['exit']);
        self::assertStringEndsWith("\n", $result['stdout'], 'the CLI adds the final newline');

        $log = json_decode($result['stdout'], true);
        self::assertIsArray($log, 'sarif output must be valid JSON');
        self::assertSame(
            ['$schema', 'version', 'runs'],
            array_keys($log),
        );
        self::assertSame('https://json.schemastore.org/sarif-2.1.0.json', $log['$schema']);
        self::assertSame('2.1.0', $log['version']);
        self::assertIsList($log['runs']);
        self::assertCount(1, $log['runs']);

        $run = $log['runs'][0];
        self::assertSame(['tool', 'results'], array_keys($run));

        $driver = $run['tool']['driver'];
        self::assertSame(Report::TOOL, $driver['name']);
        self::assertSame(Report::version(), $driver['version']);
        self::assertSame('https://git.wayne.edu/enterprise-apps/proctor', $driver['informationUri']);
    }

    public function testOneResultPerNonPassFindingAndPassIsAbsent(): void
    {
        $results = $this->results('open-bound-app');

        // The non-pass findings of the same run, counted from the text report.
        $text = $this->runProctor($this->app('open-bound-app'), 'text');
        preg_match_all('/^(?:FAIL|WARN|INFO) /m', $text['stdout'], $matches);
        self::assertCount(
            count($matches[0]),
            $results,
            'one result per non-pass finding, and no pass finding',
        );

        // A pass finding of the same run must not appear as a result.
        self::assertNull(
            $this->findByMessage($results, 'php floor 8.4 is active'),
            'a pass finding must not be reported',
        );
    }

    public function testFailAndWarnMapToErrorAndWarning(): void
    {
        $results = $this->results('open-bound-app');

        foreach ($results as $result) {
            self::assertContains($result['level'], ['error', 'warning', 'note']);
        }

        $fail = $this->findByMessage($results, 'is unbounded');
        self::assertNotNull($fail, 'expected an unbounded-constraints fail finding');
        self::assertSame('error', $fail['level']);

        $warn = $this->findByMessage($results, 'is not a stable source');
        self::assertNotNull($warn, 'expected a repository warning finding');
        self::assertSame('warning', $warn['level']);
    }

    public function testRulesAreDeduplicatedPerRuleId(): void
    {
        $log = $this->log('open-bound-app');
        $rules = $log['runs'][0]['tool']['driver']['rules'];

        foreach ($rules as $rule) {
            self::assertSame(['id', 'shortDescription'], array_keys($rule));
            self::assertIsString($rule['id']);
            self::assertIsString($rule['shortDescription']['text']);
        }

        $ids = array_column($rules, 'id');
        self::assertCount(count($ids), array_unique($ids), 'rule ids must be unique');

        // One rule per distinct rule id across every finding of the run.
        $json = $this->json('open-bound-app');
        $ruleIds = array_column($json['findings'], 'rule_id');
        self::assertEqualsCanonicalizing(array_unique($ruleIds), $ids);
    }

    public function testFindingWithoutAFilePointsAtComposerJson(): void
    {
        $results = $this->results('open-bound-app');

        $audit = $this->findByRuleId($results, 'proctor/composer-audit/skipped');
        self::assertNotNull($audit, 'expected a composer-audit result');

        $location = $audit['locations'][0]['physicalLocation'];
        self::assertSame('composer.json', $location['artifactLocation']['uri']);
        self::assertArrayNotHasKey('region', $location, 'a finding without a file has no region');
    }

    public function testFingerprintsMatchFingerprint(): void
    {
        $results = $this->results('open-bound-app');

        foreach ($results as $result) {
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{64}$/',
                $result['partialFingerprints']['proctorFingerprint/v1'],
            );
        }

        // A finding with a file: the fingerprint uses the file path.
        $fail = $this->findByMessage($results, 'is unbounded');
        self::assertNotNull($fail);
        self::assertSame(
            Fingerprint::of('proctor/unbounded-constraints/unbounded', 'composer.json', $fail['message']['text']),
            $fail['partialFingerprints']['proctorFingerprint/v1'],
        );

        // A finding without a file: the fingerprint uses a null path.
        $audit = $this->findByRuleId($results, 'proctor/composer-audit/skipped');
        self::assertNotNull($audit);
        self::assertSame(
            Fingerprint::of('proctor/composer-audit/skipped', null, $audit['message']['text']),
            $audit['partialFingerprints']['proctorFingerprint/v1'],
        );
    }

    public function testInvalidFormatIsRejectedAndNamesSarif(): void
    {
        $result = $this->runProctor($this->app('open-bound-app'), 'bogus');

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString(
            'Allowed values: text, json, codequality, sarif.',
            $result['stderr'],
        );
    }

    /**
     * The decoded SARIF log of a run on the given fixture app.
     *
     * @return array<string, mixed>
     */
    private function log(string $app): array
    {
        $result = $this->runProctor($this->app($app), 'sarif');
        $log = json_decode($result['stdout'], true);
        self::assertIsArray($log, 'sarif output must be valid JSON');

        return $log;
    }

    /**
     * The decoded JSON report of a run on the given fixture app.
     *
     * @return array<string, mixed>
     */
    private function json(string $app): array
    {
        $result = $this->runProctor($this->app($app), 'json');
        $json = json_decode($result['stdout'], true);
        self::assertIsArray($json, 'json output must be valid JSON');

        return $json;
    }

    /**
     * The results of a SARIF run on the given fixture app.
     *
     * @return list<array<string, mixed>>
     */
    private function results(string $app): array
    {
        return $this->log($app)['runs'][0]['results'];
    }

    /**
     * The first result whose message contains the given text, or null.
     *
     * @param list<array<string, mixed>> $results
     */
    private function findByMessage(array $results, string $needle): ?array
    {
        foreach ($results as $result) {
            if (str_contains($result['message']['text'], $needle)) {
                return $result;
            }
        }

        return null;
    }

    /**
     * The first result with the given rule id, or null.
     *
     * @param list<array<string, mixed>> $results
     */
    private function findByRuleId(array $results, string $ruleId): ?array
    {
        foreach ($results as $result) {
            if ($result['ruleId'] === $ruleId) {
                return $result;
            }
        }

        return null;
    }
}
