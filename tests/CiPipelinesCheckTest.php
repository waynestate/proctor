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

    /**
     * A temp app whose composer.json names it a stack package.
     */
    private function stackAppPath(): string
    {
        $appPath = sys_get_temp_dir() . '/proctor-ci-pipelines-' . uniqid('', true);
        self::assertTrue(is_dir($appPath) || mkdir($appPath, 0777, true));
        file_put_contents(
            $appPath . '/composer.json',
            <<<'JSON'
{
    "name": "waynestate/fixture",
    "require": {
        "php": "^8.4"
    }
}
JSON,
        );

        return $appPath;
    }

    private function removeStackApp(string $appPath): void
    {
        if (is_file($appPath . '/.gitlab-ci.yml')) {
            unlink($appPath . '/.gitlab-ci.yml');
        }
        unlink($appPath . '/composer.json');
        rmdir($appPath);
    }
}
