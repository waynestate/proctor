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

    public function testPolicyIgnoreWildcardPackageDowngradesEveryAdvisoryToAWarning(): void
    {
        // The rule laravel/* matches laravel/framework the way composer
        // does, so every advisory of the package is downgraded.
        $findings = $this->runAudit($this->app('audit-app-policy-ignore-wildcard'), $this->capturedAudit());

        $this->assertFindings($findings, [
            Finding::STATUS_WARNING,
            Finding::STATUS_WARNING,
            Finding::STATUS_WARNING,
        ]);
    }

    public function testPolicyIgnoreWildcardPackageThatMissesKeepsTheVerdicts(): void
    {
        // The rule symfony/* matches nothing in the captured audit, so the
        // check keeps its no-ignore verdicts.
        $findings = $this->runAudit($this->app('audit-app-policy-ignore-wildcard-miss'), $this->capturedAudit());

        $this->assertFindings($findings, [
            Finding::STATUS_FAIL,
            Finding::STATUS_WARNING,
            Finding::STATUS_FAIL,
        ]);
    }

    public function testPolicyIgnoreWildcardEntryWithUnmatchedConstraintDoesNotDowngradeTheAdvisories(): void
    {
        // on-audit true and laravel/* matches, but the locked 9.0.0 does not
        // satisfy ^10.0, so the rule does not reach this app.
        $findings = $this->runAudit($this->app('audit-app-policy-ignore-wildcard-constraint'), $this->capturedAudit());

        $this->assertFindings($findings, [
            Finding::STATUS_FAIL,
            Finding::STATUS_WARNING,
            Finding::STATUS_FAIL,
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

    public function testIgnoreEntriesInComposerJsonAreReportedAsOneWarningWithoutNamingThem(): void
    {
        // A temporary app whose composer.json keeps two legacy
        // config.audit.ignore entries: the check reports one warning that
        // counts them and names none of them, even when no advisory
        // matched.
        $app = sys_get_temp_dir() . '/proctor-ignore-entries-app';
        if (!is_dir($app)) {
            mkdir($app, 0755, true);
        }
        file_put_contents($app . '/composer.json', (string) json_encode([
            'name' => 'vendor/temp',
            'config' => [
                'audit' => [
                    'ignore' => ['CVE-1999-0001', 'GHSA-0000-aaaa-bbbb'],
                ],
            ],
        ]));
        file_put_contents($app . '/composer.lock', '{}');

        $json = json_encode(['advisories' => [], 'abandoned' => []]);
        $findings = $this->runAudit($app, (string) $json, 0, true);

        $entries = array_values(array_filter(
            $findings,
            static fn(Finding $finding): bool => $finding->ruleId === 'proctor/composer-audit/ignore-entries',
        ));

        self::assertCount(1, $entries);
        self::assertSame(Finding::STATUS_WARNING, $entries[0]->status);
        self::assertSame('composer.json', $entries[0]->path);
        self::assertSame(1, $entries[0]->line);
        self::assertStringContainsString('2 ignore entries', $entries[0]->message);
        self::assertStringNotContainsString('CVE-1999-0001', $entries[0]->message);
        self::assertStringNotContainsString('GHSA-0000-aaaa-bbbb', $entries[0]->message);
    }

    public function testNoIgnoreEntriesGivesNoIgnoreEntriesFinding(): void
    {
        // The clean fixture app keeps no ignore entries, so the count
        // warning stays away.
        $json = json_encode(['advisories' => [], 'abandoned' => []]);

        $findings = $this->runAudit($this->app('audit-app'), (string) $json, 0, true);

        foreach ($findings as $finding) {
            self::assertNotSame('proctor/composer-audit/ignore-entries', $finding->ruleId);
        }
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

        $findings = $this->runAudit($this->app('audit-app'), (string) $json, 0);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no advisories found', $findings[0]->message);
        self::assertSame('composer.lock', $findings[0]->path);
    }

    public function testNonZeroExitWithNothingReadableFails(): void
    {
        $json = json_encode(['advisories' => [], 'abandoned' => []]);

        $findings = $this->runAudit($this->app('audit-app'), (string) $json, 1);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertStringStartsWith(
            'composer audit exited 1 but listed nothing PROCTOR can read',
            $findings[0]->message,
        );
        self::assertSame('proctor/composer-audit/exit-code', $findings[0]->ruleId);
        self::assertSame('composer.lock', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    public function testUnparseableAuditOutputFailsAsNotAudited(): void
    {
        // The audit runs but prints nothing parseable, so the check fails
        // with a message that starts with "not audited:" and carries the
        // first stderr line.
        $findings = $this->runAudit($this->app('audit-app'), 'not json', 2, false, 'boom');

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('proctor/composer-audit/could-not-run', $findings[0]->ruleId);
        self::assertStringStartsWith('not audited:', $findings[0]->message);
        self::assertStringContainsString('boom', $findings[0]->message);
        self::assertSame('composer.lock', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    public function testEmptyOutputGivesNoErrorOutputText(): void
    {
        // With no stderr at all the message falls back to "no error
        // output" instead of the error line.
        $findings = $this->runAudit($this->app('audit-app'), '', 2);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame(
            'not audited: composer audit could not run: no error output',
            $findings[0]->message,
        );
    }

    public function testAuditRunsComposerWithPluginsOffAndAMinimalEnvironment(): void
    {
        $captured = new class implements ProcessRunnerInterface {
            /** @var list<string>|null */
            public ?array $command = null;
            public bool $envPassed = false;
            /** @var array<string, string>|null */
            public ?array $env = null;

            public function run(array $command, string $cwd, int $timeout = 300, ?array $env = null): ProcessResult
            {
                $this->command = $command;
                $this->envPassed = $env !== null;
                $this->env = $env;

                return new ProcessResult(0, '{"advisories": [], "abandoned": []}', '');
            }
        };

        // Composer switches from the job around the check must not reach the audit.
        putenv('COMPOSER_AUTH={"http-basic": {"repo.example.com": {"username": "u", "password": "p"}}}');
        putenv('COMPOSER_NO_AUDIT=1');

        try {
            $this->putFakeComposerOnPath();

            $check = new ComposerAuditCheck($captured);
            $context = new CheckContext(
                $this->app('audit-app'),
                Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
                new DateTimeImmutable('2026-10-27'),
            );
            $check->run($context);
        } finally {
            putenv('COMPOSER_AUTH');
            putenv('COMPOSER_NO_AUDIT');
        }

        self::assertIsArray($captured->command);
        $command = $captured->command;
        self::assertSame('audit', $command[1]);
        foreach (['--format=json', '--locked', '--no-plugins', '--no-interaction'] as $flag) {
            self::assertContains($flag, $command);
        }

        self::assertTrue($captured->envPassed, 'the audit must run with an explicit environment');
        self::assertIsArray($captured->env);
        $env = $captured->env;

        self::assertArrayHasKey('PATH', $env);
        self::assertNotSame('', $env['PATH']);
        self::assertArrayNotHasKey('COMPOSER_AUTH', $env);
        self::assertArrayNotHasKey('COMPOSER_NO_AUDIT', $env);

        $allowed = ['PATH', 'HOME', 'APPDATA', 'USERPROFILE', 'COMPOSER_HOME', 'TEMP', 'TMP', 'SystemRoot'];
        foreach (array_keys($env) as $key) {
            self::assertContains($key, $allowed, sprintf('unexpected environment variable %s in the audit environment', $key));
        }
    }

    /**
     * Runs the check on a fixture app with the given audit JSON and returns
     * its findings. Unless $keepIgnoreEntries is set, the
     * proctor/composer-audit/ignore-entries warning is dropped first, so
     * the existing tests assert the same findings they did before the
     * warning existed.
     *
     * @return list<Finding>
     */
    private function runAudit(string $app, string $json, int $exitCode = 1, bool $keepIgnoreEntries = false, string $stderr = ''): array
    {
        $this->putFakeComposerOnPath();

        $check = new ComposerAuditCheck(new class ($json, $exitCode, $stderr) implements ProcessRunnerInterface {
            public function __construct(
                private readonly string $json,
                private readonly int $exitCode,
                private readonly string $stderr,
            ) {}

            public function run(array $command, string $cwd, int $timeout = 300, ?array $env = null): ProcessResult
            {
                return new ProcessResult($this->exitCode, $this->json, $this->stderr);
            }
        });

        $context = new CheckContext(
            $app,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
        );

        $findings = $check->run($context);
        if (!$keepIgnoreEntries) {
            $findings = array_values(array_filter(
                $findings,
                static fn(Finding $finding): bool => $finding->ruleId !== 'proctor/composer-audit/ignore-entries',
            ));
        }

        return $findings;
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
