<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check (text format) on each fixture app and asserts the
 * exit code and the check ids that fail.
 */
final class CheckCommandTest extends ProctorTestCase
{
    public function testPassAppExitsZeroWithNoFailures(): void
    {
        $result = $this->runProctor($this->app('pass-app'));

        self::assertSame(0, $result['exit']);
        self::assertSame([], $this->failCheckIds($result['stdout']));
    }

    public function testPassAppPassesFloorUnboundedAndLock(): void
    {
        $stdout = $this->runProctor($this->app('pass-app'))['stdout'];

        self::assertMatchesRegularExpression('/^PASS php-floor: /m', $stdout);
        self::assertMatchesRegularExpression('/^PASS unbounded-constraints: /m', $stdout);
        self::assertMatchesRegularExpression('/^PASS lock-committed: /m', $stdout);
    }

    public function testPassAppReportsInfoForComposerAudit(): void
    {
        $stdout = $this->runProctor($this->app('pass-app'))['stdout'];

        self::assertMatchesRegularExpression('/^INFO composer-audit: /m', $stdout);
    }

    public function testEolAppExitsOneAndFailsOnlyPhpFloor(): void
    {
        $result = $this->runProctor($this->app('eol-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(['php-floor'], $this->failCheckIds($result['stdout']));
    }

    public function testUnboundedAppFailsUnboundedConstraintsForDevMasterAndStar(): void
    {
        $result = $this->runProctor($this->app('unbounded-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(
            ['unbounded-constraints', 'unbounded-constraints'],
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
            ['unbounded-constraints', 'unbounded-constraints'],
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
        self::assertSame(['overrides'], $this->failCheckIds($result['stdout']));
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

    public function testCiPipelinesGoodAppExitsZeroAndPassesCiPipelines(): void
    {
        $result = $this->runProctor($this->app('ci-pipelines-good-app'));

        self::assertSame(0, $result['exit']);
        self::assertSame([], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^PASS ci-pipelines: both pipelines run composer ci$/m',
            $result['stdout'],
        );
    }

    public function testCiPipelinesMissingGitlabAppExitsZeroAndWarns(): void
    {
        $result = $this->runProctor($this->app('ci-pipelines-missing-gitlab-app'));

        self::assertSame(0, $result['exit']);
        self::assertSame([], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^WARN ci-pipelines: ci-pipelines-gitlab-missing: /m',
            $result['stdout'],
        );
    }

    public function testCiPipelinesMissingGithubAppExitsZeroAndPasses(): void
    {
        $result = $this->runProctor($this->app('ci-pipelines-missing-github-app'));

        self::assertSame(0, $result['exit']);
        self::assertSame([], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^PASS ci-pipelines: gitlab pipeline runs composer ci$/m',
            $result['stdout'],
        );
    }

    public function testCiPipelinesGitlabNoCiAppExitsZeroAndWarns(): void
    {
        $result = $this->runProctor($this->app('ci-pipelines-gitlab-no-ci-app'));

        self::assertSame(0, $result['exit']);
        self::assertSame([], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^WARN ci-pipelines: ci-pipelines-gitlab-no-ci: /m',
            $result['stdout'],
        );
    }

    public function testCiPipelinesAcmeAppExitsZeroAndWarnsAboutTheMissingGitlabPipeline(): void
    {
        $result = $this->runProctor($this->app('ci-pipelines-acme-app'));

        self::assertSame(0, $result['exit']);
        self::assertSame([], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^WARN ci-pipelines: ci-pipelines-gitlab-missing: /m',
            $result['stdout'],
        );
    }

    public function testCiPipelinesAcmeGitlabOnlyAppExitsZeroAndPasses(): void
    {
        $result = $this->runProctor($this->app('ci-pipelines-acme-gitlab-only-app'));

        self::assertSame(0, $result['exit']);
        self::assertSame([], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^PASS ci-pipelines: gitlab pipeline runs composer ci$/m',
            $result['stdout'],
        );
    }

    public function testCiPipelinesGithubMainAppExitsZeroAndWarnsOnTheUnpinnedRef(): void
    {
        $result = $this->runProctor($this->app('ci-pipelines-github-main-app'));

        self::assertSame(0, $result['exit']);
        self::assertSame([], $this->failCheckIds($result['stdout']));
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
        self::assertSame(['php-floor', 'lock-committed'], $this->failCheckIds($result['stdout']));
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

    /**
     * The absolute path of the fixture app that has no composer.json.
     */
    private function noComposerApp(): string
    {
        return __DIR__ . '/fixtures/no-composer';
    }
}
