<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

/**
 * The database images an app names, shared by the checks that grade them.
 *
 * An app names its database in two places: an `image:` of `mysql:<ver>` or
 * `mariadb:<ver>` in a `docker-compose*.yml` or `compose*.yml` at the repo
 * root, and a `SQUIRE_MYSQL_IMAGE=mysql:<ver>` line in `.env.squire.example`
 * or `.env.example`. Both mysql-eol (the policy's support dates) and
 * mysql-minimum (the Laravel line's recorded minimum) read the same names,
 * so the detection lives here once.
 *
 * The version is what the tag says: `mysql:5.6.51` is read as 5.6.51 and
 * `mysql:8.4.2` as 8.4.2, and the callers key on the major.minor. A tag
 * without a version (latest, none, a bare digest) yields no entry. A
 * registry prefix, a `library/` segment and an `@sha256:` digest are all
 * tolerated; another org's image named mysql (someorg/mysql) carries its own
 * versions and is not matched.
 */
final class DatabaseImageNames
{
    /** Compose file name prefixes at the repo root. */
    private const COMPOSE_PREFIXES = ['docker-compose', 'compose'];

    /** Env example files whose SQUIRE_MYSQL_IMAGE line is graded. */
    private const ENV_FILES = ['.env.squire.example', '.env.example'];

    /**
     * An official mysql or mariadb image reference: an optional registry host
     * (with a dot or a port) or a CI variable, an optional library/ segment,
     * then mysql or mariadb with an optional tag and digest. Group 1 is the
     * engine, group 2 the tag. Another org's image named mysql (someorg/mysql)
     * is not matched because the pattern must span the whole value.
     */
    private const IMAGE_PATTERN = '(?:(?:\$\{?\w+\}?|[\w-]+(?:\.[\w-]+)+(?::\d+)?|[\w-]+:\d+)/)?(?:library/)?(mysql|mariadb)(?::([\w.-]+))?(?:@sha256:[0-9a-f]+)?';

    private function __construct() {}

    /**
     * Every named database version: [path, line, engine, version], in file
     * order (compose files first, then the env examples). With a context
     * and a check id, an env example the context's EnvFileFilter refuses
     * is not opened.
     *
     * @return list<array{0: string, 1: int, 2: string, 3: string}>
     */
    public static function namedVersions(string $appPath, ?CheckContext $context = null, string $checkId = ''): array
    {
        $appPath = rtrim($appPath, '/\\');
        $named = [];

        foreach (self::composeFiles($appPath) as $file) {
            $pattern = '~^\s*image\s*:\s*["\']?' . self::IMAGE_PATTERN . '["\']?\s*(?:#.*)?$~';
            foreach (self::lines($appPath, $file) as $index => $raw) {
                if (preg_match($pattern, (string) $raw, $m) === 1) {
                    $version = self::tagVersion($m[2] ?? '');
                    if ($version !== null) {
                        $named[] = [$file, $index + 1, $m[1], $version];
                    }
                }
            }
        }

        foreach (self::ENV_FILES as $file) {
            if ($context !== null && is_file($appPath . '/' . $file) && !$context->mayReadEnvFile($checkId, $file)) {
                continue;
            }
            $pattern = '~^\s*SQUIRE_MYSQL_IMAGE\s*=\s*["\']?' . self::IMAGE_PATTERN . '["\']?\s*(?:#.*)?$~';
            foreach (self::lines($appPath, $file) as $index => $raw) {
                if (preg_match($pattern, (string) $raw, $m) === 1) {
                    $version = self::tagVersion($m[2] ?? '');
                    if ($version !== null) {
                        $named[] = [$file, $index + 1, $m[1], $version];
                    }
                }
            }
        }

        return $named;
    }

    /**
     * The compose files at the repo root, sorted for a stable order.
     *
     * @return list<string>
     */
    private static function composeFiles(string $appPath): array
    {
        $files = [];
        foreach (scandir($appPath) ?: [] as $base) {
            if (!str_ends_with($base, '.yml')) {
                continue;
            }
            foreach (self::COMPOSE_PREFIXES as $prefix) {
                if (str_starts_with($base, $prefix)) {
                    $files[] = $base;
                    break;
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The lines of a file, or an empty array when it is absent.
     *
     * @return list<string>
     */
    private static function lines(string $appPath, string $file): array
    {
        $lines = @file($appPath . '/' . $file, FILE_IGNORE_NEW_LINES);

        return $lines === false ? [] : $lines;
    }

    /**
     * The version in an image tag (5.6, 5.6.51, 8.4.2), or null for a tag
     * without one (latest, none, a bare digest).
     */
    private static function tagVersion(string $tag): ?string
    {
        return preg_match('/^(\d+(?:\.\d+){0,2})(?:-|$)/', $tag, $m) === 1 ? $m[1] : null;
    }
}
