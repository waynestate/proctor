<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\ImagePhpCheck;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Creed\State;

/**
 * The image-php check grades the PHP line named by the deploy image tag in
 * parity.json, the way bin/proctor builds its context: the fixture policy
 * and a fixed date, never the real clock.
 */
final class ImagePhpCheckTest extends TestCase
{
    /**
     * The findings of the image-php check on the named fixture app on the
     * given date.
     *
     * @return list<Finding>
     */
    private function findingsIn(string $app, string $today): array
    {
        $context = new CheckContext(
            __DIR__ . '/fixtures/apps/' . $app,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable($today),
        );

        return (new ImagePhpCheck())->run($context);
    }

    public function testActiveImagePasses(): void
    {
        // 8.5 is active until 2027-12-31 in the fixture policy.
        $findings = $this->findingsIn('image-php-active', '2026-10-27');

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame(State::Active, $findings[0]->state);
        self::assertSame('proctor/image-php', $findings[0]->ruleId);
        self::assertSame('parity.json', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame('deploy image php-apache:8.5r3 runs php 8.5, which is active', $findings[0]->message);
    }

    public function testSecurityOnlyImageWarnsToMoveTheTag(): void
    {
        // 8.3 has no active_until and is in security-only support until 2027-12-31.
        $findings = $this->findingsIn('image-php-security', '2026-10-27');

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame(State::Security, $findings[0]->state);
        self::assertSame(
            'deploy image php-apache:8.3r1 runs php 8.3, which is in security-only support; move the image to a newer php-apache tag',
            $findings[0]->message,
        );
    }

    public function testEolImageWarnsWithinTheGraceWindow(): void
    {
        // 8.1 ended security support on 2025-12-31; with the policy's 90 grace
        // days it is eol until 2026-03-31.
        $findings = $this->findingsIn('image-php-eol', '2026-02-15');

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame(State::Eol, $findings[0]->state);
        self::assertSame(
            'deploy image php-apache:8.1r2 runs php 8.1, which is eol; move the image to a supported php-apache tag',
            $findings[0]->message,
        );
    }

    public function testEolImageWarnsAfterTheGraceWindow(): void
    {
        $findings = $this->findingsIn('image-php-eol', '2026-10-27');

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame(State::EolGraceExpired, $findings[0]->state);
        self::assertSame(
            'deploy image php-apache:8.1r2 runs php 8.1, which is eol_grace_expired; move the image to a supported php-apache tag',
            $findings[0]->message,
        );
    }

    public function testUnlistedLineWarnsThatItCannotBeGraded(): void
    {
        // 8.0 falls between the listed lines 7.4 and 8.1, so the policy does not know it.
        $findings = $this->findingsIn('image-php-unlisted', '2026-10-27');

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertNull($findings[0]->state);
        self::assertSame(
            'deploy image php-apache:8.0r1: the policy does not list php 8.0, so its support cannot be graded',
            $findings[0]->message,
        );
    }

    public function testMissingParityJsonSaysNothing(): void
    {
        // pass-app has no parity.json.
        self::assertSame([], $this->findingsIn('pass-app', '2026-10-27'));
    }

    public function testInvalidParityJsonSaysNothing(): void
    {
        self::assertSame([], $this->findingsIn('image-php-invalid', '2026-10-27'));
    }

    public function testNonPhpApacheImageSaysNothing(): void
    {
        self::assertSame([], $this->findingsIn('image-php-other', '2026-10-27'));
    }

    public function testRegistryPrefixedImageMatchesOnAnActiveLine(): void
    {
        $findings = $this->findingsIn('image-php-registry', '2026-10-27');

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame(State::Active, $findings[0]->state);
        self::assertSame('deploy image php-apache:8.5r3 runs php 8.5, which is active', $findings[0]->message);
    }
}
