<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Creed\State;

/**
 * The state boundaries of the fixture policy, through the CREED reader.
 */
final class PolicyStateTest extends TestCase
{
    /**
     * The state of the given PHP version on the given date.
     */
    private function state(string $version, DateTimeImmutable $date): State
    {
        return Policy::fromFile(__DIR__ . '/fixtures/policy.json')
            ->stateOf('php', $version, $date);
    }

    public function testPhp82IsSecurityOnItsLastSecurityDay(): void
    {
        self::assertSame(State::Security, $this->state('8.2', new DateTimeImmutable('2026-12-31')));
    }

    public function testPhp82IsEolTheDayAfterSecurityEnds(): void
    {
        self::assertSame(State::Eol, $this->state('8.2', new DateTimeImmutable('2027-01-01')));
    }

    public function testPhp84IsActiveOnItsLastActiveDay(): void
    {
        self::assertSame(State::Active, $this->state('8.4', new DateTimeImmutable('2026-12-31')));
    }

    public function testPhp84IsSecurityTheDayAfterActiveEnds(): void
    {
        self::assertSame(State::Security, $this->state('8.4', new DateTimeImmutable('2027-01-01')));
    }

    public function testPhp74IsEolGraceExpiredWellAfterEol(): void
    {
        self::assertSame(State::EolGraceExpired, $this->state('7.4', new DateTimeImmutable('2025-12-01')));
    }

    public function testPhp82IsEolOnTheLastDayOfTheGraceWindow(): void
    {
        $lastGraceDay = (new DateTimeImmutable('2026-12-31'))->modify('+90 days');

        self::assertSame(State::Eol, $this->state('8.2', $lastGraceDay));
    }

    public function testPhp82IsEolGraceExpiredTheDayAfterTheGraceWindow(): void
    {
        $firstDayAfter = (new DateTimeImmutable('2026-12-31'))->modify('+91 days');

        self::assertSame(State::EolGraceExpired, $this->state('8.2', $firstDayAfter));
    }
}
