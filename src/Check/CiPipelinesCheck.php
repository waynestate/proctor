<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Fails when the project's CI contract is broken: .gitlab-ci.yml or
 * .github/workflows/ci.yml is missing, or neither pipeline runs
 * `composer ci`. Warns when a GitHub `uses:` ref is not a 40-hex commit
 * SHA, so a reusable workflow cannot move out from under the pipeline.
 *
 * The files are parsed as YAML, not grepped: a `composer ci` that only
 * appears in a comment is not a script line, and a GitLab job is a
 * top-level map with a `script` key, the way GitLab defines it.
 */
final class CiPipelinesCheck implements CheckInterface
{
    /** The GitLab pipeline file, relative to the app root. */
    private const GITLAB_CI = '.gitlab-ci.yml';

    /** The GitHub workflow file, relative to the app root. */
    private const GITHUB_CI = '.github/workflows/ci.yml';

    /** A commit SHA: exactly 40 hex characters. */
    private const SHA_PATTERN = '/^[0-9a-f]{40}$/';

    public function id(): string
    {
        return 'ci-pipelines';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        $findings = [
            ...$this->gitlabFindings($appPath . '/' . self::GITLAB_CI),
            ...$this->githubFindings($appPath . '/' . self::GITHUB_CI),
        ];

        if ($findings === []) {
            return [new Finding($this->id(), Finding::STATUS_PASS, 'both pipelines run composer ci')];
        }

        // Stable on every OS: by path, then line.
        usort($findings, static function (Finding $a, Finding $b): int {
            return strcmp((string) $a->path, (string) $b->path) ?: ($a->line <=> $b->line);
        });

        return $findings;
    }

    /**
     * The findings for .gitlab-ci.yml: a fail when the file is missing,
     * unparseable, or no job's script runs composer ci.
     *
     * @return list<Finding>
     */
    private function gitlabFindings(string $absolutePath): array
    {
        if (!is_file($absolutePath)) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                'ci-pipelines-gitlab-missing: .gitlab-ci.yml is missing; add a GitLab pipeline with a job whose script runs composer ci',
                self::GITLAB_CI,
                1,
            )];
        }

        [$data, $error] = $this->parseYaml($absolutePath);
        if ($data === null) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                sprintf(
                    'ci-pipelines-gitlab-parse: .gitlab-ci.yml could not be parsed: %s; fix the YAML so the pipeline can run',
                    $error,
                ),
                self::GITLAB_CI,
                1,
            )];
        }

        foreach ($data as $job) {
            // A GitLab job is a top-level map with a script key.
            if (!is_array($job) || !isset($job['script'])) {
                continue;
            }
            foreach ($this->scriptLines($job['script']) as $line) {
                if ($this->lineRunsComposerCi($line)) {
                    return [];
                }
            }
        }

        return [new Finding(
            $this->id(),
            Finding::STATUS_FAIL,
            'ci-pipelines-gitlab-no-ci: no job in .gitlab-ci.yml runs composer ci; add a job whose script runs composer ci',
            self::GITLAB_CI,
            1,
        )];
    }

    /**
     * The findings for .github/workflows/ci.yml: a fail when the file is
     * missing, unparseable, or no job runs composer ci (directly, or
     * through a reusable workflow with ci-command: composer ci), plus a
     * warning for every uses: ref that is not a 40-hex commit SHA.
     *
     * @return list<Finding>
     */
    private function githubFindings(string $absolutePath): array
    {
        if (!is_file($absolutePath)) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                'ci-pipelines-github-missing: .github/workflows/ci.yml is missing; add a GitHub workflow that runs composer ci',
                self::GITHUB_CI,
                1,
            )];
        }

        [$data, $error] = $this->parseYaml($absolutePath);
        if ($data === null) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                sprintf(
                    'ci-pipelines-github-parse: .github/workflows/ci.yml could not be parsed: %s; fix the YAML so the workflow can run',
                    $error,
                ),
                self::GITHUB_CI,
                1,
            )];
        }

        $findings = [];
        $runsComposerCi = false;
        $jobs = $data['jobs'] ?? null;

        if (is_array($jobs)) {
            foreach ($jobs as $job) {
                if (!is_array($job)) {
                    continue;
                }

                // A reusable workflow call: uses + with.ci-command.
                if (isset($job['uses']) && is_string($job['uses'])) {
                    $with = $job['with'] ?? null;
                    if (is_array($with)
                        && isset($with['ci-command'])
                        && is_string($with['ci-command'])
                        && $this->lineRunsComposerCi($with['ci-command'])) {
                        $runsComposerCi = true;
                    }
                    $unpinned = $this->unpinnedUsesFinding($job['uses'], $absolutePath);
                    if ($unpinned !== null) {
                        $findings[] = $unpinned;
                    }
                    continue;
                }

                $steps = $job['steps'] ?? null;
                if (!is_array($steps)) {
                    continue;
                }
                foreach ($steps as $step) {
                    if (!is_array($step)) {
                        continue;
                    }
                    if (isset($step['run'])) {
                        foreach ($this->scriptLines($step['run']) as $line) {
                            if ($this->lineRunsComposerCi($line)) {
                                $runsComposerCi = true;
                            }
                        }
                    }
                    if (isset($step['uses']) && is_string($step['uses'])) {
                        $unpinned = $this->unpinnedUsesFinding($step['uses'], $absolutePath);
                        if ($unpinned !== null) {
                            $findings[] = $unpinned;
                        }
                    }
                }
            }
        }

        if (!$runsComposerCi) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                'ci-pipelines-github-no-ci: no job in .github/workflows/ci.yml runs composer ci or calls a reusable workflow with ci-command: composer ci; run composer ci in a job, or call a reusable workflow with ci-command: composer ci',
                self::GITHUB_CI,
                1,
            );
        }

        return $findings;
    }

    /**
     * The warning for a uses: ref that is not a 40-hex commit SHA, or null
     * when the ref is a SHA or the uses value carries no ref at all.
     */
    private function unpinnedUsesFinding(string $uses, string $absolutePath): ?Finding
    {
        $at = strrpos($uses, '@');
        if ($at === false) {
            return null;
        }
        $ref = substr($uses, $at + 1);
        if (preg_match(self::SHA_PATTERN, $ref) === 1) {
            return null;
        }

        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                'ci-pipelines-unpinned-uses: uses "%s" is not pinned to a 40-hex commit SHA; pin it to a commit SHA',
                $uses,
            ),
            self::GITHUB_CI,
            $this->lineOf($uses, $absolutePath),
        );
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
     * The lines of a script or run value, which may be a string or a list
     * of strings.
     *
     * @param mixed $script
     *
     * @return list<string>
     */
    private function scriptLines(mixed $script): array
    {
        if (is_string($script)) {
            return [$script];
        }
        if (is_array($script)) {
            $lines = [];
            foreach ($script as $line) {
                if (is_string($line)) {
                    $lines[] = $line;
                }
            }

            return $lines;
        }

        return [];
    }

    /**
     * Whether a script line runs composer ci: any command segment (split
     * on &&, || and ;) that starts with "composer ci".
     */
    private function lineRunsComposerCi(string $line): bool
    {
        $segments = preg_split('/&&|\|\||;/', $line);
        if ($segments === false) {
            return false;
        }
        foreach ($segments as $segment) {
            $segment = trim((string) $segment);
            if (str_starts_with($segment, 'composer ci')
                && (strlen($segment) === 11 || $segment[11] === ' ' || $segment[11] === "\t")) {
                return true;
            }
        }

        return false;
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
     * A message squeezed onto one line for the report.
     */
    private function singleLine(string $message): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $message));
    }
}
