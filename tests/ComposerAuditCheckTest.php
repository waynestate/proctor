<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\ComposerAuditCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\OverrideApplier;
use Waynestate\Proctor\Check\ProcessResult;
use Waynestate\Proctor\Check\ProcessRunnerInterface;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\Override\OverrideFile;
use Waynestate\Proctor\ToolError;

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
                'package' => 'laravel/framework',
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

    public function testSeverityAndAbandonedIgnoresAreCountedInTheIgnoreEntriesWarning(): void
    {
        // A temporary app whose composer.json keeps two
        // config.policy.advisories.ignore-severity entries and one
        // config.policy.abandoned.ignore entry: the check counts all three
        // in one warning that names none of them.
        $app = sys_get_temp_dir() . '/proctor-ignore-severity-app-' . uniqid();
        mkdir($app, 0755, true);
        file_put_contents($app . '/composer.json', (string) json_encode([
            'name' => 'vendor/temp',
            'config' => [
                'policy' => [
                    'advisories' => [
                        'ignore-severity' => ['low', 'medium'],
                    ],
                    'abandoned' => [
                        'ignore' => [
                            'vendor/old-package' => 'replaced by vendor/new-package',
                        ],
                    ],
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
        self::assertStringContainsString('3 ignore entries', $entries[0]->message);
        self::assertStringNotContainsString('vendor/old-package', $entries[0]->message);
    }

    public function testLegacyAuditSeverityAndAbandonedIgnoresAreCounted(): void
    {
        // A temporary app with no config.policy block keeps one legacy
        // config.audit.ignore-severity entry and two legacy
        // config.audit.ignore-abandoned entries: the check counts all
        // three in one warning that names none of them.
        $app = sys_get_temp_dir() . '/proctor-audit-fallback-app-' . uniqid();
        mkdir($app, 0755, true);
        file_put_contents($app . '/composer.json', (string) json_encode([
            'name' => 'vendor/temp',
            'config' => [
                'audit' => [
                    'ignore-severity' => ['low'],
                    'ignore-abandoned' => [
                        'vendor/old-one' => 'replaced by vendor/new-one',
                        'vendor/old-two' => null,
                    ],
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
        self::assertStringContainsString('3 ignore entries', $entries[0]->message);
        self::assertStringNotContainsString('vendor/old-one', $entries[0]->message);
        self::assertStringNotContainsString('vendor/old-two', $entries[0]->message);
    }

    public function testAuditFallbacksAreIgnoredWhenThePolicyBlockExists(): void
    {
        // Composer ignores all audit.* keys of a family once the matching
        // config.policy block exists, so the five legacy
        // config.audit.ignore-severity entries are not counted next to the
        // one config.policy.advisories.ignore-severity entry.
        $app = sys_get_temp_dir() . '/proctor-policy-wins-app-' . uniqid();
        mkdir($app, 0755, true);
        file_put_contents($app . '/composer.json', (string) json_encode([
            'name' => 'vendor/temp',
            'config' => [
                'policy' => [
                    'advisories' => [
                        'ignore-severity' => ['critical'],
                    ],
                ],
                'audit' => [
                    'ignore-severity' => ['low', 'medium', 'high', 'critical', 'unknown'],
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
        self::assertStringContainsString('1 ignore entry', $entries[0]->message);
        self::assertStringNotContainsString('critical', $entries[0]->message);
    }

    public function testTmpEdgeCasesForIgnoreEntryCounting(): void
    {
        // Fallbacks are gated per family: with config.policy.advisories
        // present the legacy config.audit.ignore-severity is not counted,
        // but config.audit.ignore-abandoned still is because there is no
        // config.policy.abandoned block.
        $app = sys_get_temp_dir() . '/proctor-edge-mixed-app-' . uniqid();
        mkdir($app, 0755, true);
        file_put_contents($app . '/composer.json', (string) json_encode([
            'name' => 'vendor/temp',
            'config' => [
                'policy' => [
                    'advisories' => [
                        'ignore-severity' => ['low'],
                    ],
                ],
                'audit' => [
                    'ignore-severity' => ['low', 'medium', 'high', 'critical', 'unknown'],
                    'ignore-abandoned' => [
                        'vendor/old-one' => 'replaced by vendor/new-one',
                    ],
                ],
            ],
        ]));
        file_put_contents($app . '/composer.lock', '{}');

        $findings = $this->runAudit($app, (string) json_encode(['advisories' => [], 'abandoned' => []]), 0, true);
        $entries = array_values(array_filter(
            $findings,
            static fn(Finding $finding): bool => $finding->ruleId === 'proctor/composer-audit/ignore-entries',
        ));
        self::assertCount(1, $entries);
        self::assertStringContainsString('2 ignore entries', $entries[0]->message);

        // The map shape of ignore-severity and the list shape of
        // abandoned.ignore both count their entries.
        $app = sys_get_temp_dir() . '/proctor-edge-map-app-' . uniqid();
        mkdir($app, 0755, true);
        file_put_contents($app . '/composer.json', (string) json_encode([
            'name' => 'vendor/temp',
            'config' => [
                'policy' => [
                    'advisories' => [
                        'ignore-severity' => [
                            'low' => 'accepted risk',
                            'medium' => null,
                        ],
                    ],
                    'abandoned' => [
                        'ignore' => ['vendor/old-package'],
                    ],
                ],
            ],
        ]));
        file_put_contents($app . '/composer.lock', '{}');

        $findings = $this->runAudit($app, (string) json_encode(['advisories' => [], 'abandoned' => []]), 0, true);
        $entries = array_values(array_filter(
            $findings,
            static fn(Finding $finding): bool => $finding->ruleId === 'proctor/composer-audit/ignore-entries',
        ));
        self::assertCount(1, $entries);
        self::assertStringContainsString('3 ignore entries', $entries[0]->message);

        // Non-array settings are not counted and do not crash the check.
        $app = sys_get_temp_dir() . '/proctor-edge-scalar-app-' . uniqid();
        mkdir($app, 0755, true);
        file_put_contents($app . '/composer.json', (string) json_encode([
            'name' => 'vendor/temp',
            'config' => [
                'policy' => [
                    'advisories' => [
                        'ignore-severity' => 'low',
                    ],
                ],
                'audit' => 'weird',
            ],
        ]));
        file_put_contents($app . '/composer.lock', '{}');

        $findings = $this->runAudit($app, (string) json_encode(['advisories' => [], 'abandoned' => []]), 0, true);
        foreach ($findings as $finding) {
            self::assertNotSame('proctor/composer-audit/ignore-entries', $finding->ruleId);
        }
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

    public function testAbandonedPackageWithAReplacementFailsUnderTheFailPolicy(): void
    {
        // The fixture policy carries no abandoned_packages rule, so it is
        // fail, composer's own default.
        $json = json_encode(['advisories' => [], 'abandoned' => ['vendor/old' => 'vendor/new']]);

        $findings = $this->runAudit($this->app('audit-app'), (string) $json);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('proctor/composer-audit/abandoned', $findings[0]->ruleId);
        self::assertSame('vendor/old is abandoned; use vendor/new instead', $findings[0]->message);
        self::assertSame('composer.lock', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame([['package' => 'vendor/old', 'replacement' => 'vendor/new']], $findings[0]->advisories);
    }

    public function testAbandonedPackageWithNoReplacementFailsUnderTheFailPolicy(): void
    {
        $json = json_encode(['advisories' => [], 'abandoned' => ['vendor/old' => null]]);

        $findings = $this->runAudit($this->app('audit-app'), (string) $json, 1, false, '', $this->policyWith('fail'));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('proctor/composer-audit/abandoned', $findings[0]->ruleId);
        self::assertSame('vendor/old is abandoned with no named replacement', $findings[0]->message);
        self::assertSame([['package' => 'vendor/old', 'replacement' => null]], $findings[0]->advisories);
    }

    public function testAbandonedPackageWithAValueThatIsNotANameReadsAsNoNamedReplacement(): void
    {
        // Composer 2.10 writes a package name or null, but an older or odd
        // shape (true, as composer.lock records it, or an empty string)
        // still names an abandoned package, so it is graded instead of
        // dropped.
        $json = json_encode(['advisories' => [], 'abandoned' => ['vendor/old' => true, 'vendor/older' => '']]);

        $findings = $this->runAudit($this->app('audit-app'), (string) $json);

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('vendor/old is abandoned with no named replacement', $findings[0]->message);
        self::assertSame(Finding::STATUS_FAIL, $findings[1]->status);
        self::assertSame('vendor/older is abandoned with no named replacement', $findings[1]->message);
    }

    public function testAbandonedPackagesAreWarningsUnderTheReportPolicy(): void
    {
        $json = json_encode([
            'advisories' => [],
            'abandoned' => [
                'vendor/old' => 'vendor/new',
                'vendor/older' => null,
            ],
        ]);

        $findings = $this->runAudit($this->app('audit-app'), (string) $json, 0, false, '', $this->policyWith('report'));

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/composer-audit/abandoned', $findings[0]->ruleId);
        self::assertSame('vendor/old is abandoned; use vendor/new instead', $findings[0]->message);
        self::assertSame(Finding::STATUS_WARNING, $findings[1]->status);
        self::assertSame('vendor/older is abandoned with no named replacement', $findings[1]->message);
    }

    public function testAbandonedPackagesGiveNoFindingUnderTheIgnorePolicy(): void
    {
        $json = json_encode(['advisories' => [], 'abandoned' => ['vendor/old' => 'vendor/new']]);

        $findings = $this->runAudit($this->app('audit-app'), (string) $json, 0, false, '', $this->policyWith('ignore'));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no advisories found', $findings[0]->message);
    }

    public function testAbandonedOnlyNonZeroExitRaisesNoExitCodeFinding(): void
    {
        // Under fail composer exits 1 for an abandoned package alone; the
        // abandoned list explains that exit, so the exit-code fail stays
        // away.
        $json = json_encode(['advisories' => [], 'abandoned' => ['vendor/old' => null]]);

        $findings = $this->runAudit($this->app('audit-app'), (string) $json, 1);

        self::assertCount(1, $findings);
        self::assertSame('proctor/composer-audit/abandoned', $findings[0]->ruleId);
        foreach ($findings as $finding) {
            self::assertNotSame('proctor/composer-audit/exit-code', $finding->ruleId);
        }
    }

    public function testNonZeroExitUnderTheIgnorePolicyIsNotExplainedByAbandonedPackages(): void
    {
        // Under ignore composer never fails on an abandoned package, so a
        // non-zero exit came from something PROCTOR cannot read.
        $json = json_encode(['advisories' => [], 'abandoned' => ['vendor/old' => null]]);

        $findings = $this->runAudit($this->app('audit-app'), (string) $json, 1, false, '', $this->policyWith('ignore'));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('proctor/composer-audit/exit-code', $findings[0]->ruleId);
    }

    public function testPolicyAbandonedIgnoreEntryDowngradesTheFailToAWarning(): void
    {
        // config.policy.abandoned.ignore names the package with a star, so
        // the abandoned fail becomes a warning, the same as an ignored
        // advisory; the entry itself still needs a dated override.
        $app = $this->tempApp('proctor-abandoned-ignore-app-', [
            'policy' => ['abandoned' => ['ignore' => ['vendor/old*' => 'moving off it this term']]],
        ]);
        $json = json_encode(['advisories' => [], 'abandoned' => ['vendor/old-package' => null, 'vendor/other' => null]]);

        $findings = $this->abandonedFindings($this->runAudit($app, (string) $json));

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('vendor/old-package is abandoned with no named replacement', $findings[0]->message);
        self::assertSame(Finding::STATUS_FAIL, $findings[1]->status);
    }

    public function testLegacyIgnoreAbandonedEntryDowngradesTheFailToAWarning(): void
    {
        $app = $this->tempApp('proctor-abandoned-legacy-app-', [
            'audit' => ['ignore-abandoned' => ['vendor/old']],
        ]);
        $json = json_encode(['advisories' => [], 'abandoned' => ['vendor/old' => 'vendor/new']]);

        $findings = $this->abandonedFindings($this->runAudit($app, (string) $json));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
    }

    public function testAbandonedIgnoreEntryWithOnAuditFalseKeepsTheFail(): void
    {
        $app = $this->tempApp('proctor-abandoned-on-audit-app-', [
            'policy' => ['abandoned' => ['ignore' => ['vendor/old' => ['reason' => 'block only', 'on-audit' => false]]]],
        ]);
        $json = json_encode(['advisories' => [], 'abandoned' => ['vendor/old' => null]]);

        $findings = $this->abandonedFindings($this->runAudit($app, (string) $json));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
    }

    public function testPackageScopedOverrideCoversTheAbandonedFail(): void
    {
        // The finding carries the package, so an override scoped to it
        // downgrades the fail and one scoped to another package does not.
        $json = json_encode(['advisories' => [], 'abandoned' => ['vendor/old' => 'vendor/new']]);
        $findings = $this->runAudit($this->app('audit-app'), (string) $json);
        $today = new DateTimeImmutable('2026-10-27');
        $expires = new DateTimeImmutable('2026-12-31');

        $covered = (new OverrideApplier(
            [new Override('composer-audit', 'replacing it this term', $expires, 'reviewer', null, 'vendor/old')],
            $today,
        ))->apply($findings);
        $missed = (new OverrideApplier(
            [new Override('composer-audit', 'another package', $expires, 'reviewer', null, 'vendor/other')],
            $today,
        ))->apply($findings);

        self::assertSame(Finding::STATUS_WARNING, $covered[0]->status);
        self::assertSame('vendor/old is abandoned; use vendor/new instead (overridden: replacing it this term)', $covered[0]->message);
        self::assertSame(Finding::STATUS_FAIL, $missed[0]->status);
    }

    public function testUnknownAbandonedPackagesRuleIsAToolError(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('abandoned_packages');

        $this->runAudit($this->app('audit-app'), '{"advisories": [], "abandoned": []}', 0, false, '', $this->policyWith('sometimes'));
    }

    public function testAuditPassesThePolicyAbandonedRuleToComposer(): void
    {
        foreach (['ignore', 'report', 'fail'] as $value) {
            $captured = new class implements ProcessRunnerInterface {
                /** @var list<string>|null */
                public ?array $command = null;

                public function run(array $command, string $cwd, int $timeout = 300, ?array $env = null): ProcessResult
                {
                    $this->command = $command;

                    return new ProcessResult(0, '{"advisories": [], "abandoned": []}', '');
                }
            };
            $this->putFakeComposerOnPath();

            (new ComposerAuditCheck($captured))->run(new CheckContext(
                $this->app('audit-app'),
                $this->policyWith($value),
                new DateTimeImmutable('2026-10-27'),
            ));

            self::assertIsArray($captured->command);
            self::assertContains('--abandoned=' . $value, $captured->command);
            self::assertCount(1, array_filter(
                $captured->command,
                static fn(string $part): bool => str_starts_with($part, '--abandoned'),
            ));
        }
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

    public function testAuditIsRetriedOnceWhenTheFirstRunPrintsNoJson(): void
    {
        // The first run prints no JSON, so the check runs the same audit
        // once more and carries on with the second result as normal.
        $runner = new class implements ProcessRunnerInterface {
            public int $calls = 0;

            public function run(array $command, string $cwd, int $timeout = 300, ?array $env = null): ProcessResult
            {
                $this->calls++;

                if ($this->calls === 1) {
                    return new ProcessResult(1, 'not json', 'boom');
                }

                return new ProcessResult(0, '{"advisories":[],"abandoned":[]}', '');
            }
        };

        $this->putFakeComposerOnPath();
        $findings = (new ComposerAuditCheck($runner))->run(new CheckContext(
            $this->app('audit-app'),
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
            OverrideFile::load($this->app('audit-app')),
        ));

        self::assertSame(2, $runner->calls);
        foreach ($findings as $finding) {
            self::assertNotSame('proctor/composer-audit/could-not-run', $finding->ruleId);
        }
        $passes = array_values(array_filter(
            $findings,
            static fn(Finding $finding): bool => $finding->status === Finding::STATUS_PASS,
        ));
        self::assertCount(1, $passes);
        self::assertSame('no advisories found', $passes[0]->message);
    }

    public function testAuditRunsAtMostTwice(): void
    {
        // The audit never prints JSON, so the check retries exactly once
        // and then fails as could-not-run; it never loops.
        $runner = new class implements ProcessRunnerInterface {
            public int $calls = 0;

            public function run(array $command, string $cwd, int $timeout = 300, ?array $env = null): ProcessResult
            {
                $this->calls++;

                return new ProcessResult(1, 'not json', 'boom');
            }
        };

        $this->putFakeComposerOnPath();
        $findings = (new ComposerAuditCheck($runner))->run(new CheckContext(
            $this->app('audit-app'),
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
            OverrideFile::load($this->app('audit-app')),
        ));

        self::assertSame(2, $runner->calls);
        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('proctor/composer-audit/could-not-run', $findings[0]->ruleId);
        self::assertStringStartsWith('not audited:', $findings[0]->message);
        self::assertStringContainsString('boom', $findings[0]->message);
    }

    public function testTheRootNoticeIsSkippedInTheMessage(): void
    {
        // Composer's running-as-root notice is noise, so the message
        // carries the first stderr line that is not one of its notices.
        $stderr = "Plugins have been disabled automatically as you are running as root, this may be the cause of the following exception. See also https://getcomposer.org/root\n\n  [RuntimeException]\n  Failed to download advisories\n";
        $runner = new class ($stderr) implements ProcessRunnerInterface {
            public function __construct(private readonly string $stderr) {}

            public function run(array $command, string $cwd, int $timeout = 300, ?array $env = null): ProcessResult
            {
                return new ProcessResult(1, 'not json', $this->stderr);
            }
        };

        $this->putFakeComposerOnPath();
        $findings = (new ComposerAuditCheck($runner))->run(new CheckContext(
            $this->app('audit-app'),
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
            OverrideFile::load($this->app('audit-app')),
        ));

        self::assertCount(1, $findings);
        self::assertSame('proctor/composer-audit/could-not-run', $findings[0]->ruleId);
        self::assertStringContainsString('[RuntimeException]', $findings[0]->message);
        self::assertStringNotContainsString('running as root', $findings[0]->message);
    }

    public function testDevOnlyAdvisoryIsAWarningWithTheDevDependencySuffix(): void
    {
        // laravel/framework is listed only in packages-dev, so every
        // advisory of it warns at any severity and the message ends with
        // the dev-dependency marker.
        $app = sys_get_temp_dir() . '/proctor-dev-only-app-' . uniqid();
        mkdir($app, 0755, true);
        file_put_contents($app . '/composer.json', (string) json_encode([
            'name' => 'vendor/dev-only',
            'require-dev' => ['laravel/framework' => '^9.0'],
        ]));
        file_put_contents($app . '/composer.lock', (string) json_encode([
            'packages' => [],
            'packages-dev' => [
                ['name' => 'laravel/framework', 'version' => '9.0.0'],
            ],
        ]));

        $findings = $this->runAudit($app, $this->capturedAudit());

        self::assertCount(3, $findings);
        foreach ($findings as $index => $finding) {
            self::assertSame(Finding::STATUS_WARNING, $finding->status, sprintf('finding %d status', $index + 1));
            self::assertSame(self::MESSAGES[$index] . ' [dev dependency]', $finding->message, sprintf('finding %d message', $index + 1));
        }
    }

    public function testPackageInBothListsStillFails(): void
    {
        // laravel/framework appears in both packages and packages-dev, so
        // it counts as runtime and the high advisory still fails.
        $app = sys_get_temp_dir() . '/proctor-both-lists-app-' . uniqid();
        mkdir($app, 0755, true);
        file_put_contents($app . '/composer.json', (string) json_encode([
            'name' => 'vendor/both-lists',
            'require' => ['laravel/framework' => '^9.0'],
            'require-dev' => ['laravel/framework' => '^9.0'],
        ]));
        file_put_contents($app . '/composer.lock', (string) json_encode([
            'packages' => [
                ['name' => 'laravel/framework', 'version' => '9.0.0'],
            ],
            'packages-dev' => [
                ['name' => 'laravel/framework', 'version' => '9.0.0'],
            ],
        ]));

        $findings = $this->runAudit($app, $this->capturedAudit());

        $this->assertFindings($findings, [
            Finding::STATUS_FAIL,
            Finding::STATUS_WARNING,
            Finding::STATUS_FAIL,
        ]);
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
        foreach (['--format=json', '--locked', '--no-plugins', '--no-interaction', '--abandoned=fail'] as $flag) {
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
     * warning existed. The app's policy-overrides.json, when present, is
     * loaded into the context, so an ignore entry an active override backs
     * earns no unbacked-ignore fail. The policy is the fixture policy
     * unless one is given.
     *
     * @return list<Finding>
     */
    private function runAudit(
        string $app,
        string $json,
        int $exitCode = 1,
        bool $keepIgnoreEntries = false,
        string $stderr = '',
        ?Policy $policy = null,
    ): array {
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
            $policy ?? Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
            OverrideFile::load($app),
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
     * The fixture policy with its abandoned_packages rule set to $value.
     */
    private function policyWith(string $value): Policy
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/policy.json'), true);
        self::assertIsArray($data);
        $rules = $data['rules'] ?? [];
        self::assertIsArray($rules);
        $rules['abandoned_packages'] = $value;
        $data['rules'] = $rules;

        return Policy::fromArray($data);
    }

    /**
     * A temporary app whose composer.json carries the given config block,
     * with an empty lock.
     *
     * @param array<string, mixed> $config
     */
    private function tempApp(string $prefix, array $config): string
    {
        $app = sys_get_temp_dir() . '/' . $prefix . uniqid();
        mkdir($app, 0755, true);
        file_put_contents($app . '/composer.json', (string) json_encode(['name' => 'vendor/temp', 'config' => $config]));
        file_put_contents($app . '/composer.lock', '{}');

        return $app;
    }

    /**
     * Only the proctor/composer-audit/abandoned findings, in order.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function abandonedFindings(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn(Finding $finding): bool => $finding->ruleId === 'proctor/composer-audit/abandoned',
        ));
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
