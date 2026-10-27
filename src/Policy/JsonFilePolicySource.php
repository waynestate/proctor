<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Policy;

use DateTimeImmutable;

/**
 * A PolicySource backed by a CREED-format policy.json file.
 *
 * The file is read and validated in the constructor; a PolicyException is
 * thrown when the file is unreadable or malformed.
 */
final class JsonFilePolicySource implements PolicySource
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct(string $file)
    {
        $this->data = self::read($file);
    }

    public function stateOf(string $component, string $version, DateTimeImmutable $date): string
    {
        $versions = $this->data['components'][$component]['versions'] ?? null;
        if (!is_array($versions) || !array_key_exists($version, $versions)) {
            throw new PolicyException(sprintf('version %s not in policy', $version));
        }

        /** @var array<string, mixed> $entry */
        $entry = $versions[$version];
        $eolGraceDays = (int) ($this->data['rules']['eol_grace_days'] ?? 0);

        if (isset($entry['active_until']) && $date <= self::parseDate($entry['active_until'])) {
            return State::ACTIVE;
        }

        $securityUntil = self::parseDate($entry['security_until']);
        if ($date <= $securityUntil) {
            return State::SECURITY;
        }

        if ($date <= $securityUntil->modify(sprintf('+%d days', $eolGraceDays))) {
            return State::EOL;
        }

        return State::EOL_GRACE_EXPIRED;
    }

    public function rules(): array
    {
        /** @var array<string, mixed> $rules */
        $rules = $this->data['rules'];

        return $rules;
    }

    /**
     * Reads and validates the policy file.
     *
     * @return array<string, mixed>
     *
     * @throws PolicyException
     */
    private static function read(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new PolicyException(sprintf('Policy file "%s" does not exist or is not readable.', $file));
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new PolicyException(sprintf('Policy file "%s" could not be read.', $file));
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new PolicyException(sprintf(
                'Policy file "%s" is not valid JSON: %s',
                $file,
                json_last_error_msg()
            ));
        }

        // The schema_version is never refused: an older Check must still read
        // a newer policy file, so any value is accepted and keys this reader
        // does not know are ignored.

        $versions = $data['components']['php']['versions'] ?? null;
        if (!is_array($versions) || $versions === []) {
            throw new PolicyException(sprintf('Policy file "%s" is missing components.php.versions.', $file));
        }

        foreach ($versions as $version => $entry) {
            if (!is_array($entry)) {
                throw new PolicyException(sprintf(
                    'Policy file "%s": components.php.versions["%s"] is not an object.',
                    $file,
                    $version
                ));
            }
            if (!isset($entry['security_until']) || !self::isValidDate($entry['security_until'])) {
                throw new PolicyException(sprintf(
                    'Policy file "%s": components.php.versions["%s"] is missing a security_until date (YYYY-MM-DD).',
                    $file,
                    $version
                ));
            }
            if (isset($entry['active_until']) && !self::isValidDate($entry['active_until'])) {
                throw new PolicyException(sprintf(
                    'Policy file "%s": components.php.versions["%s"].active_until is not a YYYY-MM-DD date.',
                    $file,
                    $version
                ));
            }
        }

        if (!isset($data['rules']) || !is_array($data['rules'])) {
            throw new PolicyException(sprintf('Policy file "%s" is missing rules.', $file));
        }

        return $data;
    }

    private static function isValidDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && self::parseDate($value) !== null;
    }

    /**
     * Parses a YYYY-MM-DD date at midnight, or returns null when invalid.
     */
    private static function parseDate(string $value): ?DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    }
}
