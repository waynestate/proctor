<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Policy\ResolvedPolicy;
use Waynestate\Proctor\Report\JsonWriter;
use Waynestate\Proctor\Report\Report;

/**
 * The version a report carries must be the real one: the installed package
 * version when PROCTOR runs as a Composer dependency, the fallback constant
 * otherwise. Never the stale 0.1.0 placeholder again.
 */
final class ReportVersionTest extends TestCase
{
    public function testTheStalePlaceholderVersionIsGone(): void
    {
        self::assertNotSame('0.1.0', Report::VERSION);
    }

    public function testTheFallbackIsUsedWhenNotInstalledAsARelease(): void
    {
        // A dev checkout of this repository is not an installed release:
        // Composer stamps the version-less root package with a placeholder,
        // which the lookup treats as "not installed".
        self::assertSame(Report::VERSION, Report::version());
    }

    public function testTheFallbackMatchesTheLatestChangelogRelease(): void
    {
        // The fallback is only right on the day of a release. A release that
        // forgets to bump it must fail here, not go stale silently in every
        // dev checkout report.
        $lines = file(__DIR__ . '/../CHANGELOG.md', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines, 'CHANGELOG.md must be readable from the repository root');

        $latest = null;
        foreach ($lines as $line) {
            // The first heading carrying a semver release number is the
            // latest release; "## Unreleased" carries none, so it is skipped.
            if (preg_match('/^## \[?(\d+\.\d+\.\d+)\]?/', $line, $match) === 1) {
                $latest = $match[1];
                break;
            }
        }

        self::assertNotNull($latest, 'CHANGELOG.md must contain a release heading such as "## 0.2.4"');
        self::assertSame(
            Report::VERSION,
            $latest,
            'Report::VERSION must match the latest CHANGELOG release; bump it in the release commit.',
        );
    }

    public function testTheJsonReportCarriesTheReportedVersion(): void
    {
        $policy = Policy::fromFile(__DIR__ . '/fixtures/policy.json');
        $report = new Report(
            'tests/fixtures/apps/pass-app',
            new DateTimeImmutable('2026-10-27'),
            [],
            [],
            Report::EXIT_PASS,
            new ResolvedPolicy($policy, 'option', __DIR__ . '/fixtures/policy.json', '2026-10-20'),
        );

        $data = json_decode((new JsonWriter())->write($report), true);

        self::assertIsArray($data, 'the JSON report must be valid JSON');
        self::assertSame(Report::version(), $data['version']);
        self::assertNotSame('0.1.0', $data['version']);
    }
}
