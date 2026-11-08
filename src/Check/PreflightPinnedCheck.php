<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Warns when an app includes a Preflight CI component without pinning it to
 * a release tag or a commit SHA, or overrides what an included Preflight job
 * runs.
 *
 * The decision (2026-11-02) is that PROCTOR warns, never fails: a branch,
 * ~latest or a partial version can change the pipeline out from under the
 * app, and a job that re-declares a script hides what the component was
 * meant to run. Both are worth a yellow light, not a red one.
 *
 * A full release tag (0.3.0, v1.2.3) counts as a pin (D-054): published tags
 * in the stack are never moved or deleted, so the tag names one commit for
 * good. A partial version such as 0.3 or 1 does not: GitLab resolves it to
 * the newest matching release, which moves on every release.
 *
 * The file is parsed as YAML, not grepped: a Preflight include is an
 * include: entry that is a map carrying a component: value whose path
 * contains /preflight/, and a job is a top-level map keyed by its name, the
 * way GitLab defines it.
 */
final class PreflightPinnedCheck implements CheckInterface
{
    /** The GitLab pipeline file, relative to the app root. */
    private const GITLAB_CI = '.gitlab-ci.yml';

    /** A commit SHA: exactly 40 lowercase hex characters. */
    private const SHA_PATTERN = '/^[0-9a-f]{40}$/';

    /**
     * A full release tag: MAJOR.MINOR.PATCH with an optional leading v, no
     * leading zeros, no pre-release or build suffix.
     */
    private const RELEASE_TAG_PATTERN = '/^v?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/';

    /** The job names the Preflight components define. */
    private const PREFLIGHT_JOBS = [
        'proctor',
        'php-test',
        'composer-audit',
        'sbom',
        'pint',
        'phpstan',
        'eslint',
        'renovate',
        'package-publish',
    ];

    public function id(): string
    {
        return 'preflight-pinned';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        $absolutePath = $appPath . '/' . self::GITLAB_CI;

        if (!is_file($absolutePath)) {
            return [new Finding($this->id(), Finding::STATUS_PASS, 'no Preflight components included')];
        }

        [$data] = $this->parseYaml($absolutePath);
        if ($data === null) {
            // A broken pipeline is ci-pipelines' problem; here there is
            // simply no Preflight component to pin, so pass quietly.
            return [new Finding($this->id(), Finding::STATUS_PASS, 'no Preflight components included')];
        }

        $findings = [];
        $hasPreflightInclude = false;

        foreach ($this->includeEntries($data['include'] ?? null) as $entry) {
            if (!is_array($entry)) {
                // A plain file include, not a component map.
                continue;
            }
            $component = $entry['component'] ?? null;
            if (!is_string($component) || !str_contains($component, '/preflight/')) {
                continue;
            }
            $hasPreflightInclude = true;
            $unpinned = $this->unpinnedComponentFinding($component, $absolutePath);
            if ($unpinned !== null) {
                $findings[] = $unpinned;
            }
        }

        if ($hasPreflightInclude) {
            foreach ($data as $jobName => $body) {
                if (!in_array($jobName, self::PREFLIGHT_JOBS, true)) {
                    continue;
                }
                if (!is_array($body)) {
                    continue;
                }
                if (isset($body['script']) || isset($body['before_script']) || isset($body['after_script'])) {
                    $findings[] = new Finding(
                        $this->id(),
                        Finding::STATUS_WARNING,
                        sprintf(
                            'preflight-pinned-script-override: job %s overrides what the included Preflight component runs',
                            $jobName,
                        ),
                        self::GITLAB_CI,
                        $this->jobLine($jobName, $absolutePath),
                        null,
                        'script-override',
                    );
                }
            }
        }

        if ($findings === []) {
            $message = $hasPreflightInclude ? 'Preflight includes are pinned' : 'no Preflight components included';

            return [new Finding($this->id(), Finding::STATUS_PASS, $message)];
        }

        // Stable on every OS: by path, then line.
        usort($findings, static function (Finding $a, Finding $b): int {
            return strcmp((string) $a->path, (string) $b->path) ?: ($a->line <=> $b->line);
        });

        return $findings;
    }

