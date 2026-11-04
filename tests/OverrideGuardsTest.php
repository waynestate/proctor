<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\OverrideApplier;
use Waynestate\Proctor\Check\OverridesCheck;
use Waynestate\Proctor\Override\Override;

/**
 * The guards on policy-overrides.json: an entry may only name a check that
 * is registered (and never the overrides check itself), and an active
 * override that is about to expire gets a warning.
 */
final class OverrideGuardsTest extends ProctorTestCase
{
    public function testUnknownCheckTargetIsAToolErrorNamingTheIdAndTheValidOnes(): void
    {
        $result = $this->runProctor($this->app('override-unknown-check-app'));

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('names unknown check "no-such-check"', $result['stderr']);
        self::assertStringContainsString('valid check ids are:', $result['stderr']);
        // The valid list comes from the registered checks.
        self::assertStringContainsString('php-floor', $result['stderr']);
        self::assertStringContainsString('overrides', $result['stderr']);
    }

    public function testExpiringOverrideWarnsAndOnlyTheMissingComposerAuditFails(): void
    {
        $result = $this->runProctor($this->app('override-expiring-app'));

        self::assertSame(1, $result['exit']);
        self::assertSame(['composer-audit'], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^WARN overrides: override for "lock-committed" expires on 2026-11-10 \(14 days left\)$/m',
            $result['stdout'],
        );
    }

    public function testExpiringWarningCarriesTheRuleIdInTheJsonFormat(): void
    {
        $result = $this->runProctor($this->app('override-expiring-app'), 'json');

        self::assertSame(1, $result['exit']);
        $data = json_decode($result['stdout'], true);
        self::assertIsArray($data);

        $expiring = [];
        foreach ($data['findings'] as $finding) {
            if ($finding['rule_id'] === 'proctor/overrides/expiring') {
                $expiring[] = $finding;
            }
        }
        self::assertCount(1, $expiring);
        self::assertSame('warning', $expiring[0]['status']);
        self::assertSame('policy-overrides.json', $expiring[0]['path']);
    }

    public function testTheSameOverrideOneDayOutsideTheWindowGetsNoExpiringWarning(): void
    {
        // One day earlier, the 2026-11-10 expiry is 15 days away: outside
        // the 14-day window, so no expiring warning and no fail. The active
        // override still matches no failure, so it warns as unused instead.
        $result = $this->runProctor($this->app('override-expiring-app'), 'text', null, null, '2026-10-26');

        self::assertSame(1, $result['exit']);
        self::assertSame(['composer-audit'], $this->failCheckIds($result['stdout']));
        self::assertDoesNotMatchRegularExpression(
            '/^WARN overrides: override for "lock-committed" expires on/m',
            $result['stdout'],
        );
        self::assertStringNotContainsString('days left', $result['stdout']);
        self::assertMatchesRegularExpression(
            '/^WARN overrides: override for "lock-committed" matched no failure; remove it if the problem is fixed$/m',
            $result['stdout'],
        );
    }

    public function testActiveOverrideThatMatchesNoFailureWarns(): void
    {
        $result = $this->runProctor($this->app('override-expiring-app'));

        self::assertSame(1, $result['exit']);
        self::assertMatchesRegularExpression(
            '/^WARN overrides: override for "lock-committed" matched no failure; remove it if the problem is fixed$/m',
            $result['stdout'],
        );
    }

    public function testUnusedWarningCarriesTheRuleIdInTheJsonFormat(): void
    {
        $result = $this->runProctor($this->app('override-expiring-app'), 'json');

        self::assertSame(1, $result['exit']);
        $data = json_decode($result['stdout'], true);
        self::assertIsArray($data);

        $unused = [];
        foreach ($data['findings'] as $finding) {
            if ($finding['rule_id'] === 'proctor/overrides/unused') {
                $unused[] = $finding;
            }
        }
        self::assertCount(1, $unused);
        self::assertSame('warning', $unused[0]['status']);
        self::assertSame('policy-overrides.json', $unused[0]['path']);
    }

