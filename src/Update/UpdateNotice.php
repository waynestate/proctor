<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Update;

use Throwable;

/**
 * The update notice: after any command finishes, one line on stderr when a
 * newer stable release exists, with the command that updates this install.
 *
 *     proctor 0.5.0 is out of date: 0.6.0 is the newest release. Update: git pull
 *
 * The two seams keep tests off the network and the clock: the fetcher
 * (LatestReleaseFetcher) and the clock (a closure returning unix seconds).
 * Everything is wrapped: any Throwable ends the check silently, stdout is
 * never touched, and the exit code never changes.
 */
final class UpdateNotice
{
    /**
     * The upgrading page, appended when the newest release has a higher
     * major than the running one.
     */
    public const UPGRADING_URL = 'https://proctor.apps.wayne.edu/docs/upgrading/';

    private const DAY = 86400;

    /**
     * @param string             $tool        the tool's name, as printed in the line
     * @param string             $packageRoot the folder holding bin/ and src/
     * @param \Closure(): int    $clock       current unix time, injected so tests need no real clock
     */
    public function __construct(
        private readonly string $tool,
        private readonly string $packageRoot,
        private readonly InstallKind $kind,
        private readonly UpdateCache $cache,
        private readonly LatestReleaseFetcher $fetcher,
        private readonly \Closure $clock,
    ) {}

    /**
     * Run the check and print the line when it is due. Never throws, never
     * writes to stdout, never changes an exit code.
     *
     * @param array<string, string>         $env     the process environment
     * @param list<string>                  $argv    the command line, for --quiet/-q
     * @param string                        $version the running version
     * @param callable(string): void|resource $stderr where the line goes
     */
    public function run(array $env, array $argv, string $version, mixed $stderr): void
    {
        try {
            $this->check($env, $argv, $version, $stderr);
        } catch (Throwable) {
            // The notice never gets in the way.
        }
    }

    /**
     * @param array<string, string>           $env
     * @param list<string>                    $argv
     * @param callable(string): void|resource $stderr
     */
    private function check(array $env, array $argv, string $version, mixed $stderr): void
    {
        if (self::ciEnabled($env) || self::optedOut($env) || self::quiet($argv) || !self::plainVersion($version)) {
            return;
        }

        $path = $this->cache->path($env);
        $cached = $this->cache->read($path);
        $now = ($this->clock)();

        $latest = $cached['latest'] ?? null;
        if ($cached === null || $now - $cached['checked_at'] > self::DAY) {
            $fetched = $this->fetcher->fetch();
            if ($fetched === null) {
                // Offline or unreadable: keep the old latest and refresh the
                // stamp, so the next command does not try again for a day.
                $this->cache->write($path, $now, $latest);
            } else {
                $latest = self::newestStable($fetched);
                $this->cache->write($path, $now, $latest);
            }
        }

        if ($latest === null || version_compare($latest, $version) <= 0) {
            return;
        }

        $line = sprintf(
            '%s %s is out of date: %s is the newest release. Update: %s',
            $this->tool,
            $version,
            $latest,
            $this->kind->updateCommand($latest, $this->packageRoot, $env),
        );
        if (self::major(self::normalizeVersion($latest)) > self::major($version)) {
            $line .= ' Upgrading: ' . self::UPGRADING_URL;
        }

        $this->writeLine($stderr, $line . "\n");
    }

    /**
     * The highest stable release in the p2 package list: versions containing
     * dev, alpha, beta or rc (any case) are dropped, a leading v is stripped,
     * and the rest compare with version_compare.
     *
     * @param list<array<string, mixed>> $entries
     */
    private static function newestStable(array $entries): ?string
    {
        $newest = null;

        foreach ($entries as $entry) {
            $version = $entry['version'] ?? null;
            if (!is_string($version)) {
                continue;
            }

            $version = self::normalizeVersion($version);
            if ($version === '' || self::prerelease($version)) {
                continue;
            }

            if ($newest === null || version_compare($version, $newest) > 0) {
                $newest = $version;
            }
        }

        return $newest;
    }

    /**
     * CI is set to anything other than empty, 0 or false.
     *
     * @param array<string, string> $env
     */
    private static function ciEnabled(array $env): bool
    {
        $ci = $env['CI'] ?? '';

        return $ci !== '' && $ci !== '0' && $ci !== 'false';
    }

    /**
     * @param array<string, string> $env
     */
    private static function optedOut(array $env): bool
    {
        return ($env['WAYNE_NO_UPDATE_CHECK'] ?? '') === '1';
    }

    /**
     * @param list<string> $argv
     */
    private static function quiet(array $argv): bool
    {
        foreach ($argv as $arg) {
            if ('--quiet' === $arg || '-q' === $arg) {
                return true;
            }
        }

        return false;
    }

    /**
     * A plain release number: digits and dots only, so dev builds, -dev
     * suffixes and prereleases stay silent.
     */
    private static function plainVersion(string $version): bool
    {
        return preg_match('/^\d+(\.\d+)*$/', self::normalizeVersion($version)) === 1;
    }

    private static function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), 'v');
    }

    private static function prerelease(string $version): bool
    {
        $lower = strtolower($version);

        return str_contains($lower, 'dev')
            || str_contains($lower, 'alpha')
            || str_contains($lower, 'beta')
            || str_contains($lower, 'rc');
    }

    private static function major(string $version): int
    {
        $first = explode('.', $version)[0];

        return is_numeric($first) ? (int) $first : 0;
    }

    /**
     * @param callable(string): void|resource $stderr
     */
    private function writeLine(mixed $stderr, string $message): void
    {
        $previous = set_error_handler(static fn(): bool => true);
        try {
            if (is_resource($stderr)) {
                fwrite($stderr, $message);
            } elseif (is_callable($stderr)) {
                $stderr($message);
            }
        } finally {
            restore_error_handler();
        }
    }
}
