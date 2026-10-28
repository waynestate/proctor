<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

/**
 * Warns when a locked version is behind the policy's latest patch for its
 * version line.
 *
 * The proctor checks the patch level of everyone in the hall, not just the
 * PHP at the front: laravel from composer.lock, vue and inertia from
 * package-lock.json, and php from --php-runtime. A stale patch is a note in
 * the margin, never a red pen: this check only warns or passes.
 *
 * A version line without a latest_patch, or a component or line the policy
 * does not know, produces no finding.
 */
final class UnpatchedCheck implements CheckInterface
{
    public function id(): string
    {
        return 'unpatched';
    }

    public function run(CheckContext $context): array
    {
        $findings = [];
        foreach ($this->seenVersions($context) as $component => $version) {
            $finding = $this->compare($context, $component, $version);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * The versions this check can see, keyed by component name, in a stable
     * order: laravel, vue, inertia, php.
     *
     * @return array<string, string>
     */
    private function seenVersions(CheckContext $context): array
    {
        $versions = [];

        $laravel = $this->laravelVersion($context->appPath);
        if ($laravel !== null) {
            $versions['laravel'] = $laravel;
        }

        $packageLock = $this->readPackageLock($context->appPath);
        if ($packageLock !== null) {
            $vue = $packageLock['packages']['node_modules/vue']['version'] ?? null;
            if (is_string($vue) && $vue !== '') {
                $versions['vue'] = $vue;
            }

            $inertia = $packageLock['packages']['node_modules/@inertiajs/vue3']['version'] ?? null;
            if (is_string($inertia) && $inertia !== '') {
                $versions['inertia'] = $inertia;
            }
        }

        if ($context->phpRuntime !== null) {
            $versions['php'] = $context->phpRuntime;
        }

        return $versions;
    }

    /**
     * Compares one seen version with the policy's latest patch for its
     * major.minor line.
     *
     * Returns null when the policy has no line or no latest_patch for it.
     */
    private function compare(CheckContext $context, string $component, string $version): ?Finding
    {
        $line = $this->majorMinor($version);
        if ($line === null) {
            return null;
        }

        $latest = $context->policy->line($component, $line);
        if ($latest === null) {
            return null;
        }

        $latestPatch = $latest['latest_patch'];
        if ($latestPatch === null) {
            return null;
        }

        $path = $this->pathFor($component);

        if (version_compare($version, $latestPatch) < 0) {
            $message = sprintf('%s %s is behind %s', $component, $version, $latestPatch);
            if ($latest['latest_patch_checked'] !== null) {
                $message .= sprintf(' (checked %s)', $latest['latest_patch_checked']);
            }

            return new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                $message . '; update to the latest patch.',
                $path,
            );
        }

        return new Finding(
            $this->id(),
            Finding::STATUS_PASS,
            sprintf('%s %s is up to date (latest patch %s)', $component, $version, $latestPatch),
            $path,
        );
    }

    /**
     * The file a component's version is locked in, or null when it comes from
     * a CLI flag (--php-runtime).
     */
    private function pathFor(string $component): ?string
    {
        return match ($component) {
            'laravel' => 'composer.lock',
            'vue', 'inertia' => 'package-lock.json',
            default => null,
        };
    }

    /**
     * The laravel/framework version from composer.lock, or null when the lock
     * file is absent, invalid, or has no laravel/framework package.
     */
    private function laravelVersion(string $appPath): ?string
    {
        $lock = $this->readComposerLock($appPath);
        if ($lock === null) {
            return null;
        }

        foreach (['packages', 'packages-dev'] as $section) {
            if (!isset($lock[$section]) || !is_array($lock[$section])) {
                continue;
            }
            foreach ($lock[$section] as $package) {
                if (!is_array($package)) {
                    continue;
                }
                if (($package['name'] ?? null) === 'laravel/framework'
                    && isset($package['version']) && is_string($package['version']) && $package['version'] !== '') {
                    return preg_replace('/^v/', '', $package['version']);
                }
            }
        }

        return null;
    }

    /**
     * The app's composer.lock, decoded, or null when absent or invalid.
     *
     * @return array<string, mixed>|null
     */
    private function readComposerLock(string $appPath): ?array
    {
        $data = $this->readJson($appPath . '/composer.lock');

        return is_array($data) ? $data : null;
    }

    /**
     * The app's package-lock.json, decoded, or null when absent or invalid.
     *
     * @return array<string, mixed>|null
     */
    private function readPackageLock(string $appPath): ?array
    {
        $data = $this->readJson($appPath . '/package-lock.json');

        return is_array($data) ? $data : null;
    }

    /**
     * Reads and decodes a JSON file, or null when it is absent or invalid.
     *
     * @return mixed
     */
    private function readJson(string $file): mixed
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        return json_decode($raw, true);
    }

    /**
     * Reduces a version to its major.minor line (e.g. "12.1.3" -> "12.1"),
     * or null when it has no numeric major.minor (a dev or alias version).
     */
    private function majorMinor(string $version): ?string
    {
        if (preg_match('/^(\d+\.\d+)/', $version, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
