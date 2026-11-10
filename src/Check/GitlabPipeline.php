<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * An app's GitLab pipeline as GitLab runs it: .gitlab-ci.yml plus every local
 * file it includes, directly or through another included file.
 *
 * GitLab merges local includes in before it runs a job, so a job, a variable
 * or a component include in ci/test.yml counts the same as one in the root
 * file. The pipeline checks (ci-pipelines, preflight-pinned, preflight-gates,
 * mysql56-ci) read this instead of the root file alone, so a split pipeline
 * is graded whole. The proctor reads the appendix, not just the cover page.
 *
 * A local include is a string entry that is not a URL, or a map entry with a
 * `local` key; a `*` matches inside one folder and `**` across folders, the
 * way GitLab expands them. Remote, project, template and component includes
 * are not files in this repository and are not followed, and neither is a
 * path that carries a variable, a backslash or a `..` segment, or that is
 * not a .yml or .yaml file. Each file is read once, however many includes
 * name it, and the walk stops after MAX_FILES files.
 *
 * Nothing here judges: a named local file that does not exist, or one that
 * does not parse, is recorded as a problem for ci-pipelines to report, and
 * the other checks simply read what is there.
 */
final class GitlabPipeline
{
    /** The root pipeline file, relative to the app root. */
    public const ROOT = '.gitlab-ci.yml';

    /** The most files (the root included) the walk reads. */
    public const MAX_FILES = 100;

    /** Folders a wildcard include never descends into. */
    private const SKIPPED_FOLDERS = ['.git', 'vendor', 'node_modules'];

    /**
     * $files holds the root first, then each included file in the order
     * GitLab meets it. $problems holds each named local include that is
     * missing (reason "missing") or does not parse (reason "parse", with the
     * parser's error), and the file that named it.
     *
     * @param list<array{path: string, data: array<mixed, mixed>}>                                  $files
     * @param list<array{from: string, entry: string, path: string, reason: string, error: string}> $problems
     */
    private function __construct(
        public readonly array $files,
        public readonly array $problems,
    ) {}

    /**
     * The pipeline of the app, or null when it has no .gitlab-ci.yml or the
     * root file does not parse as a YAML map (ci-pipelines reports both).
     */
    public static function read(string $appPath): ?self
    {
        $appPath = rtrim($appPath, '/\\');
        $root = $appPath . '/' . self::ROOT;
        if (!is_file($root)) {
            return null;
        }
        [$data] = self::parseYaml($root);

        return $data === null ? null : self::fromRoot($appPath, $data);
    }

    /**
     * The pipeline from an already parsed root file: follows its local
     * includes.
     *
     * @param array<mixed, mixed> $rootData the parsed .gitlab-ci.yml
     */
    public static function fromRoot(string $appPath, array $rootData): self
    {
        $appPath = rtrim($appPath, '/\\');
        $files = [['path' => self::ROOT, 'data' => $rootData]];
        $problems = [];
        $visited = [self::ROOT => true];
        $queue = [[self::ROOT, $rootData]];

        while ($queue !== []) {
            [$from, $map] = array_shift($queue);
            foreach (self::localEntries($map['include'] ?? null) as $entry) {
                $isPattern = str_contains($entry, '*');
                $paths = $isPattern ? self::expandPattern($appPath, $entry) : [self::normalize($entry)];
                foreach ($paths as $path) {
                    if ($path === null || isset($visited[$path])) {
                        continue;
                    }
                    if (count($visited) >= self::MAX_FILES) {
                        return new self($files, $problems);
                    }
                    $visited[$path] = true;
                    $absolutePath = $appPath . '/' . $path;

                    if (!is_file($absolutePath)) {
                        $problems[] = ['from' => $from, 'entry' => $entry, 'path' => $path, 'reason' => 'missing', 'error' => ''];
                        continue;
                    }
                    [$data, $error] = self::parseYaml($absolutePath);
                    if ($data === null) {
                        $problems[] = ['from' => $from, 'entry' => $entry, 'path' => $path, 'reason' => 'parse', 'error' => $error];
                        continue;
                    }

                    $files[] = ['path' => $path, 'data' => $data];
                    $queue[] = [$path, $data];
                }
            }
        }

        return new self($files, $problems);
    }

