<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * The rule-id contract: every finding across every fixture app carries a
 * stable, namespaced rule id from the known set.
 *
 * This is the guard that keeps the rule ids documented in the README in step
 * with the checks: a new rule id that is not added to KNOWN_RULE_IDS fails
 * here, and the README's rule-id table is parsed, so a known rule id missing
 * from it, or a row that names an unknown rule id, fails here too. A rule id
 * that is no longer emitted is caught by the docs review.
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
        'proctor/laravel-eol',
        'proctor/laravel-php',
        'proctor/lock-committed',
        'proctor/destructive-deploy',
        'proctor/crlf-deploy-script',
        'proctor/case-clash',
        'proctor/overrides',
        'proctor/unbounded-constraints',
        'proctor/composer-config',
        'proctor/portability',
        'proctor/mysql56',
        'proctor/ci-pipelines',
        'proctor/preflight-pinned',
        'proctor/composer-audit',
        'proctor/composer-version',
        'proctor/repo-url-credentials',
        'proctor/instant-client-committed',
        'proctor/wayneui-forked-views',
        'proctor/wayneui-outdated',
        'proctor/policy-age',
        'proctor/node-eol',
        'proctor/mysql-eol',
        'proctor/env-outside-config',
        'proctor/raw-identity-header',
        'proctor/app-key',
        'proctor/served-folder',
        'proctor/world-writable',
        // node-eol
        'proctor/node-eol/engines',
        'proctor/node-eol/odd-major',
        // env-outside-config
        'proctor/env-outside-config/suppressed',
        // app-key
        'proctor/app-key/committed-env',
        'proctor/app-key/committed-key',
        'proctor/app-key/cookie-sessions',
        // served-folder
        'proctor/served-folder/debug-file',
        'proctor/served-folder/phpinfo',
        // lock-committed
        'proctor/lock-committed/out-of-sync',
        // unbounded-constraints
        'proctor/unbounded-constraints/unbounded',
        'proctor/unbounded-constraints/repository',
        'proctor/unbounded-constraints/minimum-stability',
        'proctor/unbounded-constraints/insecure-url',
        // composer-config
        'proctor/composer-config/allow-plugins-all',
        'proctor/composer-config/secure-http-off',
        'proctor/composer-config/lock-off',
        'proctor/composer-config/audit-blocking-off',
        'proctor/composer-config/policy-off',
        'proctor/composer-config/policy-malware-off',
        'proctor/composer-config/policy-advisories-off',
        'proctor/composer-config/platform-php-unset',
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
        'proctor/ci-pipelines/github-private',
        'proctor/ci-pipelines/unpinned-uses',
        'proctor/ci-pipelines/composer-audit-off',
        'proctor/ci-pipelines/composer-update',
        // preflight-pinned
        'proctor/preflight-pinned/unpinned',
        'proctor/preflight-pinned/script-override',
        // composer-audit
        'proctor/composer-audit/advisory',
        'proctor/composer-audit/abandoned',
        'proctor/composer-audit/skipped',
        'proctor/composer-audit/could-not-run',
        'proctor/composer-audit/exit-code',
        'proctor/composer-audit/ignore-entries',
        'proctor/composer-audit/unbacked-ignore',
        // composer-version
        'proctor/composer-version/not-found',
        'proctor/composer-version/could-not-determine',
        // wayneui-outdated
        'proctor/wayneui-outdated/skipped',
        // overrides
        'proctor/overrides/expiring',
        'proctor/overrides/too-long',
        'proctor/overrides/unused',
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

    public function testReadmeRuleTableListsExactlyTheKnownRuleIds(): void
    {
        $readme = dirname(__DIR__) . '/README.md';
        self::assertFileExists($readme);
        $markdown = (string) file_get_contents($readme);

        // The table lives under the "## Rule ids" heading, up to the next heading.
        self::assertMatchesRegularExpression(
            '/^## Rule ids\n(.*?)(?=^## )/ms',
            $markdown,
            'README.md must have a "## Rule ids" section',
        );
        preg_match('/^## Rule ids\n(.*?)(?=^## )/ms', $markdown, $matches);
        $section = $matches[1] ?? '';

        $listed = [];
        foreach (preg_split('/\R/', $section) as $line) {
            if (preg_match('/^\|\s*`[a-z0-9-]+`\s*\|\s*`(proctor\/[a-z0-9-]+(?:\/[a-z0-9-]+)?)`\s*\|$/', $line, $row) !== 1) {
                continue;
            }
            $listed[] = $row[1];
        }

        self::assertNotEmpty($listed, 'no rule-id rows found in the README table');

        $seen = [];
        $duplicates = [];
        foreach ($listed as $id) {
            if (isset($seen[$id])) {
                $duplicates[] = $id;
            }
            $seen[$id] = true;
        }
        self::assertSame([], $duplicates, sprintf('duplicate rows in the README table: %s', implode(', ', $duplicates)));

        $missing = array_values(array_diff(self::KNOWN_RULE_IDS, $listed));
        self::assertSame([], $missing, sprintf('known rule ids missing from the README table: %s', implode(', ', $missing)));

        $unknown = array_values(array_diff($listed, self::KNOWN_RULE_IDS));
        self::assertSame([], $unknown, sprintf('README table rows that name no known rule id: %s', implode(', ', $unknown)));
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
