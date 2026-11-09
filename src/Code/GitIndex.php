<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Code;

/**
 * The files git lists for an app, read from the repository's index file in
 * PHP, without starting git.
 *
 * `git ls-files` prints the paths in the index; this reads the same index
 * (format versions 2, 3 and 4, SHA-1 or SHA-256 object names) so ADVISOR's
 * in-process run can ask "is this file tracked?" and still start no
 * subprocess. It finds the repository by walking up from the app to the
 * first `.git` folder, or `.git` file (a worktree or submodule) that names
 * one.
 *
 * It answers null, "cannot tell", when there is no repository, the index is
 * unreadable or in a shape it does not know (a split index, a future
 * version). A caller that must not grade an untracked file treats null as
 * "not tracked". The proctor checks the class register, not the coat rack.
 */
final class GitIndex
{
    /** Index entry flag: an extended flags word follows (version 3 and up). */
    private const FLAG_EXTENDED = 0x4000;

    /** Mode of a sparse-index directory entry. */
    private const MODE_DIRECTORY = 0040000;

    private function __construct() {}

    /**
     * The tracked paths under the app, relative to it with forward slashes,
     * as array keys; null when git's index cannot be read.
     *
     * @return array<string, true>|null
     */
    public static function trackedUnder(string $appPath): ?array
    {
        $app = realpath($appPath);
        if ($app === false || !is_dir($app)) {
            return null;
        }
        $app = rtrim(str_replace('\\', '/', $app), '/');

        $repo = self::repository($app);
        if ($repo === null) {
            return null;
        }
        [$top, $gitDir] = $repo;

        $index = $gitDir . '/index';
        if (!is_file($index)) {
            // A repository with nothing added yet lists no file.
            return is_dir($gitDir) ? [] : null;
        }
        $raw = @file_get_contents($index);
        if ($raw === false) {
            return null;
        }

        $paths = self::parse($raw, self::hashLength($gitDir));
        if ($paths === null) {
            return null;
        }

        $prefix = $app === $top ? '' : substr($app, strlen($top) + 1) . '/';
        $tracked = [];
        foreach ($paths as $path) {
            if ($prefix === '' || str_starts_with($path, $prefix)) {
                $tracked[substr($path, strlen($prefix))] = true;
            }
        }

        return $tracked;
    }

    /**
     * The work tree top and the git directory for the app: the first folder
     * at or above it with a `.git` folder, or a `.git` file whose
     * `gitdir:` line names one.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function repository(string $app): ?array
    {
        $dir = $app;
        while (true) {
            $dotGit = $dir . '/.git';
            if (is_dir($dotGit)) {
                return [$dir, $dotGit];
            }
            if (is_file($dotGit)) {
                $line = (string) @file_get_contents($dotGit);
                if (preg_match('/^gitdir:\s*(.+?)\s*$/m', $line, $m) !== 1) {
                    return null;
                }
                $gitDir = self::absolute($m[1], $dir);

                return is_dir($gitDir) ? [$dir, $gitDir] : null;
            }
            $parent = rtrim(str_replace('\\', '/', dirname($dir)), '/');
            if ($parent === $dir || $parent === '') {
                return null;
            }
            $dir = $parent;
        }
    }

    /**
     * The object name length in bytes: 32 when the repository's config sets
     * extensions.objectFormat to sha256, else 20 (SHA-1). A worktree keeps
     * its config in the common directory its `commondir` file names.
     */
    private static function hashLength(string $gitDir): int
    {
        $common = $gitDir;
        $commondir = @file_get_contents($gitDir . '/commondir');
        if (is_string($commondir) && trim($commondir) !== '') {
            $common = self::absolute(trim($commondir), $gitDir);
        }
        $config = (string) @file_get_contents($common . '/config');

        return preg_match('/^\s*objectformat\s*=\s*sha256\s*$/mi', $config) === 1 ? 32 : 20;
    }

    /**
     * The paths in an index file, or null when its shape is not one this
     * reader knows.
     *
     * @return list<string>|null
     */
    private static function parse(string $raw, int $hashLength): ?array
    {
        $length = strlen($raw);
        if ($length < 12 || substr($raw, 0, 4) !== 'DIRC') {
            return null;
        }
        $version = self::uint32($raw, 4);
        $count = self::uint32($raw, 8);
        if (!in_array($version, [2, 3, 4], true)) {
            return null;
        }

        // ctime, mtime, dev, ino, mode, uid, gid, size: ten 32-bit words; then the object name and the flags.
        $fixed = 40 + $hashLength + 2;
        $offset = 12;
        $previous = '';
        $paths = [];
        for ($i = 0; $i < $count; $i++) {
            if ($offset + $fixed > $length) {
                return null;
            }
            $start = $offset;
            $mode = self::uint32($raw, $start + 24);
            $flags = self::uint16($raw, $start + 40 + $hashLength);
            $position = $start + $fixed;
            if ($version >= 3 && ($flags & self::FLAG_EXTENDED) !== 0) {
                $position += 2;
            }

            if ($version === 4) {
                $strip = self::varint($raw, $position);
                if ($strip === null || $strip > strlen($previous)) {
                    return null;
                }
                $end = strpos($raw, "\0", $position);
                if ($end === false) {
                    return null;
                }
                $path = substr($previous, 0, strlen($previous) - $strip) . substr($raw, $position, $end - $position);
                $offset = $end + 1;
            } else {
                $end = strpos($raw, "\0", $position);
                if ($end === false) {
                    return null;
                }
                $path = substr($raw, $position, $end - $position);
                // Entries are padded with 1 to 8 NULs to a multiple of 8 bytes.
                $offset = $start + (($position - $start + strlen($path) + 8) & ~7);
            }
            $previous = $path;

            if (($mode & 0170000) !== self::MODE_DIRECTORY && !str_ends_with($path, '/')) {
                $paths[] = $path;
            }
        }

        // A split index keeps most entries in a shared file; this reader does not follow it.
        while ($offset + 8 <= $length - $hashLength) {
            $signature = substr($raw, $offset, 4);
            if ($signature === 'link') {
                return null;
            }
            $offset += 8 + self::uint32($raw, $offset + 4);
        }

        return $paths;
    }

    /**
     * Reads the offset-encoded varint index version 4 uses for its path
     * prefix, advancing the position past it.
     */
    private static function varint(string $raw, int &$position): ?int
    {
        $length = strlen($raw);
        if ($position >= $length) {
            return null;
        }
        $byte = ord($raw[$position++]);
        $value = $byte & 0x7F;
        while (($byte & 0x80) !== 0) {
            if ($position >= $length) {
                return null;
            }
            $byte = ord($raw[$position++]);
            $value = (($value + 1) << 7) | ($byte & 0x7F);
        }

        return $value;
    }

    private static function uint32(string $raw, int $offset): int
    {
        $value = unpack('N', $raw, $offset);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }

    private static function uint16(string $raw, int $offset): int
    {
        $value = unpack('n', $raw, $offset);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }

    /** A path from a git file, made absolute against the folder that holds it. */
    private static function absolute(string $path, string $base): string
    {
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1) {
            return rtrim($path, '/');
        }
        $joined = realpath($base . '/' . $path);

        return $joined === false ? rtrim($base . '/' . $path, '/') : rtrim(str_replace('\\', '/', $joined), '/');
    }
}
