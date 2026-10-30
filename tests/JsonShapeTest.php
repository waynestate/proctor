<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Policy\ResolvedPolicy;
use Waynestate\Proctor\Report\JsonWriter;
use Waynestate\Proctor\Report\Report;

/**
 * Runs bin/proctor check --format=json on pass-app and asserts the shape of
 * the JSON report.
 */
final class JsonShapeTest extends ProctorTestCase
{
    public function testJsonOutputHasTheExpectedTopLevelKeys(): void
    {
        $result = $this->runProctor($this->app('pass-app'), 'json');

        self::assertSame(0, $result['exit']);

        $data = json_decode($result['stdout'], true);
        self::assertIsArray($data, 'json output must be valid JSON');
        self::assertSame(
            ['tool', 'version', 'path', 'today', 'result', 'exit', 'tools', 'policy', 'findings', 'overrides'],
            array_keys($data),
        );
        // The CLI tests run with a PATH that finds no composer, so the
        // composer version is null, not a string.
        self::assertSame(['composer' => null], $data['tools']);

        // The policy block says where the policy came from and how old it is.
        self::assertSame(
            ['origin', 'path', 'refreshed_on', 'age_days'],
            array_keys($data['policy']),
        );
        self::assertSame('option', $data['policy']['origin']);
        self::assertStringEndsWith('tests/fixtures/policy.json', $data['policy']['path']);
        self::assertSame('2026-10-20', $data['policy']['refreshed_on']);
        self::assertSame(7, $data['policy']['age_days']);

        // Every finding carries the stable rule id next to the check id.
        foreach ($data['findings'] as $finding) {
            self::assertSame(
                ['check_id', 'rule_id', 'status', 'message', 'path', 'line'],
                array_keys($finding),
            );
            self::assertMatchesRegularExpression(
                '/^proctor\/[a-z0-9-]+(\/[a-z0-9-]+)?$/',
                $finding['rule_id'],
            );
        }
    }

    public function testAdvisoryFindingsCarryTheirAdvisoryDataInJson(): void
    {
        // The CLI tests run with no composer on PATH, so this shape is built
        // directly instead of going through bin/proctor.
        $policy = Policy::fromFile(__DIR__ . '/fixtures/policy.json');
        $report = new Report(
            'tests/fixtures/apps/audit-app',
            new DateTimeImmutable('2026-10-27'),
            [
                new Finding(
                    'composer-audit',
                    Finding::STATUS_FAIL,
                    'laravel/framework 9.0.0: CVE-2024-52301 Laravel environment manipulation via query string (high)',
                    'composer.lock',
                    1,
                    null,
                    'advisory',
                    [[
                        'id' => 'CVE-2024-52301',
                        'cve' => 'CVE-2024-52301',
                        'title' => 'Laravel environment manipulation via query string',
                        'link' => 'https://github.com/advisories/GHSA-gv7v-rgg6-548h',
                        'severity' => 'high',
                    ]],
                ),
                new Finding(
                    'composer-version',
                    Finding::STATUS_PASS,
                    'composer 2.10.3 meets the policy minimum',
                ),
            ],
            [],
            Report::EXIT_FAIL,
            new ResolvedPolicy($policy, 'option', __DIR__ . '/fixtures/policy.json', '2026-10-20'),
        );

        $data = json_decode((new JsonWriter())->write($report), true);
        self::assertIsArray($data, 'json output must be valid JSON');

        [$advisoryFinding, $plainFinding] = $data['findings'];

        self::assertSame(
            ['check_id', 'rule_id', 'status', 'message', 'path', 'line', 'advisories'],
            array_keys($advisoryFinding),
        );
        self::assertSame([
            [
                'id' => 'CVE-2024-52301',
                'cve' => 'CVE-2024-52301',
                'title' => 'Laravel environment manipulation via query string',
                'link' => 'https://github.com/advisories/GHSA-gv7v-rgg6-548h',
                'severity' => 'high',
            ],
        ], $advisoryFinding['advisories']);

        // A finding without advisory data carries no advisories key at all.
        self::assertSame(
            ['check_id', 'rule_id', 'status', 'message', 'path', 'line'],
            array_keys($plainFinding),
        );
    }
}
