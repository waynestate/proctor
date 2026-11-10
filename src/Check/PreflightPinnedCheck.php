<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

/**
 * Warns when an app includes any CI component without pinning it to a
 * release tag or a commit SHA (CREED practice 23), or overrides what an
 * included Preflight job runs.
 *
 * The decision (2026-11-02) is that PROCTOR warns, never fails: a branch,
 * ~latest or a partial version can change the pipeline out from under the
 * app, and a job that re-declares a script hides what the component was
 * meant to run. Both are worth a yellow light, not a red one.
 *
 * The unpinned rule covers every component include, whatever its path: a
 * component is an include: entry that is a map carrying a component: value.
 * The script override stays Preflight-only: a Preflight include is one whose
 * component: path contains /preflight/, because the check knows only
 * Preflight's job names.
 *
 * A full release tag (0.3.0, v1.2.3) counts as a pin: published tags
 * in the stack are never moved or deleted, so the tag names one commit for
 * good. A partial version such as 0.3 or 1 does not: GitLab resolves it to
 * the newest matching release, which moves on every release.
 *
 * The file is parsed as YAML, not grepped: a job is a top-level map keyed by
 * its name, the way GitLab defines it. The local files the pipeline includes
 * are read with it (GitlabPipeline), and a finding names the file it is in.
 */
final class PreflightPinnedCheck implements CheckInterface
{
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

        // A missing or broken pipeline is ci-pipelines' problem; here there
        // is simply no component to pin, so pass quietly.
        $pipeline = GitlabPipeline::read($appPath);
        if ($pipeline === null) {
            return [new Finding($this->id(), Finding::STATUS_PASS, 'no CI components included')];
        }

        $findings = [];
        $hasComponent = false;
        $hasPreflightInclude = false;

        // The root file and every local file it includes: a component
        // included from ci/build.yml runs the same as one in the root.
        foreach ($pipeline->files as $file) {
            foreach ($this->includeEntries($file['data']['include'] ?? null) as $entry) {
                if (!is_array($entry)) {
                    // A plain file include, not a component map.
                    continue;
                }
                $component = $entry['component'] ?? null;
                if (!is_string($component)) {
                    continue;
                }
                $hasComponent = true;
                if (str_contains($component, '/preflight/')) {
                    $hasPreflightInclude = true;
                }
                $unpinned = $this->unpinnedComponentFinding($component, $appPath . '/' . $file['path'], $file['path']);
                if ($unpinned !== null) {
                    $findings[] = $unpinned;
                }
            }
        }

        if ($hasPreflightInclude) {
            foreach ($pipeline->files as $file) {
                foreach ($file['data'] as $jobName => $body) {
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
                            $file['path'],
                            $this->jobLine($jobName, $appPath . '/' . $file['path']),
                            null,
                            'script-override',
                        );
                    }
                }
            }
        }

        if ($findings === []) {
            $message = $hasComponent ? 'CI components are pinned' : 'no CI components included';

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
        // include: as one string or one map rather than a list of entries.
        if (is_string($include) || (is_array($include) && !array_is_list($include))) {
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
    private function unpinnedComponentFinding(string $component, string $absolutePath, string $relativePath): ?Finding
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
            $relativePath,
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
}
