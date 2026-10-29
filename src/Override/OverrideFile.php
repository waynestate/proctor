<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Override;

use DateTimeImmutable;
use Waynestate\Proctor\Check\OverridesCheck;
use Waynestate\Proctor\ToolError;

/**
 * Reads and validates an app's policy-overrides.json file.
 *
 * The file is a JSON array of objects:
 * {"check": string, "reason": string, "expires": "YYYY-MM-DD", "approved_by": string}
 *
 * An absent file is not an error: it simply means the app has no overrides.
 * A present but malformed file or entry is a tool error (exit 2).
 */
final class OverrideFile
{
    public const FILENAME = 'policy-overrides.json';

    private function __construct() {}

    /**
     * @return list<Override>
     *
     * @throws ToolError if the file is present but malformed
     */
    public static function load(string $appPath): array
    {
        $file = $appPath . '/' . self::FILENAME;
        if (!is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new ToolError(sprintf('%s in "%s" could not be read.', self::FILENAME, $appPath));
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ToolError(sprintf(
                '%s in "%s" is not valid JSON: %s',
                self::FILENAME,
                $appPath,
                json_last_error_msg(),
            ));
        }

        // An empty array is valid: the app simply has no overrides.
        if ($data !== [] && array_keys($data) !== range(0, count($data) - 1)) {
            throw new ToolError(sprintf(
                '%s in "%s" must be a JSON array of override objects.',
                self::FILENAME,
                $appPath,
            ));
        }

        $overrides = [];
        foreach ($data as $index => $entry) {
            $overrides[] = self::parseEntry($entry, $index, $appPath);
        }

        return $overrides;
    }

    /**
     * Throws when an override names a check id that is not registered.
     *
     * The CLI calls this with the ids of the checks it actually runs, built
     * from the registered checks, so a new check needs no change here: an
     * entry that names a check nobody runs is a tool error (exit 2) instead
     * of an override that silently does nothing.
     *
     * @param list<Override> $overrides     the app's policy-overrides.json entries
     * @param list<string>   $knownCheckIds the ids of the registered checks
     *
     * @throws ToolError naming the unknown id and listing the valid ones
     */
    public static function assertKnownChecks(array $overrides, array $knownCheckIds): void
    {
        foreach ($overrides as $override) {
            if (!in_array($override->check, $knownCheckIds, true)) {
                throw new ToolError(sprintf(
                    '%s names unknown check "%s"; valid check ids are: %s.',
                    self::FILENAME,
                    $override->check,
                    implode(', ', $knownCheckIds),
                ));
            }
        }
    }

    /**
     * @param mixed $entry
     *
     * @throws ToolError
     */
    private static function parseEntry(mixed $entry, int $index, string $appPath): Override
    {
        $where = sprintf('%s in "%s", entry %d', self::FILENAME, $appPath, $index + 1);

        if (!is_array($entry)) {
            throw new ToolError(sprintf('%s is not an object.', $where));
        }

        $check = $entry['check'] ?? null;
        if (!is_string($check) || trim($check) === '') {
            throw new ToolError(sprintf('%s is missing a "check" string.', $where));
        }

        // An override cannot exempt the check that polices overrides: an
        // expired override must always fail, so targeting the overrides
        // check is rejected here instead of being silently ignored.
        if ($check === OverridesCheck::ID) {
            throw new ToolError(sprintf(
                '%s: an override cannot target the overrides check.',
                $where,
            ));
        }

        $reason = $entry['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '') {
            throw new ToolError(sprintf('%s is missing a "reason" string.', $where));
        }

        $expires = $entry['expires'] ?? null;
        if (!is_string($expires) || self::parseDate($expires) === null) {
            throw new ToolError(sprintf(
                '%s has a missing or invalid "expires" date (expected YYYY-MM-DD).',
                $where,
            ));
        }

        $approvedBy = $entry['approved_by'] ?? null;
        if (!is_string($approvedBy) || trim($approvedBy) === '') {
            throw new ToolError(sprintf('%s is missing an "approved_by" string.', $where));
        }

        return new Override($check, $reason, self::parseDate($expires), $approvedBy);
    }

    /**
     * Parses a YYYY-MM-DD date at midnight, or returns null when invalid.
     *
     * createFromFormat rolls out-of-range values over (2027-13-99 becomes
     * 2028-04-08) instead of failing, so the parsed date must round-trip
     * to the exact input string to be accepted.
     */
    private static function parseDate(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $date : null;
    }
}
