<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\PhpFloorCheck;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Creed\State;

/**
 * php-floor with --php-runtime: the PHP that will serve the app is graded
 * against the declared constraint first and the policy second. The fixture
 * policy lists php 7.4 and 8.1 to 8.5; 8.4 is active until 2026-12-31.
 *
 * Checking the student's ID at the door, not just the name on the roster.
 */
final class PhpFloorRuntimeTest extends ProctorTestCase
{
    /**
     * Every php-floor finding for a fixture app, with or without a runtime.
     *
     * @return list<Finding>
     */
    private function findings(string $app, ?string $runtime, string $today = self::TODAY): array
    {
        $context = new CheckContext(
            $this->app($app),
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable($today),
            phpRuntime: $runtime,
        );

        return (new PhpFloorCheck())->run($context);
    }

    /**
     * The runtime finding: the second php-floor finding, after the floor's.
     */
    private function runtimeFinding(string $app, string $runtime): Finding
    {
        $findings = $this->findings($app, $runtime);
        self::assertCount(2, $findings, 'the floor finding, then the runtime finding');

        return $findings[1];
    }

    public function testNoRuntimeMeansOnlyTheFloorFinding(): void
    {
        self::assertCount(1, $this->findings('pass-app', null));
    }

    public function testActiveRuntimeInsideTheConstraintPasses(): void
    {
        $finding = $this->runtimeFinding('pass-app', '8.4.14');

        self::assertSame(Finding::STATUS_PASS, $finding->status);
        self::assertSame('proctor/php-floor/runtime', $finding->ruleId);
        self::assertSame(State::Active, $finding->state);
        self::assertSame('php runtime 8.4.14 is active', $finding->message);
        self::assertNull($finding->path);
    }

    public function testSecurityOnlyRuntimeWarnsWithTheEndDate(): void
    {
        // security-app declares ^8.3; 8.3 is security-only until 2027-12-31.
        $finding = $this->runtimeFinding('security-app', '8.3.27');

        self::assertSame(Finding::STATUS_WARNING, $finding->status);
        self::assertSame('proctor/php-floor/runtime', $finding->ruleId);
        self::assertSame(State::Security, $finding->state);
        self::assertSame(
            'php runtime 8.3.27 is in security-only support until 2027-12-31 (430 days left)',
            $finding->message,
        );
    }

    public function testRuntimeBelowTheConstraintFails(): void
    {
        // The case the floor alone missed: a supported ^8.4 on an 8.2 image.
        $finding = $this->runtimeFinding('pass-app', '8.2.29');

        self::assertSame(Finding::STATUS_FAIL, $finding->status);
        self::assertSame('proctor/php-floor/runtime-mismatch', $finding->ruleId);
        self::assertSame('composer.json', $finding->path);
        self::assertSame(
            'php runtime 8.2.29 is below require.php "^8.4": Composer\'s platform check stops the app on boot; serve it on a PHP the constraint allows',
            $finding->message,
        );
    }

    public function testRuntimeJustBelowTheBoundStillFails(): void
    {
        $finding = $this->runtimeFinding('pass-app', '8.3.99');

        self::assertSame(Finding::STATUS_FAIL, $finding->status);
        self::assertSame('proctor/php-floor/runtime-mismatch', $finding->ruleId);
    }

    public function testRuntimeExactlyOnTheBoundPasses(): void
    {
        $finding = $this->runtimeFinding('pass-app', '8.4.0');

        self::assertSame(Finding::STATUS_PASS, $finding->status);
        self::assertSame('proctor/php-floor/runtime', $finding->ruleId);
    }

    public function testRuntimeAboveTheConstraintWarns(): void
    {
        // ^8.4 stops before 9.0.
        $finding = $this->runtimeFinding('pass-app', '9.0.1');

        self::assertSame(Finding::STATUS_WARNING, $finding->status);
        self::assertSame('proctor/php-floor/runtime-mismatch', $finding->ruleId);
        self::assertSame(
            'php runtime 9.0.1 is above require.php "^8.4": the app was never tested there; widen the constraint or serve it on a PHP it allows',
            $finding->message,
        );
    }

    public function testEndOfLifeRuntimeWarnsBecauseTheFloorAlreadyFails(): void
    {
        // eol-app declares ^7.4: the floor fails, and the 7.4 runtime warns
        // instead of adding a second blocker for the same cause.
        $findings = $this->findings('eol-app', '7.4.33');

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame(Finding::STATUS_WARNING, $findings[1]->status);
        self::assertSame('proctor/php-floor/runtime', $findings[1]->ruleId);
        self::assertSame(State::EolGraceExpired, $findings[1]->state);
        self::assertSame(
            'php runtime 7.4.33 is eol_grace_expired; move the app to a supported PHP',
            $findings[1]->message,
        );
    }

    public function testRuntimeOnALineThePolicyDoesNotListWarns(): void
    {
        // eol-app declares ^7.4, which allows 7.9: no policy line lists it.
        $finding = $this->runtimeFinding('eol-app', '7.9.1');

        self::assertSame(Finding::STATUS_WARNING, $finding->status);
        self::assertSame('proctor/php-floor/runtime', $finding->ruleId);
        self::assertSame(
            'php runtime 7.9.1: the policy does not list php 7.9, so its support cannot be graded',
            $finding->message,
        );
    }

    public function testTheGateBlocksARuntimeBelowTheConstraint(): void
    {
        $result = $this->runGate([
            '--path=' . $this->app('pass-app'),
            '--policy=' . __DIR__ . '/fixtures/policy.json',
            '--today=' . self::TODAY,
            '--php-runtime=8.2.29',
        ]);

        self::assertSame(1, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('php runtime 8.2.29 is below require.php "^8.4"', $result['stdout']);
    }

    public function testTheJsonReportCarriesTheRuntimeRule(): void
    {
        $result = $this->runProctor($this->app('pass-app'), 'json', null, '8.4.14');
        $report = json_decode($result['stdout'], true);
        self::assertIsArray($report);

        $runtime = array_values(array_filter(
            $report['findings'],
            static fn(array $f): bool => ($f['rule_id'] ?? null) === 'proctor/php-floor/runtime',
        ));
        self::assertCount(1, $runtime, $result['stdout']);
        self::assertSame('pass', $runtime[0]['status']);
    }
}
