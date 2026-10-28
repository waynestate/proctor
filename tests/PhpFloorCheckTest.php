<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\PhpFloorCheck;
use Waynestate\Proctor\Policy\JsonFilePolicySource;
use Waynestate\Proctor\Policy\State;

/**
 * The php-floor check's security-only warning, with the end date and the
 * days left, the way bin/proctor builds its context: the fixture policy and
 * a fixed date, never the real clock.
 */
final class PhpFloorCheckTest extends TestCase
{
    /**
     * The findings of the php-floor check on the security-app fixture
     * (floor 8.3, security_until 2027-12-31 in the fixture policy) on the
     * given date.
     *
     * @return list<Finding>
     */
    private function findingsOn(string $today): array
    {
        $context = new CheckContext(
            __DIR__ . '/fixtures/apps/security-app',
            new JsonFilePolicySource(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable($today),
        );

        return (new PhpFloorCheck())->run($context);
    }

    public function testSecurityOnlyWarningShowsTheEndDateAndDaysLeftMidWindow(): void
    {
        $findings = $this->findingsOn('2026-10-27');

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame(State::SECURITY, $findings[0]->state);
        self::assertSame(
            'php floor 8.3 is in security-only support until 2027-12-31 (430 days left)',
            $findings[0]->message,
        );
    }

    public function testSecurityOnlyWarningSaysOneDayLeftTheDayBefore(): void
    {
        $findings = $this->findingsOn('2027-12-30');

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame(
            'php floor 8.3 is in security-only support until 2027-12-31 (1 day left)',
            $findings[0]->message,
        );
    }

    public function testSecurityOnlyWarningSaysEndsTodayOnTheLastDay(): void
    {
        $findings = $this->findingsOn('2027-12-31');

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame(
            'php floor 8.3 is in security-only support until 2027-12-31 (ends today)',
            $findings[0]->message,
        );
    }

    public function testActiveFloorStillPassesWithTheUnchangedMessage(): void
    {
        // pass-app has a ^8.4 floor, active until 2026-12-31 in the fixture policy.
        $context = new CheckContext(
            __DIR__ . '/fixtures/apps/pass-app',
            new JsonFilePolicySource(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
        );

        $findings = (new PhpFloorCheck())->run($context);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('php floor 8.4 is active', $findings[0]->message);
    }
}
