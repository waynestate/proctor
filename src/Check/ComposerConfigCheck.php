<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\ToolError;

/**
 * Checks the config block of composer.json for unsafe Composer settings.
 *
 * An app can turn off Composer's safety switches without any other check
 * noticing: allow-plugins set to true lets every dependency run code during
 * install, secure-http false allows plain-HTTP repositories, lock false lets
 * composer.json drift from the lock, and audit.block-insecure false stops
 * insecure versions from being blocked. A require.php without a matching
 * config.platform.php means the lock resolves against whatever PHP built it.
 *
 * Every finding is a warning or an info note, never a fail, so an app that
 * passes today keeps passing while the owner sees the open switch.
 */
final class ComposerConfigCheck implements CheckInterface
{
    public function id(): string
    {
        return 'composer-config';
    }

    public function run(CheckContext $context): array
    {
        $file = $context->appPath . '/composer.json';
        if (!is_file($file)) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no composer.json, so no config to check',
                'composer.json',
            )];
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new ToolError(sprintf('composer.json in "%s" could not be read.', $context->appPath));
        }

        // The CheckRunner has already validated the file, so this only fails
        // if the file changed between the two reads.
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ToolError(sprintf('composer.json in "%s" is not valid JSON.', $context->appPath));
        }

        $config = $data['config'] ?? [];
        if (!is_array($config)) {
            $config = [];
        }

        $findings = [];

        if (($config['allow-plugins'] ?? null) === true) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'allow-plugins is true, so any plugin may run code during install',
                'composer.json',
                1,
                null,
                'allow-plugins-all',
            );
        }

        if (($config['secure-http'] ?? null) === false) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'secure-http is false, so Composer may use plain HTTP for repositories',
                'composer.json',
                1,
                null,
                'secure-http-off',
            );
        }

        if (($config['lock'] ?? null) === false) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'lock is off, so composer.json can change without a new lock',
                'composer.json',
                1,
                null,
                'lock-off',
            );
        }

        $audit = $config['audit'] ?? [];
        if (is_array($audit) && ($audit['block-insecure'] ?? null) === false) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'audit.block-insecure is false, so insecure versions are not blocked',
                'composer.json',
                1,
                null,
                'audit-blocking-off',
            );
        }

        if ($this->hasRequirePhp($data) && !$this->hasPlatformPhp($config)) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_INFO,
                'require.php is set but config.platform.php is not, so the lock resolves against the local PHP',
                'composer.json',
                1,
                null,
                'platform-php-unset',
            );
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no unsafe values in the composer config block',
                'composer.json',
            )];
        }

        return $findings;
    }

    /**
     * True when require.php carries a constraint.
     *
     * @param array<string, mixed> $data
     */
    private function hasRequirePhp(array $data): bool
    {
        $require = $data['require'] ?? [];
        if (!is_array($require)) {
            return false;
        }

        $constraint = $require['php'] ?? null;

        return is_string($constraint) && $constraint !== '';
    }

    /**
     * True when config.platform.php is set.
     *
     * @param array<string, mixed> $config
     */
    private function hasPlatformPhp(array $config): bool
    {
        $platform = $config['platform'] ?? [];

        return is_array($platform) && isset($platform['php']);
    }
}
