<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\PreflightGatesCheck;
use Waynestate\Proctor\Creed\Policy;

/**
 * Tests the PreflightGatesCheck on a temp app dir whose only file is a
 * .gitlab-ci.yml, so each flag of Preflight practice 3.3 (no include, a gate
 * that may fail, no proctor job) is pinned with its rule id, line and
 * message, without touching the fixture apps.
 */
final class PreflightGatesCheckTest extends TestCase
{
    /** A pipeline that includes the three core gates, pinned and blocking. */
    private const GOOD = <<<'YAML'
include:
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/php-test@0.4.0
    inputs:
      php_versions: ["8.3", "8.4", "8.5"]
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/composer-audit@0.4.0
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/proctor@0.4.0
    inputs:
      allow_failure: false

composer-ci:
  image: composer:2.10.3
  script:
    - composer ci

YAML;

    private string $appPath = '';

    protected function tearDown(): void
    {
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
            $this->appPath = '';
        }
    }

    public function testAPipelineWithBlockingGatesAndAProctorJobPasses(): void
    {
        $findings = $this->runCheck(self::GOOD);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/preflight-gates', $findings[0]->ruleId);
        self::assertNull($findings[0]->path);
        self::assertSame('Preflight gates are included, none may fail softly, and PROCTOR runs', $findings[0]->message);
    }

    public function testNoPipelineIsSilent(): void
    {
        $this->appDir();

        $findings = (new PreflightGatesCheck())->run($this->context());

        self::assertSame([], $findings);
    }

    public function testAPipelineThatDoesNotParseIsSilent(): void
    {
        self::assertSame([], $this->runCheck("include:\n  - component: [unclosed\n"));
    }

    public function testNoPreflightIncludeWarnsTwice(): void
    {
        $findings = $this->runCheck("composer-ci:\n  script:\n    - composer ci\n");

        self::assertSame(
            [
                ['proctor/preflight-gates/no-include', Finding::STATUS_WARNING, '.gitlab-ci.yml', 1],
                ['proctor/preflight-gates/no-proctor-job', Finding::STATUS_WARNING, '.gitlab-ci.yml', 1],
            ],
            $this->rows($findings),
        );
        self::assertSame(
            'preflight-gates-no-include: .gitlab-ci.yml includes no Preflight component, so none of the stack\'s gates run; include php-test, composer-audit and proctor from Preflight, pinned to a release tag',
            $findings[0]->message,
        );
        self::assertSame(
            'preflight-gates-no-proctor-job: no job in .gitlab-ci.yml runs PROCTOR; include the Preflight proctor component, pinned to a release tag, so every pipeline checks the app against the policy',
            $findings[1]->message,
        );
    }

    /**
     * A pipeline whose proctor include sets the given proctor_command.
     */
    private function withProctorCommand(string $command): string
    {
        return <<<YAML
include:
  - component: \$CI_SERVER_FQDN/enterprise-apps/preflight/php-test@0.4.0
  - component: \$CI_SERVER_FQDN/enterprise-apps/preflight/proctor@0.5.0
    inputs:
      git_depth: "0"
      proctor_command: {$command}

YAML;
    }

    public function testAProctorCommandThatRunsNoProctorWarns(): void
    {
        $findings = $this->runCheck($this->withProctorCommand('"true"'));

        self::assertSame(
            [['proctor/preflight-gates/proctor-command', Finding::STATUS_WARNING, '.gitlab-ci.yml', 6]],
            $this->rows($findings),
        );
        self::assertSame(
            'preflight-gates-proctor-command: the Preflight proctor component\'s proctor_command "true" runs neither proctor check nor proctor gate, so the job passes without grading the app; leave the input at its default or run vendor/bin/proctor check on its own',
            $findings[0]->message,
        );
    }

    public function testAProctorCommandThatMasksTheExitCodeWarns(): void
    {
        foreach (['vendor/bin/proctor check --format=codequality || true', 'vendor/bin/proctor check | tee out.txt'] as $command) {
            $findings = $this->runCheck($this->withProctorCommand('"' . $command . '"'));

            self::assertSame(
                [['proctor/preflight-gates/proctor-command', Finding::STATUS_WARNING, '.gitlab-ci.yml', 6]],
                $this->rows($findings),
                $command,
            );
            self::assertStringContainsString('so the shell reports another command\'s exit code', $findings[0]->message);
        }
    }

    public function testAPlainProctorCommandPasses(): void
    {
        foreach ([
            'vendor/bin/proctor check --format=codequality',
            '"cd deploy && vendor/bin/proctor check --path=.. > report.json"',
            'php proctor.phar gate --path=.',
        ] as $command) {
            $findings = $this->runCheck($this->withProctorCommand($command));

            self::assertSame(Finding::STATUS_PASS, $findings[0]->status, $command);
        }
    }

    public function testAJobThatRunsProctorItselfCountsAsTheProctorJob(): void
    {
        $findings = $this->runCheck(<<<'YAML'
wayneui-copies:
  script:
    - composer install --working-dir=deploy
    - deploy/vendor/bin/proctor check --path=.

YAML);

        self::assertSame(
            [['proctor/preflight-gates/no-include', Finding::STATUS_WARNING, '.gitlab-ci.yml', 1]],
            $this->rows($findings),
        );
    }

    public function testThePharInABlockScriptCountsButACommentDoesNot(): void
    {
        $runs = $this->runCheck(<<<'YAML'
include:
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/php-test@0.4.0
gate:
  script:
    - |
      set -eu
      php build/proctor.phar gate --format=json
YAML);
        self::assertSame(Finding::STATUS_PASS, $runs[0]->status);

        $this->tearDown();

        $comment = $this->runCheck(<<<'YAML'
include:
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/php-test@0.4.0
gate:
  script:
    - |
      # vendor/bin/proctor check runs elsewhere, honest
      echo hello
YAML);
        self::assertSame(
            [['proctor/preflight-gates/no-proctor-job', Finding::STATUS_WARNING, '.gitlab-ci.yml', 1]],
            $this->rows($comment),
        );
    }

    public function testAGateComponentIncludedWithAllowFailureWarnsOnThatLine(): void
    {
        $findings = $this->runCheck(<<<'YAML'
include:
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/php-test@0.4.0
  # allow_failure: a comment that names the input is not the input.
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/proctor@0.4.0
    inputs:
      working_dir: deploy
      allow_failure: true
YAML);

        self::assertSame(
            [['proctor/preflight-gates/allow-failure', Finding::STATUS_WARNING, '.gitlab-ci.yml', 7]],
            $this->rows($findings),
        );
        self::assertSame(
            'preflight-gates-allow-failure: the Preflight proctor component is included with allow_failure: true, so the gate can fail and the pipeline still passes; remove the input once its findings are fixed',
            $findings[0]->message,
        );
    }

    public function testTheMysql56MigrateComponentIsAGate(): void
    {
        $findings = $this->runCheck(<<<'YAML'
include:
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/proctor@0.4.0
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/mysql56-migrate@0.6.0
    inputs:
      allow_failure: true
YAML);

        self::assertSame(
            [['proctor/preflight-gates/allow-failure', Finding::STATUS_WARNING, '.gitlab-ci.yml', 5]],
            $this->rows($findings),
        );
        self::assertSame(
            'preflight-gates-allow-failure: the Preflight mysql56-migrate component is included with allow_failure: true, so the gate can fail and the pipeline still passes; remove the input once its findings are fixed',
            $findings[0]->message,
        );
    }

    public function testAReDeclaredGateJobWithAllowFailureWarnsOnThatLine(): void
    {
        $findings = $this->runCheck(self::GOOD . <<<'YAML'
php-test:
  allow_failure: true

YAML);

        self::assertSame(
            [['proctor/preflight-gates/allow-failure', Finding::STATUS_WARNING, '.gitlab-ci.yml', 15]],
            $this->rows($findings),
        );
        self::assertSame(
            'preflight-gates-allow-failure: job php-test (the Preflight php-test gate) sets allow_failure, so the gate can fail and the pipeline still passes; remove it once the findings are fixed',
            $findings[0]->message,
        );
    }

    public function testTheJobNameInputNamesTheGateJobAndExitCodesCount(): void
    {
        $findings = $this->runCheck(<<<'YAML'
include:
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/proctor@0.4.0
    inputs:
      job_name: proctor-deploy
proctor:
  allow_failure: true
  script:
    - echo not the gate
proctor-deploy:
  allow_failure:
    exit_codes: [1]
YAML);

        self::assertSame(
            [['proctor/preflight-gates/allow-failure', Finding::STATUS_WARNING, '.gitlab-ci.yml', 10]],
            $this->rows($findings),
        );
        self::assertStringContainsString('job proctor-deploy (the Preflight proctor gate)', $findings[0]->message);
    }

    public function testRenovateIsNotAGateAndAllowFailureFalseIsBlocking(): void
    {
        $findings = $this->runCheck(<<<'YAML'
include:
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/proctor@0.4.0
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/renovate@0.4.0
renovate:
  allow_failure: true
proctor:
  allow_failure: false
YAML);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testEveryPlaceAGateIsSoftenedWarnsInLineOrder(): void
    {
        $findings = $this->runCheck(<<<'YAML'
include:
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/a11y@0.4.0
    inputs:
      allow_failure: true
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/proctor@0.4.0
proctor:
  allow_failure: true
YAML);

        self::assertSame(
            [
                ['proctor/preflight-gates/allow-failure', Finding::STATUS_WARNING, '.gitlab-ci.yml', 4],
                ['proctor/preflight-gates/allow-failure', Finding::STATUS_WARNING, '.gitlab-ci.yml', 7],
            ],
            $this->rows($findings),
        );
        self::assertStringContainsString('the Preflight a11y component', $findings[0]->message);
        self::assertStringContainsString('job proctor (the Preflight proctor gate)', $findings[1]->message);
    }

    public function testTheCheckIsRegisteredAndNotInTheDeployGate(): void
    {
        self::assertNotContains('preflight-gates', CheckSet::GATE);
    }

    /**
     * (rule id, status, path, line) for each finding, in order.
     *
     * @param list<Finding> $findings
     *
     * @return list<array{0: string, 1: string, 2: string|null, 3: int}>
     */
    private function rows(array $findings): array
    {
        return array_map(
            static fn(Finding $f): array => [$f->ruleId, $f->status, $f->path, $f->line],
            $findings,
        );
    }

    /**
     * Writes the pipeline into a fresh temp app and runs the check on it.
     *
     * @return list<Finding>
     */
    private function runCheck(string $yml): array
    {
        file_put_contents($this->appDir() . '/.gitlab-ci.yml', $yml);

        return (new PreflightGatesCheck())->run($this->context());
    }

    private function context(): CheckContext
    {
        return new CheckContext(
            $this->appDir(),
            Policy::bundled(),
            new DateTimeImmutable('2026-11-08'),
        );
    }

    /**
     * The temp app dir, created on first use and remembered for cleanup.
     */
    private function appDir(): string
    {
        if ($this->appPath === '') {
            $this->appPath = sys_get_temp_dir() . '/proctor-preflightgates-' . uniqid('', true);
            self::assertTrue(is_dir($this->appPath) || mkdir($this->appPath, 0777, true));
        }

        return $this->appPath;
    }

    /**
     * Removes a file or directory tree.
     */
    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
