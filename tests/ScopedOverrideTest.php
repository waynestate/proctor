<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\OverrideApplier;
use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\Override\OverrideFile;
use Waynestate\Proctor\ToolError;

/**
 * Scoped overrides: a composer-audit override may name the one advisory or
 * package it covers (or both) and then downgrades only the failures that
 * match, while an entry with neither field keeps covering every fail of
 * its check, exactly as before.
 */
final class ScopedOverrideTest extends ProctorTestCase
{
    /** @var list<string> */
    private array $tempApps = [];

    protected function tearDown(): void
    {
        foreach ($this->tempApps as $dir) {
            @unlink($dir . '/policy-overrides.json');
            @rmdir($dir);
        }
        $this->tempApps = [];
    }

    public function testAdvisoryScopeDowngradesOnlyTheMatchingFinding(): void
    {
        $applier = $this->applier(new Override(
            'composer-audit',
            'fixture',
            new DateTimeImmutable('2027-01-01'),
            'tester',
            'PKSA-aaaa-bbbb-cccc',
        ));
        $matching = $this->advisoryFail('vendor/a', 'PKSA-aaaa-bbbb-cccc', null);
        $other = $this->advisoryFail('vendor/a', 'PKSA-dddd-eeee-ffff', 'CVE-2026-0001');

        $result = $applier->apply([$matching, $other]);

        self::assertCount(2, $result);
        self::assertSame(Finding::STATUS_WARNING, $result[0]->status);
        self::assertStringEndsWith(' (overridden: fixture)', $result[0]->message);
        // The second advisory was published after the override was written:
        // it stays a fail.
        self::assertSame(Finding::STATUS_FAIL, $result[1]->status);
        // The override really matched a fail, so it is not unused.
        self::assertSame([], $applier->unusedWarnings([$matching, $other]));
    }

    public function testPackageScopeWildcardDowngradesEveryMatchingPackage(): void
    {
        $applier = $this->applier(new Override(
            'composer-audit',
            'fixture',
            new DateTimeImmutable('2027-01-01'),
            'tester',
            null,
            'vendor/*',
        ));
        $first = $this->advisoryFail('vendor/a', 'PKSA-aaaa-bbbb-cccc', null);
        $second = $this->advisoryFail('vendor/b', 'PKSA-dddd-eeee-ffff', null);
        $outside = $this->advisoryFail('other/c', 'PKSA-gggg-hhhh-iiii', null);

        $result = $applier->apply([$first, $second, $outside]);

        self::assertSame(Finding::STATUS_WARNING, $result[0]->status);
        self::assertSame(Finding::STATUS_WARNING, $result[1]->status);
        self::assertSame(Finding::STATUS_FAIL, $result[2]->status);
    }

    public function testBothFieldsMustMatchTheSameAdvisory(): void
    {
        $applier = $this->applier(new Override(
            'composer-audit',
            'fixture',
            new DateTimeImmutable('2027-01-01'),
            'tester',
            'PKSA-aaaa-bbbb-cccc',
            'vendor/a',
        ));
        $both = $this->advisoryFail('vendor/a', 'PKSA-aaaa-bbbb-cccc', null);
        $advisoryOnly = $this->advisoryFail('vendor/b', 'PKSA-aaaa-bbbb-cccc', null);
        $packageOnly = $this->advisoryFail('vendor/a', 'PKSA-dddd-eeee-ffff', null);

        $result = $applier->apply([$both, $advisoryOnly, $packageOnly]);

        self::assertSame(Finding::STATUS_WARNING, $result[0]->status);
        self::assertSame(Finding::STATUS_FAIL, $result[1]->status);
        self::assertSame(Finding::STATUS_FAIL, $result[2]->status);
    }

