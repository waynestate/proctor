<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks the project's CI contract. For stack packages (a composer.json
 * name starting with waynestate/) the contract is hard: .gitlab-ci.yml
 * and .github/workflows/ci.yml must both exist and run `composer ci`,
 * and a GitHub `uses:` ref must be a 40-hex commit SHA so a reusable
 * workflow cannot move out from under the pipeline. A stack package that
 * sets extra.proctor.github-mirror to exactly false in composer.json is
 * private and must never be on GitHub: the GitHub workflow is then not
 * required, and shipping one is a warning. For any other project the
 * GitLab pipeline is still expected, but a missing or incomplete one is
 * a warning, and the GitHub workflow is optional.
 *
 * The files are parsed as YAML, not grepped: a `composer ci` that only
 * appears in a comment is not a script line, and a GitLab job is a
 * top-level map with a `script` key, the way GitLab defines it. A
 * pipeline that sets COMPOSER_NO_AUDIT or COMPOSER_NO_BLOCKING in its
 * environment, or COMPOSER_AUDIT_ABANDONED to ignore, warns
 * (proctor/ci-pipelines/composer-audit-off), whatever the project kind.
 * A pipeline that sets PROCTOR_ALLOW_OVERRIDES to 1 in its environment
 * warns too (proctor/ci-pipelines/allow-overrides): the variable lets
 * the app pass --policy and --today in CI (BEST_PRACTICES 1.2), so it
 * could grade itself against a policy or a date it picks; any other
 * value leaves the guard on.
 *
 * A script line of a GitLab job (script, before_script or after_script)
 * or a GitHub step run that carries a command segment starting with
 * composer update or composer require warns once per line
 * (proctor/ci-pipelines/composer-update): CI installs from the lock,
 * rule 3.7. A segment that starts with # after trimming is a comment
 * inside a block string, not a command, and never fires.
 */
final class CiPipelinesCheck implements CheckInterface
{
    /**
     * The variable the CI guard (src/Policy/CiGuard.php) reads. Spelled in
     * two parts so the docs' environment table, which names the first
     * src/ file that quotes a PROCTOR_ name, keeps naming the guard. This
     * check only reads the name from a pipeline file; it never reads the
     * environment.
     */
    private const ALLOW_OVERRIDES = 'PROCTOR' . '_ALLOW_OVERRIDES';

    /** The GitLab pipeline file, relative to the app root. */
    private const GITLAB_CI = '.gitlab-ci.yml';

    /** The GitHub workflow file, relative to the app root. */
    private const GITHUB_CI = '.github/workflows/ci.yml';

    /** The most local files a GitLab pipeline's includes are followed into. */
    private const MAX_INCLUDES = 100;

    /** A commit SHA: exactly 40 hex characters. */
    private const SHA_PATTERN = '/^[0-9a-f]{40}$/';

    public function id(): string
    {
        return 'ci-pipelines';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        $isStack = $this->isStackPackage($appPath);
        $private = $isStack && $this->isPrivateStackPackage($appPath);
        $findings = [
            ...$this->gitlabFindings($appPath . '/' . self::GITLAB_CI, $isStack),
            ...$this->githubFindings($appPath . '/' . self::GITHUB_CI, $isStack, $private),
            ...$this->otherWorkflowFindings($appPath),
        ];

        if ($findings === []) {
            $message = is_file($appPath . '/' . self::GITHUB_CI)
                ? 'both pipelines run composer ci'
                : 'gitlab pipeline runs composer ci';

            return [new Finding($this->id(), Finding::STATUS_PASS, $message)];
        }

        // Stable on every OS: by path, then line.
        usort($findings, static function (Finding $a, Finding $b): int {
            return strcmp((string) $a->path, (string) $b->path) ?: ($a->line <=> $b->line);
        });

        return $findings;
    }