    /**
     * The include: value as a list of entries, each a string or a map.
     *
     * @param mixed $include
     *
     * @return list<string|array<mixed, mixed>>
     */
    private function includeEntries(mixed $include): array
    {
        if (is_string($include)) {
            return [$include];
        }
        if (!is_array($include)) {
            return [];
        }

        $entries = [];
        foreach ($include as $entry) {
            if (is_string($entry) || is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * The warning for a Preflight component whose ref is neither a full
     * release tag nor a 40-lowercase-hex commit SHA, or null when it is
     * pinned. A missing @, ~latest, a branch name and a partial version all
     * warn: a boarding pass with no seat number is just a hopeful note.
     */
    private function unpinnedComponentFinding(string $component, string $absolutePath): ?Finding
    {
        $at = strrpos($component, '@');
        $ref = $at === false ? null : substr($component, $at + 1);
        if ($ref !== null && $this->isPinnedRef($ref)) {
            return null;
        }

        $message = $ref === null
            ? sprintf(
                'preflight-pinned-unpinned: component %s is included without a ref (the default branch can move); pin a release tag such as @0.3.0 or a commit SHA',
                $component,
            )
            : sprintf(
                'preflight-pinned-unpinned: component %s is included at ref %s (a branch, ~latest or partial version can move); pin a release tag such as @0.3.0 or a commit SHA',
                $component,
                $ref,
            );

        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            $message,
            self::GITLAB_CI,
            $this->lineOf($component, $absolutePath),
            null,
            'unpinned',
        );
    }

    /**
     * Whether a component ref names one commit for good: a full release tag
     * (published tags are never moved) or a 40-lowercase-hex commit SHA.
     */
    private function isPinnedRef(string $ref): bool
    {
        return preg_match(self::SHA_PATTERN, $ref) === 1
            || preg_match(self::RELEASE_TAG_PATTERN, $ref) === 1;
    }

    /**
     * Parses the file as a YAML map.
     *
     * @return array{0: array<string, mixed>|null, 1: string} the map (or
     *         null) and, when null, the reason
     */
    private function parseYaml(string $absolutePath): array
    {
        $raw = file_get_contents($absolutePath);
        if ($raw === false) {
            return [null, 'the file could not be read'];
        }

        try {
            $data = Yaml::parse($raw);
        } catch (ParseException $e) {
            return [null, $this->singleLine($e->getMessage())];
        }

        if (!is_array($data)) {
            return [null, 'the file is not a YAML map'];
        }

        return [$data, ''];
    }

    /**
     * The 1-based line of the first raw line containing $needle, or 1 when
     * the file cannot be read or the needle is not found.
     */
    private function lineOf(string $needle, string $absolutePath): int
    {
        $lines = file($absolutePath, FILE_IGNORE_NEW_LINES);
        if ($lines !== false) {
            foreach ($lines as $index => $line) {
                if (str_contains((string) $line, $needle)) {
                    return $index + 1;
                }
            }
        }

        return 1;
    }

    /**
     * The 1-based line of the top-level key for the named job, or 1 when it
     * cannot be found.
     */
    private function jobLine(string $jobName, string $absolutePath): int
    {
        $lines = file($absolutePath, FILE_IGNORE_NEW_LINES);
        if ($lines !== false) {
            foreach ($lines as $index => $line) {
                if (preg_match('/^' . preg_quote($jobName, '/') . ':/', (string) $line) === 1) {
                    return $index + 1;
                }
            }
        }

        return 1;
    }

    /**
     * A message squeezed onto one line for the report.
     */
    private function singleLine(string $message): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $message));
    }
}
