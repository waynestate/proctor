<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

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
}