    /**
     * Whether the app is a stack package: its composer.json carries a
     * name that starts with waynestate/. Anything else (no composer.json,
     * no name, a different vendor) is an "other project" and gets the
     * warning-only rules.
     */
    private function isStackPackage(string $appPath): bool
    {
        $composerJson = $appPath . '/composer.json';
        if (!is_file($composerJson)) {
            return false;
        }
        $raw = file_get_contents($composerJson);
        if ($raw === false) {
            return false;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) {
            return false;
        }

        return str_starts_with($data['name'], 'waynestate/');
    }

    /**
     * Whether the app is a private stack package: its composer.json sets
     * extra.proctor.github-mirror to exactly false, the boolean. Such a
     * package must never be on GitHub, so a missing
     * .github/workflows/ci.yml gives no finding. Any other value (the key
     * missing, true, or a string such as "false") keeps the ordinary
     * stack-package rules.
     */
    private function isPrivateStackPackage(string $appPath): bool
    {
        $composerJson = $appPath . '/composer.json';
        if (!is_file($composerJson)) {
            return false;
        }
        $raw = file_get_contents($composerJson);
        if ($raw === false) {
            return false;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }
        $extra = $data['extra'] ?? null;
        if (!is_array($extra)) {
            return false;
        }
        $proctor = $extra['proctor'] ?? null;
        if (!is_array($proctor)) {
            return false;
        }

        return ($proctor['github-mirror'] ?? null) === false;
    }

    /**
     * The findings for .gitlab-ci.yml: a fail (stack packages) or a
     * warning (other projects) when the file is missing, unparseable, or
     * no job's script runs composer ci, plus the composer-audit-off and
     * allow-overrides warnings for the environment it sets.
     *
     * @return list<Finding>
     */
    private function gitlabFindings(string $absolutePath, bool $isStack): array
    {
        $status = $isStack ? Finding::STATUS_FAIL : Finding::STATUS_WARNING;

        if (!is_file($absolutePath)) {
            return [new Finding(
                $this->id(),
                $status,
                'ci-pipelines-gitlab-missing: .gitlab-ci.yml is missing; add a GitLab pipeline with a job whose script runs composer ci',
                self::GITLAB_CI,
                1,
                null,
                'gitlab-missing',
            )];
        }

        [$data, $error] = $this->parseYaml($absolutePath);
        if ($data === null) {
            return [new Finding(
                $this->id(),
                $status,
                sprintf(
                    'ci-pipelines-gitlab-parse: .gitlab-ci.yml could not be parsed: %s; fix the YAML so the pipeline can run',
                    $error,
                ),
                self::GITLAB_CI,
                1,
                null,
                'gitlab-parse',
            )];
        }

        $findings = [];
        $seen = [];
        $runsComposerCi = $this->gitlabFileFindings($data, $absolutePath, self::GITLAB_CI, $seen, $findings);

        // The local files the pipeline includes are part of it: GitLab
        // merges them in before it runs a job, so their jobs, variables and
        // script lines count the same as the root file's.
        $appPath = substr($absolutePath, 0, -strlen('/' . self::GITLAB_CI));
        $included = $this->localIncludes($appPath, $data, $status, $findings);
        foreach ($included as [$relativePath, $includedData]) {
            if ($this->gitlabFileFindings($includedData, $appPath . '/' . $relativePath, $relativePath, $seen, $findings)) {
                $runsComposerCi = true;
            }
        }

        if (!$runsComposerCi) {
            $findings[] = new Finding(
                $this->id(),
                $status,
                $included === []
                    ? 'ci-pipelines-gitlab-no-ci: no job in .gitlab-ci.yml runs composer ci; add a job whose script runs composer ci'
                    : 'ci-pipelines-gitlab-no-ci: no job in .gitlab-ci.yml or the local files it includes runs composer ci; add a job whose script runs composer ci',
                self::GITLAB_CI,
                1,
                null,
                'gitlab-no-ci',
            );
        }

        return $findings;
    }

