<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\ComposerAuditCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\ProcessResult;
use Waynestate\Proctor\Check\ProcessRunnerInterface;
use Waynestate\Proctor\Creed\Policy;

/**
 * Tests the ComposerAuditCheck with a stub ProcessRunner, so advisory
 * naming, thresholds, ignore config and abandoned handling are verified
 * without running composer.
 *
 * The advisory JSON is a trimmed capture of real
 * "composer audit --format=json" output for laravel/framework 9.0.0, kept
 * in tests/fixtures/composer-audit-output.json.
 */
final class ComposerAuditCheckTest extends TestCase
{
    /**
     * The three messages the captured audit produces on the fixture apps,
     * in capture order: the high advisory, the medium one, the one with a
     * null severity.
     *
     * @var list<string>
     */
    private const MESSAGES = [
        'laravel/framework 9.0.0: CVE-2024-52301 Laravel environment manipulation via query string (high)',
        'laravel/framework 9.0.0: PKSA-m5cs-t1y6-qpcs Laravel Framework: Temporary Signed URL Path Confusion (medium)',
        'laravel/framework 9.0.0: CVE-2026-48019 Laravel CRLF injection in default email rule (unknown)',
    ];

    private string $originalPath = '';

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);
    }

    public function testAdvisoryFindingsNamePackageVersionIdTitleAndSeverity(): void
    {
        $findings = $this->runAudit($this->app('audit-app'), $this->capturedAudit());

        self::assertCount(3, $findings);
        $this->assertFindings($findings, [
            Finding::STATUS_FAIL,
            Finding::STATUS_WARNING,
            Finding::STATUS_FAIL,
        ]);

        [$high, $medium, $nullSeverity] = $findings;

        self::assertSame('proctor/composer-audit/advisory', $high->ruleId);
        self::assertSame('composer.lock', $high->path);
        self::assertSame(1, $high->line);
        self::assertSame([
            [
                'id' => 'CVE-2024-52301',
                'cve' => 'CVE-2024-52301',
                'title' => 'Laravel environment manipulation via query string',
                'link' => 'https://github.com/advisories/GHSA-gv7v-rgg6-548h',
                'severity' => 'high',
            ],
        ], $high->advisories);

        // Without a CVE the finding shows the advisory id instead.
        self::assertSame('PKSA-m5cs-t1y6-qpcs', $medium->advisories[0]['id']);
        self::assertNull($medium->advisories[0]['cve']);
        self::assertSame('medium', $medium->advisories[0]['severity']);

        // A null severity fails safe and prints as "unknown".
        self::assertNull($nullSeverity->advisories[0]['severity']);
    }

    public function testLegacyIgnoreListEntryDowngradesTheAdvisoryToAWarning(): void
    {
        // config.audit.ignore lists the high advisory's CVE, so only that
        // finding is downgraded.
        $findings = $this->runAudit($this->app('audit-app-ignore-list'), $this->capturedAudit());

        $this->assertFindings($findings, [
            Finding::STATUS_WARNING,
            Finding::STATUS_WARNING,
            Finding::STATUS_FAIL,
        ]);
    }

    public function testLegacyIgnorePackageNameDowngradesEveryAdvisoryToAWarning(): void
    {
        // config.audit.ignore names the package, so every advisory of it is
        // downgraded.
        $findings = $this->runAudit($this->app('audit-app-ignore-package'), $this->capturedAudit());

        $this->assertFindings($findings, [
            Finding::STATUS_WARNING,
            Finding::STATUS_WARNING,
            Finding::STATUS_WARNING,
        ]);
    }

    public function testLegacyIgnoreMapEntryDowngradesTheAdvisoryToAWarning(): void
    {
        // config.audit.ignore as a map keys the medium advisory by its
        // advisory id, so only that finding is downgraded.
        $findings = $this->runAudit($this->app('audit-app-ignore-map'), $this->capturedAudit());

        $this->assertFindings($findings, [
            Finding::STATUS_FAIL,
            Finding::STATUS_WARNING,
            Finding::STATUS_FAIL,
        ]);
    }

    public function testPolicyIgnoreIdDowngradesTheAdvisoryToAWarning(): void
    {
        // config.policy.advisories.ignore-id keys the null-severity advisory
        // by its advisory id, so only that finding is downgraded.
        $findings = $this->runAudit($this->app('audit-app-policy-ignore-id'), $this->capturedAudit());

        $this->assertFindings($findings, [
            Finding::STATUS_FAIL,
            Finding::STATUS_WARNING,
            Finding::STATUS_WARNING,
        ]);
    }

    public function testPolicyIgnorePackageDowngradesEveryAdvisoryToAWarning(): void
    {
        // config.policy.advisories.ignore names the package, so every
        // advisory of it is downgraded.
        $findings = $this->runAudit($this->app('audit-app-policy-ignore-package'), $this->capturedAudit());

        $this->assertFindings($findings, [
            Finding::STATUS_WARNING,
            Finding::STATUS_WARNING,
            Finding::STATUS_WARNING,
        ]);
    }

    public function testPolicyIgnoreEntryWithOnAuditFalseDoesNotDowngradeTheAdvisories(): void
    {
        // Booster's temporary unblock scopes itself to blocking only: with
        // on-audit false composer still reports the advisories, so PROCTOR
        // keeps its verdicts instead of hiding them.
        $findings = $this->runAudit($this->app('audit-app-policy-ignore-on-audit-false'), $this->capturedAudit());

        $this->assertFindings($findings, [
            Finding::STATUS_FAIL,
            Finding::STATUS_WARNING,
            Finding::STATUS_FAIL,
        ]);
    }

    public function testPolicyIgnoreEntryWithMatchingConstraintDowngradesEveryAdvisoryToAWarning(): void
    {
        // on-audit true and the locked 9.0.0 satisfies ^9.0, so the rule
        // applies and every advisory of the package is downgraded.
        $findings = $this->runAudit($this->app('audit-app-policy-ignore-constraint-match'), $this->capturedAudit());

        $this->assertFindings($findings, [
            Finding::STATUS_WARNING,
            Finding::STATUS_WARNING,
            Finding::STATUS_WARNING,
        ]);
    }

    public function testPolicyIgnoreEntryWithUnmatchedConstraintDoesNotDowngradeTheAdvisories(): void
    {
        // on-audit true, but the locked 9.0.0 does not satisfy ^10.0, so
        // the rule does not reach this app.
        $findings = $this->runAudit($this->app('audit-app-policy-ignore-constraint-mismatch'), $this->capturedAudit());

        $this->assertFindings($findings, [
            Finding::STATUS_FAIL,
            Finding::STATUS_WARNING,
            Finding::STATUS_FAIL,
        ]);
    }

    public function testPolicyIgnoreIdEntryWithOnAuditFalseDoesNotDowngradeTheAdvisory(): void
    {
        // The ignore-id rule scopes itself to blocking only, so the
        // null-severity advisory keeps its fail-safe fail.
        $findings = $this->runAudit($this->app('audit-app-policy-ignore-id-on-audit-false'), $this->capturedAudit());

        $this->assertFindings($findings, [
            Finding::STATUS_FAIL,
            Finding::STATUS_WARNING,
            Finding::STATUS_FAIL,
        ]);
    }

    public function testPolicyIgnoreEntryWithSeveralRulesIgnoresWhenOneApplies(): void
    {
        // One rule misses the locked version and the next one matches, so
        // the package is ignored.
        $findings = $this->runAudit($this->app('audit-app-policy-ignore-multi-rule'), $this->capturedAudit());

        $this->assertFindings($findings, [
            Finding::STATUS_WARNING,
            Finding::STATUS_WARNING,
            Finding::STATUS_WARNING,
        ]);
    }

    public function testAdvisoriesComposerAlreadyMovedToIgnoredAreWarnings(): void
    {
        // Whatever the app's own config says, an advisory in composer's
        // ignored-advisories section is suppressed by composer itself, so
        // it stays visible as a warning.
        $json = json_encode([
            'advisories' => [],
            'ignored-advisories' => [
                'laravel/framework' => [
                    [
                        'advisoryId' => 'PKSA-w7xr-vk7n-rstm',
                        'packageName' => 'laravel/framework',
                        'affectedVersions' => '>=9.0.0,<9.52.17',
                        'title' => 'Laravel environment manipulation via query string',
                        'cve' => 'CVE-2024-52301',
                        'link' => 'https://github.com/advisories/GHSA-gv7v-rgg6-548h',
                        'reportedAt' => '2024-11-12T15:29:00+00:00',
                        'sources' => [
                            [
                                'name' => 'GitHub',
                                'remoteId' => 'GHSA-gv7v-rgg6-548h',
                            ],
                        ],
                        'severity' => 'high',
                        'ignoreReason' => 'accepted risk',
                    ],
                ],
            ],
            'abandoned' => [],
        ]);

        $findings = $this->runAudit($this->app('audit-app'), (string) $json);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame(self::MESSAGES[0], $findings[0]->message);
    }

    public function testAbandonedPackagesAreWarnings(): void
    {
        $json = json_encode([
            'advisories' => [],
            'abandoned' => [
                'vendor/abandoned' => true,
                'vendor/abandoned-replaced' => 'vendor/replacement',
            ],
        ]);

        $findings = $this->runAudit($this->app('audit-app'), (string) $json);

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('vendor/abandoned is abandoned', $findings[0]->message);
        self::assertSame(Finding::STATUS_WARNING, $findings[1]->status);
        self::assertSame('vendor/abandoned-replaced is abandoned (use vendor/replacement instead)', $findings[1]->message);
    }

    public function testCleanAuditPasses(): void
    {
        $json = json_encode(['advisories' => [], 'abandoned' => []]);

        $findings = $this->runAudit($this->app('audit-app'), (string) $json);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no advisories found', $findings[0]->message);
        self::assertSame('composer.lock', $findings[0]->path);
    }

    /**
     * Runs the check on a fixture app with the given audit JSON and returns
     * its findings.
     *
     * @return list<Finding>
     */
    private function runAudit(string $app, string $json): array
    {
        $this->putFakeComposerOnPath();

        $check = new ComposerAuditCheck(new class ($json) implements ProcessRunnerInterface {
            public function __construct(private readonly string $json) {}

            public function run(array $command, string $cwd, int $timeout = 300): ProcessResult
            {
                return new ProcessResult(1, $this->json, '');
            }
        });

        $context = new CheckContext(
            $app,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
        );

        return $check->run($context);
    }

    /**
     * Asserts the findings carry exactly the captured messages, in capture
     * order, with the given statuses.
     *
     * @param list<Finding> $findings
     * @param list<string>  $statuses
     */
    private function assertFindings(array $findings, array $statuses): void
    {
        self::assertCount(count($statuses), $findings);
        foreach ($findings as $index => $finding) {
            self::assertSame(self::MESSAGES[$index], $finding->message, sprintf('finding %d message', $index + 1));
            self::assertSame($statuses[$index], $finding->status, sprintf('finding %d status', $index + 1));
        }
    }

    /**
     * The captured audit output the fixture apps are built around.
     */
    private function capturedAudit(): string
    {
        $json = file_get_contents(__DIR__ . '/fixtures/composer-audit-output.json');
        self::assertIsString($json);
        self::assertNotSame('', $json);

        return $json;
    }

    /**
     * The absolute path of a fixture app.
     */
    private function app(string $name): string
    {
        return __DIR__ . '/fixtures/apps/' . $name;
    }

    /**
     * Puts a fake composer executable on PATH so findComposer() succeeds.
     */
    private function putFakeComposerOnPath(): void
    {
        $dir = sys_get_temp_dir() . '/proctor-fake-composer';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $composer = $dir . '/composer';
        file_put_contents($composer, "#!/bin/sh\nexit 0\n");
        chmod($composer, 0755);
        putenv('PATH=' . $dir . PATH_SEPARATOR . $this->originalPath);
    }
}