    public function testOverrideThatDowngradesAFailureIsNotReportedAsUnused(): void
    {
        // The override really downgrades the fail, so it matched and is not
        // unused: no finding carries the unused rule id.
        $applier = new OverrideApplier(
            [new Override('lock-committed', 'fixture', new DateTimeImmutable('2027-01-01'), 'tester')],
            new DateTimeImmutable(self::TODAY),
        );
        $finding = new Finding(
            'lock-committed',
            Finding::STATUS_FAIL,
            'composer.lock is missing',
        );

        $result = array_merge($applier->apply([$finding]), $applier->unusedWarnings([$finding]));

        self::assertCount(1, $result);
        self::assertSame(Finding::STATUS_WARNING, $result[0]->status);
        foreach ($result as $resultFinding) {
            self::assertNotSame('proctor/overrides/unused', $resultFinding->ruleId);
        }
    }

    public function testExpiredOverrideGetsNoUnusedWarning(): void
    {
        // The override expired on 2026-01-01, so the overrides check fails
        // on it; it must not also earn an unused warning.
        $result = $this->runProctor($this->app('expired-override-app'));

        self::assertStringContainsString(
            'FAIL overrides: override for "lock-committed" expired on 2026-01-01',
            $result['stdout'],
        );
        self::assertStringNotContainsString('matched no failure', $result['stdout']);
    }

    public function testOverrideOnItsExpiryDayIsStillActiveAndWarnsAsUnused(): void
    {
        // The expiry day itself is still active (expired means expires <
        // today), so the override passes the check and warns as unused.
        $result = $this->runProctor($this->app('override-expiring-app'), 'text', null, null, '2026-11-10');

        self::assertSame(1, $result['exit']);
        self::assertSame(['composer-audit'], $this->failCheckIds($result['stdout']));
        self::assertStringContainsString(
            'WARN overrides: override for "lock-committed" matched no failure; remove it if the problem is fixed',
            $result['stdout'],
        );
    }

    public function testOverrideTheDayAfterItsExpiryFailsAndGetsNoUnusedWarning(): void
    {
        // One day past the expiry the override is expired: the overrides
        // check fails on it and it gets no unused warning.
        $result = $this->runProctor($this->app('override-expiring-app'), 'text', null, null, '2026-11-11');

        self::assertNotSame(0, $result['exit']);
        self::assertContains('overrides', $this->failCheckIds($result['stdout']));
        self::assertStringNotContainsString('matched no failure', $result['stdout']);
    }

    public function testUnusedWarningsAreOnePerUnmatchedActiveOverrideAndSkipExpiredOnes(): void
    {
        $applier = new OverrideApplier(
            [
                new Override('lock-committed', 'first', new DateTimeImmutable('2027-01-01'), 'tester'),
                new Override('lock-committed', 'second', new DateTimeImmutable('2027-02-01'), 'tester'),
                new Override('php-floor', 'stale', new DateTimeImmutable('2026-01-01'), 'tester'),
            ],
            new DateTimeImmutable(self::TODAY),
        );

        $warnings = $applier->unusedWarnings([
            new Finding('php-floor', Finding::STATUS_PASS, 'php floor 8.4 is active'),
        ]);

        // Two active overrides for the same check give two warnings; the
        // expired one is left to the overrides check's fail.
        self::assertCount(2, $warnings);
        foreach ($warnings as $warning) {
            self::assertSame(OverridesCheck::ID, $warning->checkId);
            self::assertSame(Finding::STATUS_WARNING, $warning->status);
            self::assertSame('proctor/overrides/unused', $warning->ruleId);
            self::assertSame('policy-overrides.json', $warning->path);
            self::assertSame(1, $warning->line);
            self::assertNull($warning->state);
        }
        self::assertSame(
            'override for "lock-committed" matched no failure; remove it if the problem is fixed',
            $warnings[0]->message,
        );
    }

    public function testTheApplierStillNeverDowngradesTheOverridesCheck(): void
    {
        // The CLI now rejects an override that names the overrides check,
        // but the applier keeps its own guard: even such an override could
        // not turn an expired-override fail into a warning.
        $applier = new OverrideApplier(
            [new Override(OverridesCheck::ID, 'fixture', new DateTimeImmutable('2027-01-01'), 'tester')],
            new DateTimeImmutable(self::TODAY),
        );
        $finding = new Finding(
            OverridesCheck::ID,
            Finding::STATUS_FAIL,
            'override for "lock-committed" expired on 2026-01-01',
            'policy-overrides.json',
        );

        $result = $applier->apply([$finding]);

        self::assertCount(1, $result);
        self::assertSame(Finding::STATUS_FAIL, $result[0]->status);
        self::assertSame('override for "lock-committed" expired on 2026-01-01', $result[0]->message);
    }
}