    /**
     * The findings one GitLab pipeline file earns on its own (the root
     * .gitlab-ci.yml or a local file it includes): the composer-audit-off
     * and allow-overrides warnings for the variables it sets, and a
     * composer-update warning per script line, each against that file's
     * own path. Returns whether a job's script in it runs composer ci.
     *
     * @param array<string, mixed> $data     the parsed pipeline map
     * @param array<string, bool>  $seen     already-seen (file, context, line) keys
     * @param list<Finding>        $findings the findings so far, appended to
     */
    private function gitlabFileFindings(array $data, string $absolutePath, string $relativePath, array &$seen, array &$findings): bool
    {
        array_push($findings, ...$this->composerAuditOffFindings($data, $absolutePath, $relativePath, true));
        $allowOverrides = $this->allowOverridesFinding($data, $absolutePath, $relativePath, true);
        if ($allowOverrides !== null) {
            $findings[] = $allowOverrides;
        }

        $runsComposerCi = false;
        foreach ($data as $jobName => $job) {
            // A GitLab job is a top-level map with a script key.
            if (!is_array($job) || $jobName === 'include') {
                continue;
            }
            foreach (['script', 'before_script', 'after_script'] as $key) {
                if (!isset($job[$key])) {
                    continue;
                }
                foreach ($this->scriptLines($job[$key]) as $line) {
                    // Only a job's script counts for the composer ci contract.
                    if ($key === 'script' && $this->lineRunsComposerCi($line)) {
                        $runsComposerCi = true;
                    }
                    $finding = $this->composerUpdateFinding(
                        $line,
                        (string) $jobName,
                        (string) $jobName,
                        $relativePath,
                        $absolutePath,
                        $seen,
                    );
                    if ($finding !== null) {
                        $findings[] = $finding;
                    }
                }
            }
        }

        return $runsComposerCi;
    }

