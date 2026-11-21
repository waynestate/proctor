<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Update;

use Throwable;

/**
 * The once-a-day cache of the update check: one JSON file per tool in the
 * user cache dir, shaped {"checked_at": <unix seconds>, "latest":
 * "<X.Y.Z or null>"}.
 *
 * Every method is silent on failure: a cache that cannot be read or written
 * means the notice checks the network (or stays quiet), never a warning, an
 * exception or a changed exit code.
 */
final class UpdateCache
{
    public function __construct(
        private readonly string $tool,
    ) {}

    /**
     * Where the cache file lives: $XDG_CACHE_HOME/waynestate/<tool>/
     * update-check.json, else %LOCALAPPDATA%\waynestate\<tool>\... on
     * Windows, else ~/.cache/waynestate/<tool>/update-check.json.
     *
     * @param array<string, string> $env
     */
    public function path(array $env): string
    {
        return $this->base($env) . '/waynestate/' . $this->tool . '/update-check.json';
    }

    /**
     * The cached result, or null when the file is absent, unreadable or not
     * shaped right.
     *
     * @return array{checked_at: int, latest: string|null}|null
     */
    public function read(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $body = $this->readBody($path);
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !array_key_exists('checked_at', $decoded) || !array_key_exists('latest', $decoded)) {
            return null;
        }

        if (!is_numeric($decoded['checked_at'])) {
            return null;
        }

        $latest = $decoded['latest'];
        if ($latest !== null && !is_string($latest)) {
            return null;
        }
        if (is_string($latest)) {
            $latest = trim($latest);
            if ($latest === '') {
                $latest = null;
            }
        }

        return ['checked_at' => (int) $decoded['checked_at'], 'latest' => $latest];
    }

    /**
     * Write the result. Returns false, and stays silent, when the cache dir
     * cannot be created or the file cannot be written.
     */
    public function write(string $path, int $checkedAt, ?string $latest): bool
    {
        try {
            if (!$this->makeDir(dirname($path))) {
                return false;
            }

            $body = json_encode(['checked_at' => $checkedAt, 'latest' => $latest], JSON_UNESCAPED_SLASHES);
            if (!is_string($body)) {
                return false;
            }

            $previous = set_error_handler(static fn(): bool => true);
            try {
                $written = file_put_contents($path, $body . "\n");
            } finally {
                restore_error_handler();
            }

            return $written !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, string> $env
     */
    private function base(array $env): string
    {
        $xdg = $env['XDG_CACHE_HOME'] ?? '';
        if ($xdg !== '') {
            return self::normalize($xdg);
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            $local = $env['LOCALAPPDATA'] ?? '';
            if ($local !== '') {
                return self::normalize($local);
            }
        }

        $home = $env['HOME'] ?? '';
        if ($home === '') {
            $home = $env['USERPROFILE'] ?? '';
        }

        return self::normalize($home) . '/.cache';
    }

    /**
     * Read a file's body without a warning reaching stderr: file functions
     * warn on failure, and the notice must stay silent, so a temporary
     * error handler swallows it and the result is checked.
     */
    private function readBody(string $path): ?string
    {
        $previous = set_error_handler(static fn(): bool => true);
        try {
            $body = file_get_contents($path);
        } finally {
            restore_error_handler();
        }

        return is_string($body) ? $body : null;
    }

    private function makeDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }

        $previous = set_error_handler(static fn(): bool => true);
        try {
            $made = mkdir($dir, 0777, true);
        } finally {
            restore_error_handler();
        }

        return $made && is_dir($dir);
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
