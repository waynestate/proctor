<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

/**
 * Checks that an app with an npm manifest commits its package-lock.json.
 *
 * The front-end shapes install with `npm ci --ignore-scripts`, which fails
 * without a committed lock, so a package.json that declares dependencies
 * owes the lock the same way composer.json owes composer.lock. The warning
 * (`proctor/npm-lock-committed`) names the fix, npm install; like
 * lock-committed's out-of-sync note it never changes the exit code. A
 * missing or unparseable package.json says nothing here: other checks own
 * that verdict. The proctor collects the pens before the exam, npm among
 * them.
 */
final class NpmLockCommittedCheck implements CheckInterface
{
    public function id(): string
    {
        return 'npm-lock-committed';
    }

    public function run(CheckContext $context): array
    {
        $manifest = $this->readManifest($context->appPath . '/package.json');
        if ($manifest === null || !$this->declaresDependencies($manifest)) {
            return [];
        }

        $lockFile = $context->appPath . '/package-lock.json';
        if (!is_file($lockFile)) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'package.json declares dependencies but package-lock.json is missing; run npm install and commit the lock so `npm ci` can install it',
                'package-lock.json',
                1,
            )];
        }

        $raw = @file_get_contents($lockFile);
        if ($raw === false) {
            // An unreadable lock says nothing here; the file system owns that.
            return [];
        }

        if (json_decode($raw, true) === null && json_last_error() !== JSON_ERROR_NONE) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'package.json declares dependencies but package-lock.json is not valid JSON; run npm install to regenerate it and commit the lock',
                'package-lock.json',
                1,
            )];
        }

        return [new Finding(
            $this->id(),
            Finding::STATUS_PASS,
            'package-lock.json is present',
            'package-lock.json',
        )];
    }

    /**
     * Whether the manifest declares at least one dependency in any of the
     * three maps npm installs from; a map that is absent, null or not an
     * object counts as empty.
     *
     * @param array<string, mixed> $manifest
     */
    private function declaresDependencies(array $manifest): bool
    {
        foreach (['dependencies', 'devDependencies', 'optionalDependencies'] as $key) {
            $map = $manifest[$key] ?? null;
            if (is_array($map) && $map !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The app's package.json, decoded, or null when it is absent, unreadable
     * or does not parse to an object; those cases say nothing here.
     *
     * @return array<string, mixed>|null
     */
    private function readManifest(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