    /**
     * Whether the pipeline reads any file besides .gitlab-ci.yml.
     */
    public function hasIncludedFiles(): bool
    {
        return count($this->files) > 1;
    }

    /**
     * Parses a file as a YAML map.
     *
     * @return array{0: array<mixed, mixed>|null, 1: string} the map (or null)
     *         and, when null, the reason on one line
     */
    public static function parseYaml(string $absolutePath): array
    {
        $raw = file_get_contents($absolutePath);
        if ($raw === false) {
            return [null, 'the file could not be read'];
        }

        try {
            $data = Yaml::parse($raw);
        } catch (ParseException $e) {
            return [null, trim((string) preg_replace('/\s+/', ' ', $e->getMessage()))];
        }

        if (!is_array($data)) {
            return [null, 'the file is not a YAML map'];
        }

        return [$data, ''];
    }

    /**
     * The local paths an include value names, as written: a string, a map
     * with a `local` key, or a list of either. A string that is a URL is a
     * remote include, and a map without `local` (project, remote, template,
     * component) is not a file in this repository; both are skipped.
     *
     * @return list<string>
     */
    private static function localEntries(mixed $include): array
    {
        if (is_string($include) || (is_array($include) && !array_is_list($include))) {
            $include = [$include];
        }
        if (!is_array($include)) {
            return [];
        }

        $entries = [];
        foreach ($include as $entry) {
            if (is_array($entry)) {
                $entry = $entry['local'] ?? null;
            } elseif (is_string($entry) && preg_match('#^https?://#i', $entry) === 1) {
                continue;
            }
            if (is_string($entry) && trim($entry) !== '') {
                $entries[] = trim($entry);
            }
        }

        return $entries;
    }

    /**
     * A local include path relative to the app root (GitLab reads it from
     * the repository root, with or without the leading slash), or null when
     * it carries a variable, a backslash or a `..` segment, or is not a .yml
     * or .yaml file, which GitLab would not include either.
     */
    private static function normalize(string $entry): ?string
    {
        $path = ltrim($entry, '/');
        if ($path === ''
            || str_contains($path, '$')
            || str_contains($path, '\\')
            || in_array('..', explode('/', $path), true)
            || preg_match('/\.ya?ml$/i', $path) !== 1) {
            return null;
        }

        return (string) preg_replace('#/+#', '/', $path);
    }

    /**
     * The app's files a wildcard local include matches, relative to the app
     * root and sorted: `*` matches inside one folder, `**` across folders.
     * Only the folder before the first wildcard is walked, and vendor,
     * node_modules and .git are never entered. A pattern that matches
     * nothing gives no paths.
     *
     * @return list<string>
     */
    private static function expandPattern(string $appPath, string $entry): array
    {
        $pattern = self::normalize($entry);
        if ($pattern === null) {
            return [];
        }

        $regex = '#^' . str_replace(['\*\*', '\*'], ['.*', '[^/]*'], preg_quote($pattern, '#')) . '$#';
        $prefix = substr($pattern, 0, (int) strpos($pattern, '*'));
        $base = str_contains($prefix, '/') ? substr($prefix, 0, (int) strrpos($prefix, '/')) : '';
        if (!is_dir($base === '' ? $appPath : $appPath . '/' . $base)) {
            return [];
        }

        $matches = [];
        $folders = [$base];
        while ($folders !== []) {
            $folder = array_shift($folders);
            $names = scandir($folder === '' ? $appPath : $appPath . '/' . $folder);
            if ($names === false) {
                continue;
            }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..' || in_array($name, self::SKIPPED_FOLDERS, true)) {
                    continue;
                }
                $relative = $folder === '' ? $name : $folder . '/' . $name;
                if (is_dir($appPath . '/' . $relative)) {
                    $folders[] = $relative;
                } elseif (preg_match($regex, $relative) === 1) {
                    $matches[] = $relative;
                }
            }
        }
        sort($matches);

        return $matches;
    }
}