    public function testMatchingIsCaseInsensitiveAgainstIdCveAndPackage(): void
    {
        // The advisory scope against the advisory id, in another case.
        $byId = $this->applier(new Override(
            'composer-audit',
            'fixture',
            new DateTimeImmutable('2027-01-01'),
            'tester',
            'pksa-aaaa-bbbb-cccc',
        ));
        self::assertSame(
            Finding::STATUS_WARNING,
            $byId->apply([$this->advisoryFail('vendor/a', 'PKSA-AAAA-BBBB-CCCC', null)])[0]->status,
        );

        // The advisory scope against the CVE, in another case.
        $byCve = $this->applier(new Override(
            'composer-audit',
            'fixture',
            new DateTimeImmutable('2027-01-01'),
            'tester',
            'cve-2026-0001',
        ));
        self::assertSame(
            Finding::STATUS_WARNING,
            $byCve->apply([$this->advisoryFail('vendor/a', 'PKSA-aaaa-bbbb-cccc', 'CVE-2026-0001')])[0]->status,
        );

        // The package scope, in another case, with a wildcard.
        $byPackage = $this->applier(new Override(
            'composer-audit',
            'fixture',
            new DateTimeImmutable('2027-01-01'),
            'tester',
            null,
            'Vendor/*',
        ));
        self::assertSame(
            Finding::STATUS_WARNING,
            $byPackage->apply([$this->advisoryFail('vendor/a', 'PKSA-aaaa-bbbb-cccc', null)])[0]->status,
        );
    }

    public function testScopedOverrideOnAnotherCheckIsAToolErrorNamingTheField(): void
    {
        $dir = $this->tempApp([array_merge($this->baseEntry('lock-committed'), ['advisory' => 'CVE-2026-0001'])]);

        $this->assertToolError(
            sprintf('policy-overrides.json in "%s", entry 1: "advisory" is only valid for the composer-audit check.', $dir),
            static fn() => OverrideFile::load($dir),
        );

        $dir = $this->tempApp([array_merge($this->baseEntry('lock-committed'), ['package' => 'vendor/*'])]);

        $this->assertToolError(
            sprintf('policy-overrides.json in "%s", entry 1: "package" is only valid for the composer-audit check.', $dir),
            static fn() => OverrideFile::load($dir),
        );
    }

    public function testEmptyOrNonStringScopeValueIsAToolError(): void
    {
        // An empty string and a non-string earn the same error text.
        $dir = $this->tempApp([array_merge($this->baseEntry(), ['advisory' => ''])]);

        $this->assertToolError(
            sprintf('policy-overrides.json in "%s", entry 1: "advisory" must be a non-empty string.', $dir),
            static fn() => OverrideFile::load($dir),
        );

        $dir = $this->tempApp([array_merge($this->baseEntry(), ['package' => 42])]);

        $this->assertToolError(
            sprintf('policy-overrides.json in "%s", entry 1: "package" must be a non-empty string.', $dir),
            static fn() => OverrideFile::load($dir),
        );
    }

    public function testUnscopedOverrideStillCoversEveryFailOfTheCheck(): void
    {
        // Without either field the override behaves exactly as today, which
        // includes the fail that carries no advisory data at all.
        $applier = $this->applier(new Override(
            'composer-audit',
            'fixture',
            new DateTimeImmutable('2027-01-01'),
            'tester',
        ));
        $advisory = $this->advisoryFail('vendor/a', 'PKSA-aaaa-bbbb-cccc', null);
        $exitCode = $this->exitCodeFail();

        $result = $applier->apply([$advisory, $exitCode]);

        self::assertSame(Finding::STATUS_WARNING, $result[0]->status);
        self::assertSame(Finding::STATUS_WARNING, $result[1]->status);
        self::assertSame([], $applier->unusedWarnings([$advisory, $exitCode]));
    }

