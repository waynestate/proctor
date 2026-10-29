<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Policy;

use InvalidArgumentException;
use RuntimeException;
use Waynestate\Proctor\Creed\Policy;

/**
 * Decides which CREED policy a run checks against.
 *
 * First match wins:
 *   1. an explicit --policy path,
 *   2. the PROCTOR_POLICY environment variable,
 *   3. <app>/vendor/waynestate/creed/policy/policy.json, when it exists, is
 *      readable, and is at least as fresh as the bundled copy,
 *   4. the copy bundled with PROCTOR.
 *
 * A vendor copy that cannot be read or parsed is skipped with a note and
 * never fails the run. An explicit source (--policy or PROCTOR_POLICY) that
 * points at a missing or invalid file is an error, as before.
 *
 * The refreshed_on dates are read from the raw JSON: the reader under
 * src/Creed/ is a generated copy that must not be edited.
 */
final class PolicyResolver
{
    public const ORIGIN_OPTION = 'option';

    public const ORIGIN_ENV = 'env';

    public const ORIGIN_VENDOR = 'vendor';

    public const ORIGIN_BUNDLED = 'bundled';

    /** The environment variable that names a policy file. */
    public const ENV_VAR = 'PROCTOR_POLICY';

    /** The policy bundled with this package. */
    private const BUNDLED_PATH = __DIR__ . '/../Creed/policy.json';

    /**
     * Resolve the policy for one run.
     *
     * @param string|null   $policyOption the --policy value, or null when not given
     * @param string        $appPath      the --path value
     * @param callable|null $note         receives a line for stderr whenever a vendor
     *                                    copy is skipped; null for no notes
     *
     * @throws InvalidArgumentException when an explicit policy file is missing or invalid
     * @throws RuntimeException         when an explicit policy file cannot be read
     */
    public static function resolve(?string $policyOption, string $appPath, ?callable $note = null): ResolvedPolicy
    {
        $emit = $note ?? static function (string $message): void {};

        if ($policyOption !== null && $policyOption !== '') {
            return self::explicit($policyOption, self::ORIGIN_OPTION);
        }

        $env = getenv(self::ENV_VAR);
        if (is_string($env) && $env !== '') {
            return self::explicit($env, self::ORIGIN_ENV);
        }

        $vendorPath = $appPath . '/vendor/waynestate/creed/policy/policy.json';
        if (is_file($vendorPath) && is_readable($vendorPath)) {
            $raw = file_get_contents($vendorPath);
            if ($raw === false) {
                $emit("Skipping vendor policy {$vendorPath}: the file could not be read.");

                return self::bundled();
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $emit("Skipping vendor policy {$vendorPath}: invalid JSON (" . json_last_error_msg() . ').');

                return self::bundled();
            }
            try {
                $vendorPolicy = Policy::fromArray($data);
            } catch (InvalidArgumentException $e) {
                $emit("Skipping vendor policy {$vendorPath}: " . $e->getMessage());

                return self::bundled();
            }

            $vendorDate = self::dateOrNull($data['refreshed_on'] ?? null);
            $bundledDate = self::refreshedOn(self::BUNDLED_PATH);
            if (self::vendorAtLeastAsFresh($vendorDate, $bundledDate)) {
                return new ResolvedPolicy($vendorPolicy, self::ORIGIN_VENDOR, $vendorPath, $vendorDate);
            }

            $emit(sprintf(
                'Using the bundled policy: the vendor copy at %s (%s) is older than the bundled copy (%s).',
                $vendorPath,
                self::when($vendorDate),
                self::when($bundledDate),
            ));
        }

        return self::bundled();
    }

    /**
     * Whether a vendor copy wins over the bundled one: the bundled copy has
     * no refreshed_on, or the vendor copy's date is the same or newer.
     *
     * ISO dates compare correctly as strings.
     */
    public static function vendorAtLeastAsFresh(?string $vendor, ?string $bundled): bool
    {
        if ($bundled === null) {
            return true;
        }

        return $vendor !== null && $vendor >= $bundled;
    }

    /**
     * Load an explicit policy file. A missing or invalid file throws, as it
     * always has.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private static function explicit(string $path, string $origin): ResolvedPolicy
    {
        return new ResolvedPolicy(Policy::fromFile($path), $origin, $path, self::refreshedOn($path));
    }

    private static function bundled(): ResolvedPolicy
    {
        return new ResolvedPolicy(
            Policy::bundled(),
            self::ORIGIN_BUNDLED,
            self::BUNDLED_PATH,
            self::refreshedOn(self::BUNDLED_PATH),
        );
    }

    /**
     * A refresh date for a note message.
     */
    private static function when(?string $date): string
    {
        return $date !== null ? "refreshed {$date}" : 'without a refreshed_on date';
    }

    /**
     * The top-level refreshed_on date of a policy file, read from the raw
     * JSON, or null when the file is unreadable, unparseable, or the field
     * is absent or not an ISO date.
     */
    private static function refreshedOn(string $path): ?string
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return self::dateOrNull($data['refreshed_on'] ?? null);
    }

    /**
     * A refreshed_on value as an ISO date string, or null when it is absent
     * or not one.
     */
    private static function dateOrNull(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        return null;
    }
}