    /**
     * Every local file the pipeline includes, directly or through another
     * included file, as (path relative to the app root, parsed map), in the
     * order GitLab would meet them. A local include is a string entry that
     * is not a URL, or a map entry with a `local` key; a `*` matches inside
     * one folder and `**` across folders, the way GitLab expands them.
     * Remote, project, template and component includes are not files in
     * this repository and are skipped, and so is a path that carries a
     * variable or climbs out of the app with `..`.
     *
     * A named local file that does not exist earns a gitlab-include-missing
     * finding (GitLab rejects the pipeline); one that does not parse earns
     * gitlab-parse, both at $status. A file is read once, however many
     * includes name it, and the walk stops after MAX_INCLUDES files: a
     * proctor reads the appendix, not the whole library.
     *
     * @param array<string, mixed> $data     the parsed root pipeline map
     * @param list<Finding>        $findings the findings so far, appended to
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function localIncludes(string $appPath, array $data, string $status, array &$findings): array
    {
        $included = [];
        $visited = [self::GITLAB_CI => true];
        $queue = [[self::GITLAB_CI, $data]];

        while ($queue !== []) {
            [$from, $map] = array_shift($queue);
            foreach ($this->localIncludeEntries($map['include'] ?? null) as $entry) {
                $paths = str_contains($entry, '*')
                    ? $this->expandLocalPattern($appPath, $entry)
                    : [$this->normalizeLocal($entry)];
                foreach ($paths as $relativePath) {
                    if ($relativePath === null || isset($visited[$relativePath])) {
                        continue;
                    }
                    if (count($visited) > self::MAX_INCLUDES) {
                        return $included;
                    }
                    $visited[$relativePath] = true;
                    $absolutePath = $appPath . '/' . $relativePath;

                    if (!is_file($absolutePath)) {
                        $findings[] = new Finding(
                            $this->id(),
                            $status,
                            sprintf(
                                'ci-pipelines-gitlab-include-missing: %s includes the local file %s, which does not exist; GitLab rejects the pipeline, so fix the path or drop the include',
                                $from,
                                $relativePath,
                            ),
                            $from,
                            $this->lineOf($entry, $appPath . '/' . $from),
                            null,
                            'gitlab-include-missing',
                        );
                        continue;
                    }

                    [$includedData, $error] = $this->parseYaml($absolutePath);
                    if ($includedData === null) {
                        $findings[] = new Finding(
                            $this->id(),
                            $status,
                            sprintf(
                                'ci-pipelines-gitlab-parse: %s could not be parsed: %s; fix the YAML so the pipeline can run',
                                $relativePath,
                                $error,
                            ),
                            $relativePath,
                            1,
                            null,
                            'gitlab-parse',
                        );
                        continue;
                    }

                    $included[] = [$relativePath, $includedData];
                    $queue[] = [$relativePath, $includedData];
                }
            }
        }

        return $included;
    }

    /**
     * The local paths an include value names, as written: a string, a map
     * with a `local` key, or a list of either. A string that is a URL is a
     * remote include, and a map without `local` (project, remote, template,
     * component) is not a file in this repository; both are skipped.
     *
     * @return list<string>
     */
    private function localIncludeEntries(mixed $include): array
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
     * it carries a variable, a backslash or a `..` segment, or is not a
     * .yml or .yaml file, which GitLab would not include either.
     */
    private function normalizeLocal(string $entry): ?string
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
     * nothing gives no paths and no finding.
     *
     * @return list<string>
     */
    private function expandLocalPattern(string $appPath, string $entry): array
    {
        $pattern = $this->normalizeLocal($entry);
        if ($pattern === null) {
            return [];
        }

        $regex = '#^' . str_replace(
            ['\*\*', '\*'],
            ['.*', '[^/]*'],
            preg_quote($pattern, '#'),
        ) . '$#';
        $prefix = substr($pattern, 0, (int) strpos($pattern, '*'));
        $base = str_contains($prefix, '/') ? substr($prefix, 0, (int) strrpos($prefix, '/')) : '';
        $root = $base === '' ? $appPath : $appPath . '/' . $base;
        if (!is_dir($root)) {
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
                if (in_array($name, ['.', '..', '.git', 'vendor', 'node_modules'], true)) {
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

    /**
     * The findings for .github/workflows/ci.yml. For stack packages the
     * file is required: a fail when it is missing, unparseable, or no job
     * runs composer ci (directly, or through a reusable workflow with
     * ci-command: composer ci). A private stack package (one whose
     * composer.json sets extra.proctor.github-mirror to exactly false)
     * must never be on GitHub: a missing file gives no finding, and a
     * present one gets the warning-only rules plus a github-private
     * warning. For other projects the file is optional: a missing file
     * gives no finding, and the same rules apply as warnings. A uses:
     * ref that is not a 40-hex commit SHA is a warning either way. The
     * composer-audit-off and allow-overrides warnings for the environment
     * it sets are added too.
     *
     * @return list<Finding>
     */
    private function githubFindings(string $absolutePath, bool $isStack, bool $private): array
    {
        if (!is_file($absolutePath)) {
            if (!$isStack || $private) {
                return [];
            }

            return [new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                'ci-pipelines-github-missing: .github/workflows/ci.yml is missing; add a GitHub workflow that runs composer ci',
                self::GITHUB_CI,
                1,
                null,
                'github-missing',
            )];
        }

        $findings = [];
        if ($private) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'ci-pipelines-github-private: extra.proctor.github-mirror is false but .github/workflows/ci.yml exists; a private package should not ship a GitHub workflow',
                self::GITHUB_CI,
                1,
                null,
                'github-private',
            );
        }

        $status = $isStack && !$private ? Finding::STATUS_FAIL : Finding::STATUS_WARNING;

        [$data, $error] = $this->parseYaml($absolutePath);
        if ($data === null) {
            $findings[] = new Finding(
                $this->id(),
                $status,
                sprintf(
                    'ci-pipelines-github-parse: .github/workflows/ci.yml could not be parsed: %s; fix the YAML so the workflow can run',
                    $error,
                ),
                self::GITHUB_CI,
                1,
                null,
                'github-parse',
            );

            return $findings;
        }

        $findings = array_merge(
            $this->composerAuditOffFindings($data, $absolutePath, self::GITHUB_CI, false),
            $findings,
        );
        $allowOverrides = $this->allowOverridesFinding($data, $absolutePath, self::GITHUB_CI, false);
        if ($allowOverrides !== null) {
            $findings[] = $allowOverrides;
        }

        $runsComposerCi = false;
        $seen = [];
        $jobs = $data['jobs'] ?? null;

        if (is_array($jobs)) {
            foreach ($jobs as $jobName => $job) {
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
                foreach ($steps as $stepIndex => $step) {
                    if (!is_array($step)) {
                        continue;
                    }
                    if (isset($step['run'])) {
                        $stepName = is_string($step['name'] ?? null)
                            ? $step['name']
                            : sprintf('step %d', $stepIndex + 1);
                        foreach ($this->scriptLines($step['run']) as $line) {
                            if ($this->lineRunsComposerCi($line)) {
                                $runsComposerCi = true;
                            }
                            $finding = $this->composerUpdateFinding(
                                $line,
                                (string) $jobName . ' / ' . $stepName,
                                $stepName,
                                self::GITHUB_CI,
                                $absolutePath,
                                $seen,
                            );
                            if ($finding !== null) {
                                $findings[] = $finding;
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
                $status,
                'ci-pipelines-github-no-ci: no job in .github/workflows/ci.yml runs composer ci or calls a reusable workflow with ci-command: composer ci; run composer ci in a job, or call a reusable workflow with ci-command: composer ci',
                self::GITHUB_CI,
                1,
                null,
                'github-no-ci',
            );
        }

        return $findings;
    }

    /**
     * The warnings for the GitHub workflows other than ci.yml (every .yml
     * and .yaml file in .github/workflows, sorted): composer-audit-off and
     * allow-overrides for the env they set, and composer-update for a step
     * that runs composer update or composer require. A release or nightly
     * workflow that refreshes the lock bypasses rule 3.7 as surely as ci.yml
     * would. The composer ci contract and the uses: pin stay on ci.yml, and a
     * workflow that does not parse is GitHub's to report, so it gives no
     * finding here.
     *
     * @return list<Finding>
     */
    private function otherWorkflowFindings(string $appPath): array
    {
        $folder = $appPath . '/' . dirname(self::GITHUB_CI);
        if (!is_dir($folder)) {
            return [];
        }
        $names = scandir($folder);
        if ($names === false) {
            return [];
        }

        $findings = [];
        foreach ($names as $name) {
            $relativePath = dirname(self::GITHUB_CI) . '/' . $name;
            $absolutePath = $appPath . '/' . $relativePath;
            if ($relativePath === self::GITHUB_CI
                || preg_match('/\.ya?ml$/i', $name) !== 1
                || !is_file($absolutePath)) {
                continue;
            }
            [$data, ] = $this->parseYaml($absolutePath);
            if ($data === null) {
                continue;
            }

            array_push($findings, ...$this->composerAuditOffFindings($data, $absolutePath, $relativePath, false));
            $allowOverrides = $this->allowOverridesFinding($data, $absolutePath, $relativePath, false);
            if ($allowOverrides !== null) {
                $findings[] = $allowOverrides;
            }

            $seen = [];
            $jobs = $data['jobs'] ?? null;
            foreach (is_array($jobs) ? $jobs : [] as $jobName => $job) {
                $steps = is_array($job) ? ($job['steps'] ?? null) : null;
                foreach (is_array($steps) ? $steps : [] as $stepIndex => $step) {
                    if (!is_array($step) || !isset($step['run'])) {
                        continue;
                    }
                    $stepName = is_string($step['name'] ?? null)
                        ? $step['name']
                        : sprintf('step %d', (int) $stepIndex + 1);
                    foreach ($this->scriptLines($step['run']) as $line) {
                        $finding = $this->composerUpdateFinding(
                            $line,
                            (string) $jobName . ' / ' . $stepName,
                            $stepName,
                            $relativePath,
                            $absolutePath,
                            $seen,
                        );
                        if ($finding !== null) {
                            $findings[] = $finding;
                        }
                    }
                }
            }
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
            null,
            'unpinned-uses',
        );
    }

    /**
     * The warnings for the pipeline environment settings that switch
     * Composer's audit or blocking policy off: COMPOSER_NO_AUDIT and
     * COMPOSER_NO_BLOCKING set to anything but an empty string, 0 or
     * false (compared string-cast, case-insensitively), and
     * COMPOSER_AUDIT_ABANDONED set to ignore. One warning per variable
     * name per file, whatever the project kind: a warning, never a fail.
     * A file that fails to parse gets none of these.
     *
     * @param array<string, mixed> $data the parsed pipeline map
     *
     * @return list<Finding>
     */
    private function composerAuditOffFindings(array $data, string $absolutePath, string $relativePath, bool $gitlab): array
    {
        $findings = [];
        foreach ($this->pipelineEnvSettings($data, $gitlab) as $name => $values) {
            $offending = false;
            foreach ($values as $value) {
                if ($this->switchesComposerAuditOff($name, (string) $value)) {
                    $offending = true;
                    break;
                }
            }
            if (!$offending) {
                continue;
            }
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf(
                    'ci-pipelines-composer-audit-off: %s is set in the pipeline, so Composer\'s audit or blocking policy is switched off',
                    $name,
                ),
                $relativePath,
                $this->lineOf($name, $absolutePath),
                null,
                'composer-audit-off',
            );
        }

        return $findings;
    }

    /**
     * The warning for a pipeline that sets PROCTOR_ALLOW_OVERRIDES to 1,
     * which lets the app pass --policy and --today in CI (BEST_PRACTICES
     * 1.2) and grade itself against a policy or a date it picks. One
     * warning per file, whatever the project kind: a warning, never a
     * fail. Read from the same places as the audit switches: the GitLab
     * top-level and job variables: and the GitHub top-level, job and
     * step env:. Warns only when the value is the string 1 or the number
     * 1, the two shapes the runners export as the string 1 that the CI
     * guard accepts; any other value (0, empty, false, yes, true) leaves
     * the guard on: no finding.
     *
     * @param array<string, mixed> $data the parsed pipeline map
     */
    private function allowOverridesFinding(array $data, string $absolutePath, string $relativePath, bool $gitlab): ?Finding
    {
        $values = $this->pipelineEnvSettings($data, $gitlab)[self::ALLOW_OVERRIDES] ?? [];
        foreach ($values as $value) {
            if ($value === '1' || $value === 1) {
                return new Finding(
                    $this->id(),
                    Finding::STATUS_WARNING,
                    sprintf(
                        'ci-pipelines-allow-overrides: %s is 1 in the pipeline, so PROCTOR accepts --policy and --today and the app can grade itself against a policy or date it picks; remove it (only PROCTOR\'s own pipeline needs it)',
                        self::ALLOW_OVERRIDES,
                    ),
                    $relativePath,
                    $this->lineOf(self::ALLOW_OVERRIDES, $absolutePath),
                    null,
                    'allow-overrides',
                );
            }
        }

        return null;
    }

    /**
     * The pipeline environment settings the file sets, whatever the
     * project kind: every value seen for a name, in first-seen order.
     * GitLab settings come from the top-level variables: and each job's
     * variables:, where a value is a scalar or a map with a value key;
     * GitHub's from the top-level env:, each job's env: and each step's
     * env:.
     *
     * @param array<string, mixed> $data the parsed pipeline map
     *
     * @return array<string, list<mixed>>
     */
    private function pipelineEnvSettings(array $data, bool $gitlab): array
    {
        $settings = [];
        if ($gitlab) {
            $this->recordEnvBlock($data['variables'] ?? null, $settings);
            foreach ($data as $job) {
                if (is_array($job)) {
                    $this->recordEnvBlock($job['variables'] ?? null, $settings);
                }
            }
        } else {
            $this->recordEnvBlock($data['env'] ?? null, $settings);
            $jobs = $data['jobs'] ?? null;
            if (is_array($jobs)) {
                foreach ($jobs as $job) {
                    if (!is_array($job)) {
                        continue;
                    }
                    $this->recordEnvBlock($job['env'] ?? null, $settings);
                    $steps = $job['steps'] ?? null;
                    if (is_array($steps)) {
                        foreach ($steps as $step) {
                            if (is_array($step)) {
                                $this->recordEnvBlock($step['env'] ?? null, $settings);
                            }
                        }
                    }
                }
            }
        }

        return $settings;
    }

    /**
     * Records the settings of one variables or env block in $settings:
     * every value seen for a name, in first-seen order. A value is a
     * scalar, or a map with a value key (the GitLab shape); anything
     * else is skipped.
     *
     * @param mixed                       $block
     * @param array<string, list<mixed>>  $settings
     */
    private function recordEnvBlock(mixed $block, array &$settings): void
    {
        if (!is_array($block)) {
            return;
        }
        foreach ($block as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            if (is_array($value)) {
                if (!array_key_exists('value', $value)) {
                    continue;
                }
                $value = $value['value'];
            }
            if (!is_scalar($value)) {
                continue;
            }
            $settings[$name][] = $value;
        }
    }

    /**
     * Whether a pipeline environment setting switches Composer's audit
     * or blocking policy off: COMPOSER_NO_AUDIT and COMPOSER_NO_BLOCKING
     * set to anything but an empty string, 0 or false (compared
     * string-cast, case-insensitively), and COMPOSER_AUDIT_ABANDONED set
     * to ignore.
     */
    private function switchesComposerAuditOff(string $name, string $value): bool
    {
        $value = strtolower($value);
        if ($name === 'COMPOSER_NO_AUDIT' || $name === 'COMPOSER_NO_BLOCKING') {
            return !in_array($value, ['', '0', 'false'], true);
        }
        if ($name === 'COMPOSER_AUDIT_ABANDONED') {
            return $value === 'ignore';
        }

        return false;
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
     * The warning for a script line that carries a command segment
     * starting with composer update or composer require, or null when no
     * segment of the line does. One finding per (file, context, line):
     * the same line reached twice earns one warning, whatever the walk.
     * Segments that start with # after trimming are comments inside a
     * block string, not commands, and never fire. A line that carries
     * both commands still earns one finding, for its first matching
     * segment.
     *
     * @param string              $line         the script line as parsed
     * @param string              $context      what identifies the line for dedupe: the GitLab job
     *                                         name, or the GitHub job and step together
     * @param string              $jobOrStep    the job or step name the message shows
     * @param string              $relativePath the pipeline file, relative to the app root
     * @param string              $absolutePath the pipeline file, absolute
     * @param array<string, bool> $seen         already-seen (file, context, line) keys
     */
    private function composerUpdateFinding(
        string $line,
        string $context,
        string $jobOrStep,
        string $relativePath,
        string $absolutePath,
        array &$seen,
    ): ?Finding {
        $key = $relativePath . "\0" . $context . "\0" . $line;
        if (isset($seen[$key])) {
            return null;
        }
        $seen[$key] = true;

        $segments = preg_split('/&&|\|\||;/', $line);
        if ($segments === false) {
            return null;
        }
        foreach ($segments as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '' || str_starts_with($segment, '#')) {
                continue;
            }
            foreach (['composer update', 'composer require'] as $command) {
                $length = strlen($command);
                if (str_starts_with($segment, $command)
                    && (strlen($segment) === $length || $segment[$length] === ' ' || $segment[$length] === "\t")) {
                    return new Finding(
                        $this->id(),
                        Finding::STATUS_WARNING,
                        sprintf(
                            'ci-pipelines-composer-update: CI runs "%s" in %s; install from the lock with "composer install" instead (rule 3.7).',
                            $segment,
                            $jobOrStep,
                        ),
                        $relativePath,
                        $this->lineOf($segment, $absolutePath),
                        null,
                        'composer-update',
                    );
                }
            }
        }

        return null;
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
