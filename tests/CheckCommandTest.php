<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check (text format) on each fixture app and asserts the
 * exit code and the check ids that fail.
 */
final class CheckCommandTest extends ProctorTestCase
{
    public function testPassAppFailsOnlyTheMissingComposerAudit(): void
    {
        $result = $this->runProctor($this->app('pass-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(['composer-audit'], $this->failCheckIds($result['stdout']));
    }

    public function testPassAppPassesFloorUnboundedAndLock(): void
    {
        $stdout = $this->runProctor($this->app('pass-app'))['stdout'];

        self::assertMatchesRegularExpression('/^PASS php-floor: /m', $stdout);
        self::assertMatchesRegularExpression('/^PASS unbounded-constraints: /m', $stdout);
        self::assertMatchesRegularExpression('/^PASS lock-committed: /m', $stdout);
    }

    public function testPassAppFailsComposerAuditWhenComposerIsMissing(): void
    {
        $stdout = $this->runProctor($this->app('pass-app'))['stdout'];

        self::assertMatchesRegularExpression(
            '/^FAIL composer-audit: not audited: composer executable not found on PATH; install Composer or record an override$/m',
            $stdout,
        );
    }

    public function testNoLockAppFailsComposerAuditWithoutAnOverride(): void
    {
        // The fake composer on PATH lets the check reach the lock branch.
        $result = $this->runProctor($this->app('audit-no-lock-app'), 'text', null, null, null, $this->fakeComposerPath());

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString(
            'FAIL composer-audit: not audited: no composer.lock in the app path; commit the lock or record an override',
            $result['stdout'],
        );
    }

    public function testNoLockAppOverrideDowngradesTheComposerAuditFailToAWarning(): void
    {
        // The fake composer on PATH lets the check reach the lock branch.
        $result = $this->runProctor($this->app('audit-no-lock-override-app'), 'text', null, null, null, $this->fakeComposerPath());

        self::assertStringContainsString(
            'WARN composer-audit: not audited: no composer.lock in the app path; commit the lock or record an override (overridden: no lock yet, ticket in progress)',
            $result['stdout'],
        );
        self::assertStringContainsString(' (overridden: no lock yet', $result['stdout']);
        // The override downgrades the audit fail to a warning; the run still
        // fails on the missing lock, never on composer-audit.
        self::assertNotContains('composer-audit', $this->failCheckIds($result['stdout']));
    }

    public function testEolAppFailsPhpFloorAndTheMissingComposerAudit(): void
    {
        $result = $this->runProctor($this->app('eol-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(['php-floor', 'composer-audit'], $this->failCheckIds($result['stdout']));
    }

    public function testUnboundedAppFailsUnboundedConstraintsForDevMasterAndStar(): void
    {
        $result = $this->runProctor($this->app('unbounded-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(
            ['unbounded-constraints', 'unbounded-constraints', 'composer-audit'],
            $this->failCheckIds($result['stdout']),
        );
        self::assertStringContainsString('vendor/dev constraint "dev-master" is unbounded', $result['stdout']);
        self::assertStringContainsString('vendor/any constraint "*" is unbounded', $result['stdout']);
    }

    public function testOpenBoundAppFailsOpenUpperBoundAndDevFlagAndWarnsForRepositories(): void
    {
        $result = $this->runProctor($this->app('open-bound-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(
            ['unbounded-constraints', 'unbounded-constraints', 'composer-audit'],
            $this->failCheckIds($result['stdout']),
        );
        self::assertStringContainsString('vendor/open constraint ">=1.0" is unbounded', $result['stdout']);
        self::assertStringContainsString('vendor/devflag constraint "1.0@dev" is unbounded', $result['stdout']);
        self::assertMatchesRegularExpression(
            '/^WARN unbounded-constraints: repository of type "path" is not a stable source$/m',
            $result['stdout'],
        );
        self::assertMatchesRegularExpression(
            '/^WARN unbounded-constraints: repository of type "vcs" is not a stable source$/m',
            $result['stdout'],
        );
    }

    public function testExpiredOverrideAppFailsOverridesAndPrintsTheOverrideLine(): void
    {
        $result = $this->runProctor($this->app('expired-override-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(['composer-audit', 'overrides'], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^OVERRIDE lock-committed: fixture: expired \(expires 2026-01-01, approved by tester\)$/m',
            $result['stdout'],
        );
    }

    public function testLoopholeAppIsRejectedBecauseAnOverrideNamesTheOverridesCheck(): void
    {
        $result = $this->runProctor($this->app('override-loophole-app'));

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString(
            'an override cannot target the overrides check',
            $result['stderr'],
        );
    }

    public function testCiPipelinesGoodAppPassesCiPipelinesAndFailsOnlyTheMissingComposerAudit(): void
    {
        $result = $this->runProctor($this->app('ci-pipelines-good-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(['composer-audit'], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^PASS ci-pipelines: both pipelines run composer ci$/m',
            $result['stdout'],
        );
    }

    public function testCiPipelinesMissingGitlabAppWarnsAndFailsOnlyTheMissingComposerAudit(): void
    {
        $result = $this->runProctor($this->app('ci-pipelines-missing-gitlab-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(['composer-audit'], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^WARN ci-pipelines: ci-pipelines-gitlab-missing: /m',
            $result['stdout'],
        );
    }

    public function testCiPipelinesMissingGithubAppPassesAndFailsOnlyTheMissingComposerAudit(): void
    {
        $result = $this->runProctor($this->app('ci-pipelines-missing-github-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(['composer-audit'], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^PASS ci-pipelines: gitlab pipeline runs composer ci$/m',
            $result['stdout'],
        );
    }

    public function testCiPipelinesGitlabNoCiAppWarnsAndFailsOnlyTheMissingComposerAudit(): void
    {
        $result = $this->runProctor($this->app('ci-pipelines-gitlab-no-ci-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(['composer-audit'], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^WARN ci-pipelines: ci-pipelines-gitlab-no-ci: /m',
            $result['stdout'],
        );
    }

    public function testCiPipelinesAcmeAppWarnsAboutTheMissingGitlabPipelineAndFailsOnlyTheMissingComposerAudit(): void
    {
        $result = $this->runProctor($this->app('ci-pipelines-acme-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(['composer-audit'], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^WARN ci-pipelines: ci-pipelines-gitlab-missing: /m',
            $result['stdout'],
        );
    }

    public function testCiPipelinesAcmeGitlabOnlyAppPassesAndFailsOnlyTheMissingComposerAudit(): void
    {
        $result = $this->runProctor($this->app('ci-pipelines-acme-gitlab-only-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(['composer-audit'], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^PASS ci-pipelines: gitlab pipeline runs composer ci$/m',
            $result['stdout'],
        );
    }

    public function testCiPipelinesGithubMainAppWarnsOnTheUnpinnedRefAndFailsOnlyTheMissingComposerAudit(): void
    {
        $result = $this->runProctor($this->app('ci-pipelines-github-main-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(['composer-audit'], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^WARN ci-pipelines: ci-pipelines-unpinned-uses: uses "waynestate\/preflight\/.github\/workflows\/php-package\.yml@main" is not pinned to a 40-hex commit SHA; pin it to a commit SHA$/m',
            $result['stdout'],
        );
    }

    public function testNoComposerAppExitsOneWithAPhpFloorFailNotAToolError(): void
    {
        $result = $this->runProctor($this->noComposerApp());

        self::assertSame(1, $result['exit']);
        // No exception and no tool error: stderr stays empty.
        self::assertSame('', $result['stderr']);
        self::assertSame(['php-floor', 'lock-committed', 'composer-audit'], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^FAIL php-floor: no composer\.json: the app declares no PHP version$/m',
            $result['stdout'],
        );
        self::assertMatchesRegularExpression(
            '/^PASS unbounded-constraints: no composer\.json, so no constraints to check$/m',
            $result['stdout'],
        );
        // lock-committed keeps its current behaviour when composer.lock is absent.
        self::assertMatchesRegularExpression(
            '/^FAIL lock-committed: composer\.lock is missing$/m',
            $result['stdout'],
        );
    }

    public function testNoComposerAppGetsAResultLineInEveryFormat(): void
    {
        foreach (['text', 'json', 'codequality', 'sarif'] as $format) {
            $result = $this->runProctor($this->noComposerApp(), $format);

            self::assertSame(1, $result['exit'], sprintf('format %s', $format));
            self::assertSame('', $result['stderr'], sprintf('format %s', $format));
            self::assertNotSame('', trim($result['stdout']), sprintf('format %s', $format));
        }
    }

    public function testNoComposerAppJsonReportCarriesThePhpFloorFailFinding(): void
    {
        $result = $this->runProctor($this->noComposerApp(), 'json');

        self::assertSame(1, $result['exit']);
        $data = json_decode($result['stdout'], true);
        self::assertIsArray($data);

        $byRule = [];
        foreach ($data['findings'] as $finding) {
            $byRule[$finding['rule_id']][] = $finding;
        }

        self::assertCount(1, $byRule['proctor/php-floor']);
        self::assertSame('fail', $byRule['proctor/php-floor'][0]['status']);
        self::assertSame('no composer.json: the app declares no PHP version', $byRule['proctor/php-floor'][0]['message']);
        self::assertSame('composer.json', $byRule['proctor/php-floor'][0]['path']);

        self::assertCount(1, $byRule['proctor/unbounded-constraints']);
        self::assertSame('pass', $byRule['proctor/unbounded-constraints'][0]['status']);
        self::assertSame('no composer.json, so no constraints to check', $byRule['proctor/unbounded-constraints'][0]['message']);
    }

    public function testTextOutputOfAPassingAndAFailingAppCarriesNoEscapeByte(): void
    {
        foreach (['pass-app', 'eol-app'] as $name) {
            $stdout = $this->runProctor($this->app($name))['stdout'];

            self::assertStringNotContainsString(
                "\x1b",
                $stdout,
                sprintf('an escape byte reached the text output of %s', $name),
            );
        }
    }

    public function testMalformedComposerJsonStillExitsTwo(): void
    {
        $appPath = sys_get_temp_dir() . '/proctor-malformed-composer-' . uniqid('', true);
        self::assertTrue(is_dir($appPath) || mkdir($appPath, 0777, true));
        file_put_contents($appPath . '/composer.json', '{ "require": ');

        try {
            $result = $this->runProctor($appPath);

            self::assertSame(2, $result['exit']);
            self::assertStringContainsString('is not valid JSON', $result['stderr']);
        } finally {
            unlink($appPath . '/composer.json');
            rmdir($appPath);
        }
    }

    public function testTagLikeConstraintReachesTheTextReportLiterally(): void
    {
        $appPath = sys_get_temp_dir() . '/proctor-tag-like-' . uniqid('', true);
        self::assertTrue(is_dir($appPath) || mkdir($appPath, 0777, true));
        // The star alternative is what makes the constraint unbounded and so
        // reported; the tag-like token must survive the whole trip to stdout
        // unchanged, which only happens because the report prints raw.
        file_put_contents($appPath . '/composer.json', json_encode([
            'require' => [
                'php' => '^8.3',
                'vendor/tag' => '* || <error>*</error>',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $lockSource = __DIR__ . '/fixtures/apps/pass-app/composer.lock';
        if (is_file($lockSource)) {
            copy($lockSource, $appPath . '/composer.lock');
        }

        try {
            $result = $this->runProctor($appPath);

            self::assertStringContainsString('<error>*</error>', $result['stdout']);
        } finally {
            @unlink($appPath . '/composer.json');
            @unlink($appPath . '/composer.lock');
            @rmdir($appPath);
        }
    }

    /**
     * The absolute path of the fixture app that has no composer.json.
     */
    private function noComposerApp(): string
    {
        return __DIR__ . '/fixtures/no-composer';
    }
}