    public function testScopedOverrideThatMatchesNothingWarnsWithItsScope(): void
    {
        // The check raised a failure, but not one the scope covers, so the
        // override is unused and the warning names the scope.
        $findings = [
            $this->advisoryFail('vendor/a', 'PKSA-dddd-eeee-ffff', null),
            $this->exitCodeFail(),
        ];

        $byAdvisory = $this->applier(new Override(
            'composer-audit',
            'fixture',
            new DateTimeImmutable('2027-01-01'),
            'tester',
            'PKSA-miss-0000-0000',
        ));
        $warnings = $byAdvisory->unusedWarnings($findings);
        self::assertCount(1, $warnings);
        self::assertSame('proctor/overrides/unused', $warnings[0]->ruleId);
        self::assertSame(Finding::STATUS_WARNING, $warnings[0]->status);
        self::assertSame('policy-overrides.json', $warnings[0]->path);
        self::assertSame(
            'override for "composer-audit" (advisory PKSA-miss-0000-0000) matched no failure; remove it if the problem is fixed',
            $warnings[0]->message,
        );

        $byPackage = $this->applier(new Override(
            'composer-audit',
            'fixture',
            new DateTimeImmutable('2027-01-01'),
            'tester',
            null,
            'other/*',
        ));
        $warnings = $byPackage->unusedWarnings($findings);
        self::assertCount(1, $warnings);
        self::assertSame(
            'override for "composer-audit" (package other/*) matched no failure; remove it if the problem is fixed',
            $warnings[0]->message,
        );

        $byBoth = $this->applier(new Override(
            'composer-audit',
            'fixture',
            new DateTimeImmutable('2027-01-01'),
            'tester',
            'PKSA-miss-0000-0000',
            'other/*',
        ));
        $warnings = $byBoth->unusedWarnings($findings);
        self::assertCount(1, $warnings);
        self::assertSame(
            'override for "composer-audit" (advisory PKSA-miss-0000-0000, package other/*) matched no failure; remove it if the problem is fixed',
            $warnings[0]->message,
        );
    }

    /**
     * An applier holding the one given override, active on self::TODAY.
     */
    private function applier(Override $override): OverrideApplier
    {
        return new OverrideApplier([$override], new DateTimeImmutable(self::TODAY));
    }

    /**
     * A composer-audit fail carrying one advisory for the given package.
     */
    private function advisoryFail(string $package, string $id, ?string $cve): Finding
    {
        return new Finding(
            'composer-audit',
            Finding::STATUS_FAIL,
            sprintf('%s 1.0.0: %s some title (high)', $package, $id),
            'composer.lock',
            1,
            null,
            'advisory',
            [[
                'package' => $package,
                'id' => $id,
                'cve' => $cve,
                'title' => 'some title',
                'link' => null,
                'severity' => 'high',
            ]],
        );
    }

    /**
     * The composer-audit fail that carries no advisory data at all.
     */
    private function exitCodeFail(): Finding
    {
        return new Finding(
            'composer-audit',
            Finding::STATUS_FAIL,
            'composer audit exited 1 but listed nothing PROCTOR can read (a malware or policy block, or an error); run composer audit yourself',
            'composer.lock',
            1,
            null,
            'exit-code',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baseEntry(string $check = 'composer-audit'): array
    {
        return [
            'check' => $check,
            'reason' => 'fixture',
            'expires' => '2027-01-01',
            'approved_by' => 'tester',
        ];
    }

    /**
     * Writes a policy-overrides.json with the given entries into a fresh
     * temp dir and returns the dir; the dir is removed in tearDown.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function tempApp(array $entries): string
    {
        $dir = sys_get_temp_dir() . '/proctor-scoped-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/policy-overrides.json', (string) json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->tempApps[] = $dir;

        return $dir;
    }

    /**
     * Runs $load and asserts it throws a ToolError with exactly the given
     * message.
     */
    private function assertToolError(string $expectedMessage, callable $load): void
    {
        try {
            $load();
        } catch (ToolError $error) {
            self::assertSame($expectedMessage, $error->getMessage());

            return;
        }

        self::fail('expected a ToolError');
    }
}
