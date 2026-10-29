<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * The rule-id contract: every finding across every fixture app carries a
 * stable, namespaced rule id from the known set.
 *
 * This is the guard that keeps the rule ids documented in the README in step
 * with the checks: a new rule id that is not added to KNOWN_RULE_IDS fails
 * here, and a rule id that is no longer emitted is caught by the docs review.
 */
final class RuleIdContractTest extends ProctorTestCase
{
    /**
     * The full set of rule ids PROCTOR may emit.
     *
     * @var list<string>
     */
    private const KNOWN_RULE_IDS = [
        // The check's default finding (no named rule).
        'proctor/php-floor',
        'proctor/unpatched',
        'proctor/lock-committed',
        'proctor/destructive-deploy',
        'proctor/overrides',
        'proctor/unbounded-constraints',
        'proctor/portability',
        'proctor/mysql56',
        'proctor/ci-pipelines',
        'proctor/composer-audit',
        'proctor/composer-version',
        'proctor/repo-url-credentials',
        // unbounded-constraints
        'proctor/unbounded-constraints/unbounded',
        'proctor/unbounded-constraints/repository',
        // portability
        'proctor/portability/host-path',
        'proctor/portability/proxy-address',
        'proctor/portability/local-session',
        'proctor/portability/htaccess-php-setting',
        'proctor/portability/parse-error',
        'proctor/portability/dynamic-call',
        // mysql56
        'proctor/mysql56/json',
        'proctor/mysql56/generated',
        'proctor/mysql56/rename',
        'proctor/mysql56/newer-column',
        'proctor/mysql56/introspection',
        'proctor/mysql56/raw-sql',
        'proctor/mysql56/string-length',
        'proctor/mysql56/current-date',
        'proctor/mysql56/command',
        'proctor/mysql56/upsert-alias',
        'proctor/mysql56/string-default',
        'proctor/mysql56/parse-error',
        // ci-pipelines
        'proctor/ci-pipelines/gitlab-missing',
        'proctor/ci-pipelines/gitlab-parse',
        'proctor/ci-pipelines/gitlab-no-ci',
        'proctor/ci-pipelines/github-missing',
        'proctor/ci-pipelines/github-parse',
        'proctor/ci-pipelines/github-no-ci',
        'proctor/ci-pipelines/unpinned-uses',
        // composer-audit
        'proctor/composer-audit/advisory',
        'proctor/composer-audit/abandoned',
        'proctor/composer-audit/skipped',
        'proctor/composer-audit/could-not-run',
        // composer-version
        'proctor/composer-version/not-found',
        'proctor/composer-version/could-not-determine',
    ];

    public function testEveryFindingCarriesAKnownRuleId(): void
    {
        $apps = $this->fixtureApps();
        self::assertNotEmpty($apps, 'expected at least one fixture app');

        $observed = [];
        foreach ($apps as $app) {
            $result = $this->runProctor($app, 'json');

            // A tool error (exit 2) produces no findings; skip it.
            if ($result['exit'] === 2) {
                continue;
            }

            $data = json_decode($result['stdout'], true);
            self::assertIsArray($data, sprintf('json output for %s must be valid JSON', basename($app)));

            foreach ($data['findings'] as $finding) {
                $ruleId = $finding['rule_id'];
                self::assertMatchesRegularExpression(
                    '/^proctor\/[a-z0-9-]+(\/[a-z0-9-]+)?$/',
                    $ruleId,
                    sprintf('rule id "%s" in %s must be namespaced', $ruleId, basename($app)),
                );
                self::assertContains(
                    $ruleId,
                    self::KNOWN_RULE_IDS,
                    sprintf('rule id "%s" in %s is not in the known set', $ruleId, basename($app)),
                );
                $observed[$ruleId] = true;
            }
        }

        self::assertNotEmpty($observed, 'expected at least one rule id across the fixture apps');
    }

    /**
     * The absolute paths of every fixture app, sorted for determinism.
     *
     * @return list<string>
     */
    private function fixtureApps(): array
    {
        $dir = __DIR__ . '/fixtures/apps';
        $apps = [];
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($dir . '/' . $entry)) {
                $apps[] = $dir . '/' . $entry;
            }
        }
        sort($apps);

        return $apps;
    }
}
