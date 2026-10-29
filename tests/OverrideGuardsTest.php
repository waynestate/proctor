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

    public function testExpiringOverrideWarnsButStillPasses(): void
    {
        $result = $this->runProctor($this->app('override-expiring-app'));

        self::assertSame(0, $result['exit']);
        self::assertSame([], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression(
            '/^WARN overrides: override for "lock-committed" expires on 2026-11-10 \(14 days left\)$/m',
            $result['stdout'],
        );
    }

    public function testExpiringWarningCarriesTheRuleIdInTheJsonFormat(): void
    {
        $result = $this->runProctor($this->app('override-expiring-app'), 'json');

        self::assertSame(0, $result['exit']);
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

    public function testTheSameOverrideOneDayOutsideTheWindowGetsNoWarning(): void
    {
        // One day earlier, the 2026-11-10 expiry is 15 days away: outside
        // the 14-day window, so no warning and no fail.
        $result = $this->runProctor($this->app('override-expiring-app'), 'text', null, null, '2026-10-26');

        self::assertSame(0, $result['exit']);
        self::assertSame([], $this->failCheckIds($result['stdout']));
        self::assertDoesNotMatchRegularExpression('/^WARN overrides: /m', $result['stdout']);
        self::assertStringNotContainsString('days left', $result['stdout']);
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
