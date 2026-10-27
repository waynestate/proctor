<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Policy\JsonFilePolicySource;
use Waynestate\Proctor\Policy\State;

/**
 * The state boundaries of the fixture policy, through JsonFilePolicySource.
 */
final class PolicyStateTest extends TestCase
{
    /**
     * The state of the given PHP version on the given date.
     */
    private function state(string $version, DateTimeImmutable $date): string
    {
        return (new JsonFilePolicySource(__DIR__ . '/fixtures/policy.json'))
            ->stateOf('php', $version, $date);
    }

    public function testPhp82IsSecurityOnItsLastSecurityDay(): void
    {
        self::assertSame(State::SECURITY, $this->state('8.2', new DateTimeImmutable('2026-12-31')));
    }

    public function testPhp82IsEolTheDayAfterSecurityEnds(): void
    {
        self::assertSame(State::EOL, $this->state('8.2', new DateTimeImmutable('2027-01-01')));
    }

    public function testPhp84IsActiveOnItsLastActiveDay(): void
    {
        self::assertSame(State::ACTIVE, $this->state('8.4', new DateTimeImmutable('2026-12-31')));
    }

    public function testPhp84IsSecurityTheDayAfterActiveEnds(): void
    {
        self::assertSame(State::SECURITY, $this->state('8.4', new DateTimeImmutable('2027-01-01')));
    }

    public function testPhp74IsEolGraceExpiredWellAfterEol(): void
    {
        self::assertSame(State::EOL_GRACE_EXPIRED, $this->state('7.4', new DateTimeImmutable('2025-12-01')));
    }

    public function testPhp82IsEolOnTheLastDayOfTheGraceWindow(): void
    {
        $lastGraceDay = (new DateTimeImmutable('2026-12-31'))->modify('+90 days');

        self::assertSame(State::EOL, $this->state('8.2', $lastGraceDay));
    }

    public function testPhp82IsEolGraceExpiredTheDayAfterTheGraceWindow(): void
    {
        $firstDayAfter = (new DateTimeImmutable('2026-12-31'))->modify('+91 days');

        self::assertSame(State::EOL_GRACE_EXPIRED, $this->state('8.2', $firstDayAfter));
    }
}
