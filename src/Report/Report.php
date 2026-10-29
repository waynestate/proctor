<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Report;

use Composer\InstalledVersions;
use DateTimeImmutable;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\Policy\ResolvedPolicy;

/**
 * The result of one `proctor check` run, ready for a ReportWriter.
 *
 * Value object: immutable. The findings are already in check order with the
 * overrides applied (fail -> warning, except eol_grace_expired). The
 * overrides list is the app's raw policy-overrides.json entries; a writer
 * computes each entry's "expired" flag from today.
 */
final class Report
{
    public const TOOL = 'proctor';

    /**
     * The version a report carries when PROCTOR is not installed as a
     * Composer package: a dev checkout of this repository, or a copy without
     * one. An installed copy reports its real release version instead.
     */
    public const VERSION = '0.2.5';

    /** The package name PROCTOR publishes under. */
    private const PACKAGE = 'waynestate/proctor';

    public const EXIT_PASS = 0;

    public const EXIT_FAIL = 1;

    /**
     * @param string                     $path      the --path value, as given
     * @param list<Finding>              $findings  the findings in check order
     * @param list<Override>             $overrides the app's policy-overrides.json entries
     * @param int                        $exitCode  EXIT_PASS or EXIT_FAIL
     * @param ResolvedPolicy             $resolvedPolicy the CREED policy the run checked against,
     *                                                   and where it came from
     * @param array<string, string|null> $tools          the versions of the external tools the run
     *                                                   used, e.g. ['composer' => '2.10.3']; null
     *                                                   when a tool's version could not be determined
     */
    public function __construct(
        public readonly string $path,
        public readonly DateTimeImmutable $today,
        public readonly array $findings,
        public readonly array $overrides,
        public readonly int $exitCode,
        public readonly ResolvedPolicy $resolvedPolicy,
        public readonly array $tools = [],
    ) {
        if (!in_array($this->exitCode, [self::EXIT_PASS, self::EXIT_FAIL], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Report exit code must be %d or %d; got %d.',
                self::EXIT_PASS,
                self::EXIT_FAIL,
                $this->exitCode,
            ));
        }
    }

    /**
     * "pass" or "fail", for the report output.
     */
    public function result(): string
    {
        return self::EXIT_FAIL === $this->exitCode ? 'fail' : 'pass';
    }

    /**
     * The version a report carries: the installed package version when
     * PROCTOR runs as a Composer dependency, the fallback constant otherwise.
     */
    public static function version(): string
    {
        $installed = self::installedVersion();

        return $installed ?? self::VERSION;
    }

    /**
     * The pretty version Composer recorded for this package, or null when
     * PROCTOR is not installed as a release: no entry at all (a phar or a
     * manual copy), or the placeholder Composer stamps on a version-less
     * root package (a dev checkout of this very repository). A leading "v"
     * is dropped, the way the release tags read.
     */
    private static function installedVersion(): ?string
    {
        try {
            // When this repository is itself the root package, Composer
            // reports a root version (1.0.0, dev-main) that is not a release.
            if (InstalledVersions::getRootPackage()['name'] === self::PACKAGE) {
                return null;
            }
            $pretty = InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (\Throwable) {
            return null;
        }
        if ($pretty === null || $pretty === '' || str_contains($pretty, 'no-version-set')) {
            return null;
        }

        return preg_replace('/^v/i', '', $pretty) ?? $pretty;
    }

    /**
     * The number of findings with the given status.
     */
    public function countByStatus(string $status): int
    {
        $count = 0;
        foreach ($this->findings as $finding) {
            if ($finding->status === $status) {
                $count++;
            }
        }

        return $count;
    }
}
