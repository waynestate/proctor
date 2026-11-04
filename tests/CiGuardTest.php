<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use Waynestate\Proctor\Policy\CiGuard;

/**
 * The CI guard: --policy and --today are refused in CI unless a trusted job
 * sets PROCTOR_ALLOW_OVERRIDES=1. Unit tests cover the decision table of
 * CiGuard::refusal(); CLI tests run bin/proctor with the CI markers set.
 */
final class CiGuardTest extends ProctorTestCase
{
    private const REFUSAL_BOTH = "Refused: --policy and --today are not accepted in CI, because the app could pick its own policy or date. A trusted job (a protected or group-level CI variable, not the app's .gitlab-ci.yml) can set PROCTOR_ALLOW_OVERRIDES=1 to allow them.";

    private const REFUSAL_POLICY = "Refused: --policy is not accepted in CI, because the app could pick its own policy or date. A trusted job (a protected or group-level CI variable, not the app's .gitlab-ci.yml) can set PROCTOR_ALLOW_OVERRIDES=1 to allow them.";

    private const REFUSAL_TODAY = "Refused: --today is not accepted in CI, because the app could pick its own policy or date. A trusted job (a protected or group-level CI variable, not the app's .gitlab-ci.yml) can set PROCTOR_ALLOW_OVERRIDES=1 to allow them.";

    /** @var array<string, string> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['CI', 'GITLAB_CI', 'GITHUB_ACTIONS', 'PROCTOR_ALLOW_OVERRIDES'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $this->savedEnv[$name] = $value;
            }
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($name . '=' . $value);
        }
        $this->savedEnv = [];
        parent::tearDown();
    }

    public function testOptionsAreAllowedOutsideCi(): void
    {
        self::assertNull(CiGuard::refusal('policy.json', self::TODAY));
    }

    public function testNothingIsRefusedInCiWhenNeitherOptionIsGiven(): void
    {
        $this->setEnv(['CI' => 'true']);

        self::assertNull(CiGuard::refusal(null, null));
    }

    public function testAnEmptyPolicyValueCountsAsNotGiven(): void
    {
        $this->setEnv(['CI' => 'true']);

        self::assertNull(CiGuard::refusal('', null));
    }

    public function testTodayIsRefusedInCiWithTheExactMessage(): void
    {
        $this->setEnv(['CI' => 'true']);

        self::assertSame(self::REFUSAL_TODAY, CiGuard::refusal(null, self::TODAY));
    }

    public function testPolicyIsRefusedInCiNamingOnlyPolicy(): void
    {
        $this->setEnv(['CI' => 'true']);

        self::assertSame(self::REFUSAL_POLICY, CiGuard::refusal('policy.json', null));
    }

    public function testBothOptionsAreRefusedInCiNamingBoth(): void
    {
        $this->setEnv(['CI' => 'true']);

        self::assertSame(self::REFUSAL_BOTH, CiGuard::refusal('policy.json', self::TODAY));
    }

    public function testGitlabCiMarksTheRunAsCi(): void
    {
        $this->setEnv(['GITLAB_CI' => 'true']);

        self::assertSame(self::REFUSAL_TODAY, CiGuard::refusal(null, self::TODAY));
    }

    public function testGithubActionsMarksTheRunAsCi(): void
    {
        $this->setEnv(['GITHUB_ACTIONS' => 'true']);

        self::assertSame(self::REFUSAL_TODAY, CiGuard::refusal(null, self::TODAY));
    }

    public function testZeroAndFalseValuesDoNotMarkTheRunAsCi(): void
    {
        foreach (['0', 'false', 'FALSE', 'False'] as $value) {
            $this->setEnv(['CI' => $value]);
            self::assertNull(CiGuard::refusal('policy.json', self::TODAY), "CI={$value} must not count as CI");
        }
    }

    public function testAllowOverridesExactlyOneAllowsTheOptions(): void
    {
        $this->setEnv(['CI' => 'true', 'PROCTOR_ALLOW_OVERRIDES' => '1']);

        self::assertNull(CiGuard::refusal('policy.json', self::TODAY));
    }

    public function testAllowOverridesOtherThanOneDoesNotAllowTheOptions(): void
    {
        foreach (['0', 'true', 'yes', '2', ' 1', '1 '] as $value) {
            $this->setEnv(['CI' => 'true', 'PROCTOR_ALLOW_OVERRIDES' => $value]);
            self::assertSame(self::REFUSAL_BOTH, CiGuard::refusal('policy.json', self::TODAY), 'PROCTOR_ALLOW_OVERRIDES=' . var_export($value, true) . ' must not allow the options');
        }
    }

    public function testTheCliRefusesBothOptionsInCi(): void
    {
        $result = $this->runProctor($this->app('pass-app'), 'text', null, null, null, ['CI' => 'true']);

        self::assertSame(2, $result['exit']);
        self::assertSame('', $result['stdout']);
        self::assertSame(self::REFUSAL_BOTH . "\n", $result['stderr']);
    }

    public function testTheCliRunsNormallyWhenATrustedJobAllowsOverrides(): void
    {
        $result = $this->runProctor($this->app('pass-app'), 'text', null, null, null, [
            'CI' => 'true',
            'PROCTOR_ALLOW_OVERRIDES' => '1',
        ]);

        self::assertSame(0, $result['exit']);
        self::assertSame([], $this->failCheckIds($result['stdout']));
        self::assertStringContainsString('RESULT: pass', $result['stdout']);
    }

    /**
     * @param array<string, string> $vars
     */
    private function setEnv(array $vars): void
    {
        foreach ($vars as $name => $value) {
            putenv($name . '=' . $value);
        }
    }
}
