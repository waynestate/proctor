<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CiPipelinesCheck;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Policy\JsonFilePolicySource;

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
            new JsonFilePolicySource(__DIR__ . '/fixtures/policy.json'),
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

    public function testMissingGitlabFileFails(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-missing-gitlab-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame(
            [['ci-pipelines-gitlab-missing', '.gitlab-ci.yml', 1]],
            $this->triples($findings),
        );
    }

    public function testMissingGithubFileFails(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-missing-github-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame(
            [['ci-pipelines-github-missing', '.github/workflows/ci.yml', 1]],
            $this->triples($findings),
        );
    }

    public function testGitlabPipelineWithoutComposerCiFails(): void
    {
        $findings = (new CiPipelinesCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/ci-pipelines-gitlab-no-ci-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
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

    public function testUnparseableGitlabFileFailsWithTheParseRule(): void
    {
        // An unclosed flow sequence is invalid YAML, so the pipeline could
        // not run at all: the check must say the file could not be parsed.
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
            self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
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
}
