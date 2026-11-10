<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CiPipelinesCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\GitlabPipeline;
use Waynestate\Proctor\Check\Mysql56CiCheck;
use Waynestate\Proctor\Check\PreflightGatesCheck;
use Waynestate\Proctor\Check\PreflightPinnedCheck;
use Waynestate\Proctor\Creed\Policy;

/**
 * A split pipeline: .gitlab-ci.yml runs composer ci and includes
 * ci/preflight.yml, which holds every Preflight include (proctor with
 * allow_failure, mysql56-migrate pinned, php-test at @main) and a php-test
 * job that re-declares its script. Every pipeline check must grade the two
 * files as one pipeline, the way GitLab merges them, and name the included
 * file in its findings. Until now each check read the root file alone and
 * saw no Preflight at all.
 */
final class SplitPipelineTest extends TestCase
{
    private const APP = __DIR__ . '/../fixtures/apps/preflight-split-pipeline-app';

    private function context(): CheckContext
    {
        return new CheckContext(
            self::APP,
            Policy::fromFile(__DIR__ . '/../fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
        );
    }

    /**
     * (rule id, path, line) for each finding, in order.
     *
     * @param list<Finding> $findings
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function triples(array $findings): array
    {
        return array_map(
            static fn(Finding $f): array => [$f->ruleId, (string) $f->path, $f->line],
            $findings,
        );
    }

    public function testThePipelineIsTheRootFileThenItsLocalIncludes(): void
    {
        $pipeline = GitlabPipeline::read(self::APP);

        self::assertNotNull($pipeline);
        self::assertSame(['.gitlab-ci.yml', 'ci/preflight.yml'], array_column($pipeline->files, 'path'));
        self::assertSame([], $pipeline->problems);
        self::assertTrue($pipeline->hasIncludedFiles());
    }

    public function testNoPipelineFileReadsAsNull(): void
    {
        self::assertNull(GitlabPipeline::read(__DIR__ . '/../fixtures/apps/security-app'));
    }

    public function testPreflightGatesSeesTheIncludedComponentsAndTheirAllowFailure(): void
    {
        $findings = (new PreflightGatesCheck())->run($this->context());

        // No no-include and no no-proctor-job: the proctor component is
        // included, one file down.
        self::assertSame(
            [['proctor/preflight-gates/allow-failure', 'ci/preflight.yml', 4]],
            $this->triples($findings),
        );
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
    }

    public function testPreflightPinnedSeesTheUnpinnedIncludeAndTheScriptOverride(): void
    {
        $findings = (new PreflightPinnedCheck())->run($this->context());

        self::assertSame(
            [
                ['proctor/preflight-pinned/unpinned', 'ci/preflight.yml', 6],
                ['proctor/preflight-pinned/script-override', 'ci/preflight.yml', 7],
            ],
            $this->triples($findings),
        );
        self::assertStringContainsString('php-test@main', $findings[0]->message);
    }

    public function testMysql56CiSeesTheIncludedMigrateComponent(): void
    {
        $findings = (new Mysql56CiCheck())->run($this->context());

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_INFO, $findings[0]->status);
        self::assertSame('proctor/mysql56-ci/held', $findings[0]->ruleId);
    }

    public function testCiPipelinesPassesTheSplitPipeline(): void
    {
        $findings = (new CiPipelinesCheck())->run($this->context());

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('gitlab pipeline runs composer ci', $findings[0]->message);
    }

    public function testAnIncludeWithNoPreflightAnywhereSaysWhereItLooked(): void
    {
        $appPath = sys_get_temp_dir() . '/proctor-split-' . bin2hex(random_bytes(4));
        mkdir($appPath . '/ci', 0777, true);
        file_put_contents($appPath . '/.gitlab-ci.yml', "include: ci/test.yml\n");
        file_put_contents($appPath . '/ci/test.yml', "test:\n  script:\n    - composer ci\n");

        try {
            $findings = (new PreflightGatesCheck())->run(new CheckContext(
                $appPath,
                Policy::fromFile(__DIR__ . '/../fixtures/policy.json'),
                new DateTimeImmutable('2026-10-27'),
            ));
        } finally {
            unlink($appPath . '/ci/test.yml');
            rmdir($appPath . '/ci');
            unlink($appPath . '/.gitlab-ci.yml');
            rmdir($appPath);
        }

        self::assertSame(
            [
                'preflight-gates-no-include: .gitlab-ci.yml and the local files it includes have no Preflight component, so none of the stack\'s gates run; include php-test, composer-audit and proctor from Preflight, pinned to a release tag',
                'preflight-gates-no-proctor-job: no job in .gitlab-ci.yml or the local files it includes runs PROCTOR; include the Preflight proctor component, pinned to a release tag, so every pipeline checks the app against the policy',
            ],
            array_map(static fn(Finding $f): string => $f->message, $findings),
        );
    }
}
