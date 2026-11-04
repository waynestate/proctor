<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\ComposerAuditCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\OverrideApplier;
use Waynestate\Proctor\Check\ProcessResult;
use Waynestate\Proctor\Check\ProcessRunnerInterface;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\Override\OverrideFile;

/**
 * Every ignore entry in the app's composer.json must be backed by an active
 * composer-audit override: an unbacked entry earns a fail of its own (rule
 * proctor/composer-audit/unbacked-ignore), while a backing override is not
 * reported unused. The audit itself runs against a stub that lists nothing,
 * so each finding set holds only the ignore-related findings.
 */
final class IgnoreBackingTest extends ProctorTestCase
{
    private string $originalPath = '';

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);
    }

    public function testUnbackedIdEntryFailsWithTheExactMessage(): void
    {
        $findings = $this->runAudit($this->app('ignore-backing-unbacked-id'));

        // The clean audit passes, then the count warning, then the fail.
        self::assertCount(3, $findings);
        self::assertPassFinding($findings[0]);
        self::assertSame(Finding::STATUS_WARNING, $findings[1]->status);
        self::assertSame('proctor/composer-audit/ignore-entries', $findings[1]->ruleId);

        $fail = $findings[2];
        self::assertSame(Finding::STATUS_FAIL, $fail->status);
        self::assertSame('proctor/composer-audit/unbacked-ignore', $fail->ruleId);
        self::assertSame(
            'ignore entry "CVE-2024-52301" in composer.json has no matching override; record it in policy-overrides.json with a reason and an expiry',
            $fail->message,
        );
        self::assertSame('composer.json', $fail->path);
        self::assertSame(1, $fail->line);
    }

    public function testAdvisoryOverrideBacksTheEntryCaseInsensitively(): void
    {
        // The override names the advisory in lower case; the entry carries
        // the upper-case CVE, so backing must compare case-insensitively.
        $findings = $this->runAudit($this->app('ignore-backing-advisory-backed'));

        self::assertCount(2, $findings);
        self::assertPassFinding($findings[0]);
        self::assertSame('proctor/composer-audit/ignore-entries', $findings[1]->ruleId);
    }

    public function testPackageWildcardOverrideBacksTheEntry(): void
    {
        // The override scopes the wildcard laravel/*; the entry names the
        // concrete package laravel/framework.
        $findings = $this->runAudit($this->app('ignore-backing-package-backed'));

        self::assertCount(2, $findings);
        self::assertPassFinding($findings[0]);
        self::assertSame('proctor/composer-audit/ignore-entries', $findings[1]->ruleId);
    }

    public function testUnscopedOverrideBacksEveryEntry(): void
    {
        // One unscoped override backs both the ignore-id and the ignore
        // package entry, so no fail appears even though two entries exist.
        $findings = $this->runAudit($this->app('ignore-backing-unscoped'));

        self::assertCount(2, $findings);
        self::assertPassFinding($findings[0]);
        self::assertSame(
            'composer.json silences audit results with 2 ignore entries; move them to policy-overrides.json so they expire',
            $findings[1]->message,
        );
    }

    public function testScopedOverrideDoesNotBackASeverityLevel(): void
    {
        // An ignore-severity entry names neither an id nor a package, so a
        // scoped override cannot back it: only an unscoped one can.
        $findings = $this->runAudit($this->app('ignore-backing-severity-scoped'));

        self::assertCount(3, $findings);
        $fail = $findings[2];
        self::assertSame('proctor/composer-audit/unbacked-ignore', $fail->ruleId);
        self::assertSame(
            'ignore entry "low" in composer.json has no matching override; record it in policy-overrides.json with a reason and an expiry',
            $fail->message,
        );
    }

    public function testExpiredOverrideBacksNothing(): void
    {
        $findings = $this->runAudit($this->app('ignore-backing-expired'));

        self::assertCount(3, $findings);
        $fail = $findings[2];
        self::assertSame('proctor/composer-audit/unbacked-ignore', $fail->ruleId);
        self::assertSame(
            'ignore entry "CVE-2024-52301" in composer.json has no matching override; record it in policy-overrides.json with a reason and an expiry',
            $fail->message,
        );
    }

    public function testLegacyListYieldsOneFailPerEntry(): void
    {
        // The legacy config.audit.ignore list splits on the slash: the id
        // and the package each earn their own fail.
        $findings = $this->runAudit($this->app('ignore-backing-legacy-list'));

        self::assertCount(4, $findings);
        self::assertPassFinding($findings[0]);
        self::assertSame(
            'composer.json silences audit results with 2 ignore entries; move them to policy-overrides.json so they expire',
            $findings[1]->message,
        );
        self::assertSame(
            'ignore entry "CVE-2024-52301" in composer.json has no matching override; record it in policy-overrides.json with a reason and an expiry',
            $findings[2]->message,
        );
        self::assertSame(
            'ignore entry "laravel/framework" in composer.json has no matching override; record it in policy-overrides.json with a reason and an expiry',
            $findings[3]->message,
        );
    }

    public function testLegacyMapKeyIsAnIdEntry(): void
    {
        $findings = $this->runAudit($this->app('ignore-backing-legacy-map'));

        self::assertCount(3, $findings);
        self::assertSame(
            'ignore entry "PKSA-m5cs-t1y6-qpcs" in composer.json has no matching override; record it in policy-overrides.json with a reason and an expiry',
            $findings[2]->message,
        );
    }

    public function testBackedOverrideIsNotReportedUnused(): void
    {
        $override = new Override(
            'composer-audit',
            'fixture',
            new DateTimeImmutable('2027-01-01'),
            'tester',
            'CVE-2024-52301',
        );
        $applier = new OverrideApplier(
            [$override],
            new DateTimeImmutable(self::TODAY),
            [['id' => 'CVE-2024-52301', 'package' => null]],
        );

        self::assertSame([], $applier->unusedWarnings([]));
    }

    public function testUnbackedOverrideIsStillReportedUnused(): void
    {
        $override = new Override(
            'composer-audit',
            'fixture',
            new DateTimeImmutable('2027-01-01'),
            'tester',
            'PKSA-other-0000-0000',
        );
        $applier = new OverrideApplier(
            [$override],
            new DateTimeImmutable(self::TODAY),
            [['id' => 'CVE-2024-52301', 'package' => null]],
        );

        $warnings = $applier->unusedWarnings([]);
        self::assertCount(1, $warnings);
        self::assertSame('proctor/overrides/unused', $warnings[0]->ruleId);
    }

    public function testUnscopedOverrideWithoutEntriesIsStillUnused(): void
    {
        // Unscoped backs every entry, but only when entries exist: with an
        // empty composer.json the override still rots.
        $override = new Override(
            'composer-audit',
            'fixture',
            new DateTimeImmutable('2027-01-01'),
            'tester',
        );
        $applier = new OverrideApplier(
            [$override],
            new DateTimeImmutable(self::TODAY),
            [],
        );

        $warnings = $applier->unusedWarnings([]);
        self::assertCount(1, $warnings);
        self::assertSame('proctor/overrides/unused', $warnings[0]->ruleId);
    }

    /**
     * Runs the composer-audit check on the fixture app with a stub audit
     * that exits 0 and lists nothing, loading the app's overrides into the
     * context the way the CLI does.
     *
     * @return list<Finding>
     */
    private function runAudit(string $app): array
    {
        $this->putFakeComposerOnPath();

        $check = new ComposerAuditCheck(new class implements ProcessRunnerInterface {
            public function run(array $command, string $cwd, int $timeout = 300, ?array $env = null): ProcessResult
            {
                return new ProcessResult(0, '{}', '');
            }
        });

        $context = new CheckContext(
            $app,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY),
            OverrideFile::load($app),
        );

        return $check->run($context);
    }

    /**
     * Asserts the finding is the clean-audit pass line the stub produces.
     */
    private function assertPassFinding(Finding $finding): void
    {
        self::assertSame(Finding::STATUS_PASS, $finding->status);
        self::assertSame('proctor/composer-audit', $finding->ruleId);
        self::assertSame('no advisories found', $finding->message);
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
