<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check (text format) on the node-eol fixture apps with the
 * node fixture policy and asserts the node-eol findings (or their absence).
 */
final class NodeEolCheckTest extends ProctorTestCase
{
    private function policy(): string
    {
        return __DIR__ . '/fixtures/policy-node.json';
    }

    /**
     * The node-eol lines of a text report, in output order.
     *
     * @return list<string>
     */
    private function nodeLines(string $stdout): array
    {
        preg_match_all('/^(?:PASS|WARN|FAIL|INFO) node-eol: .*$/m', $stdout, $matches);

        return $matches[0];
    }

    public function testPinnedVersionsAreGradedThroughTheStateTable(): void
    {
        $result = $this->runProctor($this->app('node-eol-pinned-app'), 'text', $this->policy());

        self::assertSame([
            'WARN node-eol: node 22.11.0 (.nvmrc) is in security-only support until 2027-04-30 (185 days left)',
            'FAIL node-eol: node 20 (.gitlab-ci.yml) is eol_grace_expired (security support ended 2026-04-30)',
            'PASS node-eol: node 26.13.0 (.gitlab-ci.yml) is active until 2027-10-20',
            'WARN node-eol: node 24 (Dockerfile) is in security-only support until 2028-04-30 (551 days left)',
            'WARN node-eol: engines.node ">=18" (package.json) allows node 18, which is eol (older than the oldest listed line (20))',
        ], $this->nodeLines($result['stdout']));
    }

    public function testEolWithinGraceFailsAsEol(): void
    {
        $stdout = $this->runProctor($this->app('node-eol-pinned-app'), 'text', $this->policy(), null, '2026-05-15')['stdout'];

        self::assertMatchesRegularExpression(
            '/^FAIL node-eol: node 20 \(\.gitlab-ci\.yml\) is eol \(security support ended 2026-04-30\)$/m',
            $stdout,
        );
    }

    public function testOddMajorWarnsAndAliasIsNotGraded(): void
    {
        $stdout = $this->runProctor($this->app('node-eol-odd-app'), 'text', $this->policy())['stdout'];

        self::assertSame([
            'WARN node-eol: node 23.3.0 (.node-version) is an odd major: odd majors are never LTS and are not a target; use an even LTS line',
            'PASS node-eol: engines.node "^26.0.0" (package.json) allows node 26, which is active until 2027-10-20',
        ], $this->nodeLines($stdout));
    }

    public function testRuleIdsInJson(): void
    {
        $data = json_decode($this->runProctor($this->app('node-eol-pinned-app'), 'json', $this->policy())['stdout'], true);
        self::assertIsArray($data);

        $ids = [];
        foreach ($data['findings'] as $finding) {
            if ($finding['check_id'] === 'node-eol') {
                $ids[] = $finding['rule_id'] . ' ' . $finding['path'] . ':' . $finding['line'];
            }
        }
        self::assertSame([
            'proctor/node-eol .nvmrc:1',
            'proctor/node-eol .gitlab-ci.yml:4',
            'proctor/node-eol .gitlab-ci.yml:10',
            'proctor/node-eol Dockerfile:1',
            'proctor/node-eol/engines package.json:3',
        ], $ids);
    }

    public function testSilentWhenThePolicyHasNoNode(): void
    {
        $stdout = $this->runProctor($this->app('node-eol-pinned-app'))['stdout'];

        self::assertSame([], $this->nodeLines($stdout));
    }

    public function testSilentWhenTheRepoNamesNoNode(): void
    {
        $stdout = $this->runProctor($this->app('pass-app'), 'text', $this->policy())['stdout'];

        self::assertSame([], $this->nodeLines($stdout));
    }
}
