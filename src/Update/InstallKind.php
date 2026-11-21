<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Update;

use Throwable;

/**
 * How the running copy of PROCTOR was installed, detected from where the
 * code lives (the package root is the folder holding bin/ and src/).
 *
 * The detection order is the contract's: PHAR, source checkout, Composer
 * global, project dependency, unknown. PROCTOR ships a PHAR (box.json.dist),
 * so a PHAR copy downloads the newest release asset; every other install
 * has a git or Composer command.
 */
enum InstallKind
{
    case Phar;
    case SourceCheckout;
    case ComposerGlobal;
    case ProjectDependency;
    case Unknown;

    private const TOOL = 'proctor';

    private const PACKAGE = 'waynestate/proctor';

    /**
     * The update command for a PHAR copy: the single-file-download one-
     * liner the README and docs/install.md print, where <release> is the
     * base URL of the newest tag's asset links. The release-phar job in
     * .gitlab-ci.yml attaches proctor.phar and proctor.phar.sha256 to each
     * tag's GitLab Release, so the tag name is the version itself: tags
     * are cut bare (0.8.0) or v-prefixed (v0.8.0), which is exactly what
     * Packagist reports after a leading v is stripped (a v-prefixed tag
     * keeps its v in the asset links while Packagist reports the version
     * without it). The release page lists both files, so the user copies
     * the base URL from there.
     */
    private const PHAR_COMMAND = 'curl -fsSL -o proctor.phar "<release>/proctor.phar" -o proctor.phar.sha256 "<release>/proctor.phar.sha256" && sha256sum -c proctor.phar.sha256 && php proctor.phar --version';

    /**
     * Detect the install from the package root.
     *
     * @param array<string, string> $env
     */
    public static function detect(string $packageRoot, array $env): self
    {
        $root = self::normalize($packageRoot);

        if (\Phar::running(false) !== '') {
            return self::Phar;
        }

        if (self::hasGit($root) && !self::underVendor($root)) {
            return self::SourceCheckout;
        }

        $project = self::vendorParent($root);
        if ($project === null) {
            return self::Unknown;
        }

        if (in_array(self::normalize($project), self::globalHomes($env), true)) {
            return self::ComposerGlobal;
        }

        return self::ProjectDependency;
    }

    /**
     * The command that brings this install to the newest release.
     *
     * @param array<string, string> $env
     */
    public function updateCommand(string $newest, string $packageRoot, array $env): string
    {
        return match ($this) {
            self::Phar => self::PHAR_COMMAND,
            self::ComposerGlobal, self::Unknown => 'composer global update ' . self::PACKAGE,
            self::SourceCheckout => 'git pull',
            self::ProjectDependency => $this->projectCommand($newest, $packageRoot),
        };
    }

    /**
     * The constraint the project pins PROCTOR to, as [constraint, devOnly],
     * or null when composer.json cannot be read or does not pin it. require
     * wins over require-dev.
     *
     * @return array{0: string, 1: bool}|null
     */
    private function projectPin(string $project): ?array
    {
        try {
            $file = $project . '/composer.json';
            if (!is_file($file) || !is_readable($file)) {
                return null;
            }

            $previous = set_error_handler(static fn(): bool => true);
            try {
                $body = file_get_contents($file);
            } finally {
                restore_error_handler();
            }
            if (!is_string($body)) {
                return null;
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                return null;
            }

            $require = $decoded['require'] ?? [];
            if (is_array($require) && isset($require[self::PACKAGE]) && is_string($require[self::PACKAGE])) {
                return [$require[self::PACKAGE], false];
            }

            $requireDev = $decoded['require-dev'] ?? [];
            if (is_array($requireDev) && isset($requireDev[self::PACKAGE]) && is_string($requireDev[self::PACKAGE])) {
                return [$requireDev[self::PACKAGE], true];
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private function projectCommand(string $newest, string $packageRoot): string
    {
        $update = 'composer update ' . self::PACKAGE;
        $project = self::vendorParent(self::normalize($packageRoot));
        if ($project === null) {
            return $update;
        }

        $pin = $this->projectPin($project);
        if ($pin === null || !self::constraintExcludesNewest($pin[0], $newest)) {
            return $update;
        }

        $majorMinor = implode('.', array_slice(explode('.', $newest), 0, 2));
        $command = 'composer require ' . self::PACKAGE . ':^' . $majorMinor;

        return $pin[1] ? $command . ' --dev' : $command;
    }

    /**
     * Whether a ^ or ~ constraint excludes the newest release. The contract
     * names only caret and tilde pins; every other shape (wildcards, ranges,
     * exact versions) keeps the plain update command.
     */
    private static function constraintExcludesNewest(string $constraint, string $newest): bool
    {
        $constraint = trim($constraint);
        $operator = '';
        if (str_starts_with($constraint, '^')) {
            $operator = '^';
        } elseif (str_starts_with($constraint, '~')) {
            $operator = '~';
        } else {
            return false;
        }

        $base = self::segments(substr($constraint, 1));
        $latest = self::segments($newest);

        if ('~' === $operator) {
            return $latest[0] > $base[0] || ($latest[0] === $base[0] && $latest[1] > $base[1]);
        }

        // Caret: the leftmost non-zero segment is fixed.
        if ($base[0] > 0) {
            return $latest[0] > $base[0];
        }
        if ($base[1] > 0) {
            return $latest[0] > 0 || ($latest[0] === 0 && $latest[1] > $base[1]);
        }

        return $latest[0] > 0 || ($latest[0] === 0 && $latest[1] > 0);
    }

    /**
     * @param array<string, string> $env
     * @return list<string>
     */
    private static function globalHomes(array $env): array
    {
        $homes = [];

        $composerHome = $env['COMPOSER_HOME'] ?? '';
        if ($composerHome !== '') {
            $homes[] = self::normalize($composerHome);
        }

        $home = $env['HOME'] ?? '';
        if ($home === '') {
            $home = $env['USERPROFILE'] ?? '';
        }
        if ($home !== '') {
            $home = self::normalize($home);
            $homes[] = $home . '/.config/composer';
            $homes[] = $home . '/.composer';
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            $appData = $env['APPDATA'] ?? '';
            if ($appData !== '') {
                $homes[] = self::normalize($appData) . '/Composer';
            }
        }

        return $homes;
    }

    private static function hasGit(string $root): bool
    {
        return is_dir($root . '/.git') || is_file($root . '/.git');
    }

    private static function underVendor(string $root): bool
    {
        return in_array('vendor', explode('/', $root), true);
    }

    private static function vendorParent(string $root): ?string
    {
        $suffix = '/vendor/waynestate/' . self::TOOL;
        if (!str_ends_with($root, $suffix)) {
            return null;
        }

        return substr($root, 0, -strlen($suffix));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function segments(string $version): array
    {
        $parts = explode('.', ltrim(trim($version), 'v'));
        $get = static fn(int $i): int => isset($parts[$i]) && is_numeric($parts[$i]) ? (int) $parts[$i] : 0;

        return [$get(0), $get(1), $get(2)];
    }

    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        while (str_contains($path, '//')) {
            $path = str_replace('//', '/', $path);
        }

        return rtrim($path, '/');
    }
}
