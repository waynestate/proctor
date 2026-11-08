<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CiPipelinesCheck;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;

/**
 * Calls the ci-pipelines check directly, the way bin/proctor builds its
 * context: the fixture policy and a fixed --today.
 */
final class CiPipelinesCheckTest extends TestCase
{
    /** The fixed date every run uses. */
    private const TODAY = '2026-10-27';

    /**
     * @param string $appPath the app to check
     */
    private function contextFor(string $appPath): CheckContext
    {
        return new CheckContext(
            $appPath,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY),
        );
    }

    /**
     * The (rule id, path, line) triples of the findings, in order.
     *
     * @param list<Finding> $findings
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function triples(array $findings): array
    {
        return array_map(
            static fn(Finding $finding): array => [
                explode(':', $finding->message, 2)[0],
                (string) $finding->path,
                $finding->line,
            ],
            $findings,
        );
    }

    public function testGoodAppGivesExactlyOnePassFinding(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-good-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('both pipelines run composer ci', $findings[0]->message);
        self::assertNull($findings[0]->path);
    }

    public function testMissingGitlabFileWarnsForOtherProjects(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-missing-gitlab-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame(
            [['ci-pipelines-gitlab-missing', '.gitlab-ci.yml', 1]],
            $this->triples($findings),
        );
    }

    public function testMissingGithubFilePassesForOtherProjects(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-missing-github-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('gitlab pipeline runs composer ci', $findings[0]->message);
        self::assertNull($findings[0]->path);
    }

    public function testGitlabPipelineWithoutComposerCiWarnsForOtherProjects(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-gitlab-no-ci-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame(
            [['ci-pipelines-gitlab-no-ci', '.gitlab-ci.yml', 1]],
            $this->triples($findings),
        );
    }

    public function testGithubWorkflowOnMainRefWarnsButDoesNotFail(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-github-main-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame(
            [['ci-pipelines-unpinned-uses', '.github/workflows/ci.yml', 12]],
            $this->triples($findings),
        );
        self::assertStringContainsString(
            'waynestate/preflight/.github/workflows/php-package.yml@main',
            $findings[0]->message,
        );
    }

    public function testUnparseableGitlabFileWarnsForOtherProjects(): void
    {
        // An unclosed flow sequence is invalid YAML, so the pipeline could
        // not run at all: the check must say the file could not be parsed.
        // The app has no composer.json, so it is an "other project" and the
        // parse error is a warning, not a fail.
        $appPath = sys_get_temp_dir() . '/proctor-ci-pipelines-' . uniqid('', true);
        self::assertTrue(
            is_dir($appPath . '/.github/workflows') || mkdir($appPath . '/.github/workflows', 0777, true),
        );
        file_put_contents(
            $appPath . '/.gitlab-ci.yml',
            "composer-ci:\n  script: [composer ci\n",
        );
        file_put_contents(
            $appPath . '/.github/workflows/ci.yml',
            <<<'YAML'
name: CI

on:
  push:
    branches: ['**']

permissions:
  contents: read

jobs:
  ci:
    uses: waynestate/preflight/.github/workflows/php-package.yml@65711c350bd0ddf637f0933482d7bdae2a9b3c3b
    with:
      php-versions: '["8.3","8.4","8.5"]'
      ci-command: composer ci
YAML,
        );

        try {
            $findings = (new CiPipelinesCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
            self::assertSame(
                [['ci-pipelines-gitlab-parse', '.gitlab-ci.yml', 1]],
                $this->triples($findings),
            );
        } finally {
            unlink($appPath . '/.gitlab-ci.yml');
            unlink($appPath . '/.github/workflows/ci.yml');
            rmdir($appPath . '/.github/workflows');
            rmdir($appPath . '/.github');
            rmdir($appPath);
        }
    }

    public function testAcmeAppWithoutPipelinesWarnsAboutTheMissingGitlabPipeline(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-acme-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame(
            [['ci-pipelines-gitlab-missing', '.gitlab-ci.yml', 1]],
            $this->triples($findings),
        );
    }

    public function testAcmeAppWithOnlyAGoodGitlabPipelinePasses(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-acme-gitlab-only-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('gitlab pipeline runs composer ci', $findings[0]->message);
        self::assertNull($findings[0]->path);
    }

    public function testStackPackageWithoutPipelinesFailsBothFiles(): void
    {
        $appPath = $this->stackAppPath();

        try {
            $findings = (new CiPipelinesCheck())->run($this->contextFor($appPath));

            self::assertCount(2, $findings);
            self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
            self::assertSame(Finding::STATUS_FAIL, $findings[1]->status);
            self::assertSame(
                [
                    ['ci-pipelines-github-missing', '.github/workflows/ci.yml', 1],
                    ['ci-pipelines-gitlab-missing', '.gitlab-ci.yml', 1],
                ],
                $this->triples($findings),
            );
        } finally {
            $this->removeStackApp($appPath);
        }
    }

    public function testStackPackageWithGitlabWithoutComposerCiFails(): void
    {
        $appPath = $this->stackAppPath();
        file_put_contents(
            $appPath . '/.gitlab-ci.yml',
            "lint:\n  script:\n    - composer validate\n",
        );

        try {
            $findings = (new CiPipelinesCheck())->run($this->contextFor($appPath));

            self::assertCount(2, $findings);
            self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
            self::assertSame(Finding::STATUS_FAIL, $findings[1]->status);
            self::assertSame(
                [
                    ['ci-pipelines-github-missing', '.github/workflows/ci.yml', 1],
                    ['ci-pipelines-gitlab-no-ci', '.gitlab-ci.yml', 1],
                ],
                $this->triples($findings),
            );
        } finally {
            $this->removeStackApp($appPath);
        }
    }

    public function testPrivateStackPackageWithoutGithubWorkflowPasses(): void
    {
        $appPath = $this->stackAppPath(false);
        file_put_contents(
            $appPath . '/.gitlab-ci.yml',
            "composer-ci:\n  script:\n    - composer ci\n",
        );

        try {
            $findings = (new CiPipelinesCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
            self::assertSame('gitlab pipeline runs composer ci', $findings[0]->message);
            self::assertNull($findings[0]->path);
        } finally {
            $this->removeStackApp($appPath);
        }
    }

    public function testPrivateStackPackageWithGithubWorkflowWarns(): void
    {
        $appPath = $this->stackAppPath(false);
        file_put_contents(
            $appPath . '/.gitlab-ci.yml',
            "composer-ci:\n  script:\n    - composer ci\n",
        );
        self::assertTrue(
            is_dir($appPath . '/.github/workflows') || mkdir($appPath . '/.github/workflows', 0777, true),
        );
        file_put_contents($appPath . '/.github/workflows/ci.yml', $this->goodGithubWorkflow());

        try {
            $findings = (new CiPipelinesCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
            self::assertSame(
                [['ci-pipelines-github-private', '.github/workflows/ci.yml', 1]],
                $this->triples($findings),
            );
        } finally {
            $this->removeStackApp($appPath);
        }
    }

    public function testStackPackageWithStringFalseGithubMirrorStillFailsWhenMissing(): void
    {
        $appPath = $this->stackAppPath('false');

        try {
            $findings = (new CiPipelinesCheck())->run($this->contextFor($appPath));

            self::assertCount(2, $findings);
            self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
            self::assertSame(Finding::STATUS_FAIL, $findings[1]->status);
            self::assertSame(
                [
                    ['ci-pipelines-github-missing', '.github/workflows/ci.yml', 1],
                    ['ci-pipelines-gitlab-missing', '.gitlab-ci.yml', 1],
                ],
                $this->triples($findings),
            );
        } finally {
            $this->removeStackApp($appPath);
        }
    }

    public function testGitlabJobVariableNoAuditWarns(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-env-gitlab-job-no-audit-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/ci-pipelines/composer-audit-off', $findings[0]->ruleId);
        self::assertStringContainsString('COMPOSER_NO_AUDIT', $findings[0]->message);
    }

    public function testGitlabTopLevelNoBlockingWarns(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-env-gitlab-top-level-no-blocking-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/ci-pipelines/composer-audit-off', $findings[0]->ruleId);
        self::assertStringContainsString('COMPOSER_NO_BLOCKING', $findings[0]->message);
    }

    public function testGithubStepEnvNoAuditWarns(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-env-github-step-no-audit-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/ci-pipelines/composer-audit-off', $findings[0]->ruleId);
        self::assertStringContainsString('COMPOSER_NO_AUDIT', $findings[0]->message);
        self::assertSame('.github/workflows/ci.yml', (string) $findings[0]->path);
    }

    public function testAuditAbandonedIgnoreWarnsButReportWarnDoesNot(): void
    {
        $warns = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-env-abandoned-ignore-app'),
        );

        self::assertCount(1, $warns);
        self::assertSame(Finding::STATUS_WARNING, $warns[0]->status);
        self::assertSame('proctor/ci-pipelines/composer-audit-off', $warns[0]->ruleId);
        self::assertStringContainsString('COMPOSER_AUDIT_ABANDONED', $warns[0]->message);

        $doesNotWarn = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-env-abandoned-report-app'),
        );

        self::assertCount(1, $doesNotWarn);
        self::assertSame(Finding::STATUS_PASS, $doesNotWarn[0]->status);
        self::assertSame('gitlab pipeline runs composer ci', $doesNotWarn[0]->message);
    }

    public function testNoAuditZeroDoesNotWarn(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-env-no-audit-zero-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('gitlab pipeline runs composer ci', $findings[0]->message);
    }

    public function testGitlabTopLevelAllowOverridesOneWarns(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-allow-overrides-gitlab-top-level-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/ci-pipelines/allow-overrides', $findings[0]->ruleId);
        self::assertSame(
            'ci-pipelines-allow-overrides: PROCTOR_ALLOW_OVERRIDES is 1 in the pipeline, so PROCTOR accepts --policy and --today and the app can grade itself against a policy or date it picks; remove it (only PROCTOR\'s own pipeline needs it)',
            $findings[0]->message,
        );
        self::assertSame(
            [['ci-pipelines-allow-overrides', '.gitlab-ci.yml', 3]],
            $this->triples($findings),
        );
    }

    public function testGitlabJobVariableMapAllowOverridesOneWarns(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-allow-overrides-gitlab-job-map-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/ci-pipelines/allow-overrides', $findings[0]->ruleId);
        self::assertSame(
            [['ci-pipelines-allow-overrides', '.gitlab-ci.yml', 5]],
            $this->triples($findings),
        );
    }

    public function testGithubStepEnvAllowOverridesOneWarns(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-allow-overrides-github-step-env-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/ci-pipelines/allow-overrides', $findings[0]->ruleId);
        self::assertSame('.github/workflows/ci.yml', (string) $findings[0]->path);
        self::assertSame(
            [['ci-pipelines-allow-overrides', '.github/workflows/ci.yml', 17]],
            $this->triples($findings),
        );
    }

    public function testAllowOverridesZeroDoesNotWarn(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-allow-overrides-zero-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('gitlab pipeline runs composer ci', $findings[0]->message);
    }

    public function testGitlabScriptLineWithComposerUpdateWarns(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-composer-update-gitlab-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/ci-pipelines/composer-update', $findings[0]->ruleId);
        self::assertSame(
            [['ci-pipelines-composer-update', '.gitlab-ci.yml', 6]],
            $this->triples($findings),
        );
        self::assertSame(
            'ci-pipelines-composer-update: CI runs "composer update" in composer-ci; install from the lock with "composer install" instead (rule 3.7).',
            $findings[0]->message,
        );
    }

    public function testGithubStepRunWithComposerUpdateWarns(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-composer-update-github-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/ci-pipelines/composer-update', $findings[0]->ruleId);
        self::assertSame(
            [['ci-pipelines-composer-update', '.github/workflows/ci.yml', 17]],
            $this->triples($findings),
        );
        self::assertSame(
            'ci-pipelines-composer-update: CI runs "composer update" in Refresh dependencies; install from the lock with "composer install" instead (rule 3.7).',
            $findings[0]->message,
        );
    }

    public function testChainedCommandsWarnOncePerLine(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-composer-update-chained-app'),
        );

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame(Finding::STATUS_WARNING, $findings[1]->status);
        self::assertSame(
            [
                ['ci-pipelines-composer-update', '.gitlab-ci.yml', 5],
                ['ci-pipelines-composer-update', '.gitlab-ci.yml', 10],
            ],
            $this->triples($findings),
        );
        self::assertStringContainsString('CI runs "composer update foo/bar" in composer-ci', $findings[0]->message);
        self::assertStringContainsString('CI runs "composer update bar/baz" in refresh', $findings[1]->message);
    }

    public function testComposerUpdateInCommentsDoesNotWarn(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-composer-update-comment-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('gitlab pipeline runs composer ci', $findings[0]->message);
    }

    /**
     * A temp app whose composer.json names it a stack package. When
     * $githubMirror is not null, extra.proctor.github-mirror carries that
     * exact value (boolean false, or the string "false").
     */
    private function stackAppPath(mixed $githubMirror = null): string
    {
        $appPath = sys_get_temp_dir() . '/proctor-ci-pipelines-' . uniqid('', true);
        self::assertTrue(is_dir($appPath) || mkdir($appPath, 0777, true));
        $composer = [
            'name' => 'waynestate/fixture',
            'require' => ['php' => '^8.4'],
        ];
        if ($githubMirror !== null) {
            $composer['extra'] = ['proctor' => ['github-mirror' => $githubMirror]];
        }
        file_put_contents(
            $appPath . '/composer.json',
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return $appPath;
    }

    /**
     * A GitHub workflow that passes the check on its own: a reusable
     * workflow call pinned to a 40-hex commit SHA, with
     * ci-command: composer ci.
     */
    private function goodGithubWorkflow(): string
    {
        return <<<'YAML'
name: CI

on:
  push:
    branches: ['**']

permissions:
  contents: read

jobs:
  ci:
    uses: waynestate/preflight/.github/workflows/php-package.yml@65711c350bd0ddf637f0933482d7bdae2a9b3c3b
    with:
      php-versions: '["8.3","8.4","8.5"]'
      ci-command: composer ci
YAML;
    }

    private function removeStackApp(string $appPath): void
    {
        if (is_file($appPath . '/.gitlab-ci.yml')) {
            unlink($appPath . '/.gitlab-ci.yml');
        }
        if (is_file($appPath . '/.github/workflows/ci.yml')) {
            unlink($appPath . '/.github/workflows/ci.yml');
            rmdir($appPath . '/.github/workflows');
            rmdir($appPath . '/.github');
        }
        unlink($appPath . '/composer.json');
        rmdir($appPath);
    }
}
