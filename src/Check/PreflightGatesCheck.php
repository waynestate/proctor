<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

/**
 * Warns when an app's GitLab pipeline skips or weakens Preflight: no
 * Preflight component included at all, a gate that may fail without failing
 * the pipeline, or no job that runs PROCTOR.
 *
 * Preflight practice 3.3 asks for these flags because a standard nobody can
 * see being skipped is not a standard. The fourth flag it names, an include
 * at a moving ref, is preflight-pinned's job; this check covers the rest:
 *
 * - no-include: .gitlab-ci.yml has no include: entry whose component: path
 *   contains /preflight/, so none of the stack's gates run.
 * - allow-failure: a gate component (every Preflight component but the
 *   renovate bot and package-publish) is included with the allow_failure
 *   input set to true, or the app re-declares the gate's job (its job_name
 *   input, or the component name) with allow_failure set to anything but
 *   false, or with a rules: entry that sets allow_failure: true. One
 *   warning per place it is set.
 * - skipped: the app re-declares the gate's job with when: never, or with
 *   rules: that all say when: never, so the gate never runs and the
 *   pipeline passes without it.
 * - manual: the app re-declares the gate's job with when: manual and no
 *   allow_failure, which GitLab defaults to allow_failure: true, so the
 *   pipeline passes until someone clicks the gate.
 * - no-proctor-job: the Preflight proctor component is not included and no
 *   job's script runs `proctor check` or `proctor gate` (vendor/bin/proctor,
 *   proctor.phar or a path to either).
 *
 * Warns, never fails, and is not in the deploy gate: PROCTOR's own pipeline
 * and Sourdough's both keep allow_failure on the proctor job while their
 * findings are fixed, on purpose and with a comment, and a check that failed
 * them would only teach people to switch it off. No finding when there is
 * no .gitlab-ci.yml or it does not parse: ci-pipelines reports both. The
 * local files the pipeline includes are read with it (GitlabPipeline), so a
 * Preflight include or a gate job in ci/*.yml counts. With nothing to flag,
 * one pass. The file is parsed as YAML, never grepped, and
 * nothing here reads the environment: the pipeline file is the only witness.
 */
final class PreflightGatesCheck implements CheckInterface
{
    /** The GitLab pipeline file, relative to the app root. */
    private const GITLAB_CI = '.gitlab-ci.yml';

    /**
     * The Preflight components whose job is a gate: when it fails, the
     * pipeline should stop. renovate (a bot) and package-publish (a release
     * step) are not gates, and php-test's advisory job is meant to fail
     * softly, so neither is listed.
     */
    private const GATE_COMPONENTS = [
        'a11y',
        'composer-audit',
        'eslint',
        'mysql56-migrate',
        'php-test',
        'phpstan',
        'pint',
        'portability',
        'proctor',
        'sbom',
        'secret-detection',
        'squire-kit',
    ];

    /** Top-level keys GitLab reserves; none of them is a job. */
    private const RESERVED_KEYS = ['default', 'include', 'stages', 'variables', 'workflow', 'spec'];

    /**
     * A command segment that runs PROCTOR's check or gate: proctor,
     * vendor/bin/proctor, proctor.phar or any path ending in one of them,
     * optionally behind php.
     */
    private const PROCTOR_COMMAND_PATTERN = '/(^|[\s\/])proctor(\.phar)?\s+(check|gate)(\s|$)/';

    public function id(): string
    {
        return 'preflight-gates';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        $pipeline = GitlabPipeline::read($appPath);
        if ($pipeline === null) {
            return [];
        }

        $findings = [];
        $includes = [];
        $runsProctor = false;
        // Each file of the pipeline: the root and every local file it
        // includes, which GitLab merges in before any job runs.
        foreach ($pipeline->files as $file) {
            $lines = $this->lines($appPath . '/' . $file['path']);
            foreach ($this->preflightIncludes($file['data']['include'] ?? null) as [$componentPath, $name, $inputs]) {
                $includes[] = [$componentPath, $name, $inputs];
                if ($name === 'proctor') {
                    $runsProctor = true;
                    $command = $this->proctorCommandFinding($componentPath, $inputs, $file['path'], $lines);
                    if ($command !== null) {
                        $findings[] = $command;
                    }
                }
                if (!in_array($name, self::GATE_COMPONENTS, true) || ($inputs['allow_failure'] ?? null) !== true) {
                    continue;
                }
                $start = $this->lineOf($componentPath, $lines);
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_WARNING,
                    sprintf(
                        'preflight-gates-allow-failure: the Preflight %s component is included with allow_failure: true, so the gate can fail and the pipeline still passes; remove the input once its findings are fixed',
                        $name,
                    ),
                    $file['path'],
                    $this->lineAfter($start, '/^\s*allow_failure\s*:/', $lines),
                    null,
                    'allow-failure',
                );
            }
        }

        $where = $pipeline->hasIncludedFiles() ? '.gitlab-ci.yml or the local files it includes' : '.gitlab-ci.yml';
        if ($includes === []) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf(
                    'preflight-gates-no-include: %s no Preflight component, so none of the stack\'s gates run; include php-test, composer-audit and proctor from Preflight, pinned to a release tag',
                    $pipeline->hasIncludedFiles() ? '.gitlab-ci.yml and the local files it includes have' : '.gitlab-ci.yml includes',
                ),
                self::GITLAB_CI,
                1,
                null,
                'no-include',
            );
        }

        $gateJobs = $this->gateJobNames($includes);
        foreach ($pipeline->files as $file) {
            $lines = null;
            foreach ($gateJobs as $jobName => $component) {
                $job = $file['data'][$jobName] ?? null;
                if (!is_array($job)) {
                    continue;
                }
                $lines ??= $this->lines($appPath . '/' . $file['path']);
                $start = $this->jobLine((string) $jobName, $lines);
                if (array_key_exists('allow_failure', $job) && $job['allow_failure'] !== false) {
                    $findings[] = new Finding(
                        $this->id(),
                        Finding::STATUS_WARNING,
                        sprintf(
                            'preflight-gates-allow-failure: job %s (the Preflight %s gate) sets allow_failure, so the gate can fail and the pipeline still passes; remove it once the findings are fixed',
                            $jobName,
                            $component,
                        ),
                        $file['path'],
                        $this->lineAfter($start, '/^\s+allow_failure\s*:/', $lines),
                        null,
                        'allow-failure',
                    );
                }
                $when = $job['when'] ?? null;
                if ($when === 'never') {
                    $findings[] = new Finding(
                        $this->id(),
                        Finding::STATUS_WARNING,
                        sprintf(
                            'preflight-gates-skipped: job %s (the Preflight %s gate) sets when: never, so the gate never runs and the pipeline passes without it; remove the override',
                            $jobName,
                            $component,
                        ),
                        $file['path'],
                        $this->lineAfter($start, '/^\s+when\s*:/', $lines),
                        null,
                        'skipped',
                    );
                } elseif ($when === 'manual' && !array_key_exists('allow_failure', $job)) {
                    // GitLab makes a job-level manual job allow_failure: true
                    // by default, so the pipeline passes without a click.
                    $findings[] = new Finding(
                        $this->id(),
                        Finding::STATUS_WARNING,
                        sprintf(
                            'preflight-gates-manual: job %s (the Preflight %s gate) sets when: manual without allow_failure: false, so GitLab treats it as optional and the pipeline passes without it; remove when: manual, or add allow_failure: false so the pipeline waits for it',
                            $jobName,
                            $component,
                        ),
                        $file['path'],
                        $this->lineAfter($start, '/^\s+when\s*:/', $lines),
                        null,
                        'manual',
                    );
                }
                $rules = $job['rules'] ?? null;
                if (is_array($rules) && $rules !== []) {
                    $allNever = true;
                    $mayFail = false;
                    foreach ($rules as $rule) {
                        $allNever = $allNever && is_array($rule) && ($rule['when'] ?? null) === 'never';
                        $mayFail = $mayFail || (is_array($rule) && ($rule['allow_failure'] ?? null) === true);
                    }
                    if ($allNever) {
                        $findings[] = new Finding(
                            $this->id(),
                            Finding::STATUS_WARNING,
                            sprintf(
                                'preflight-gates-skipped: job %s (the Preflight %s gate) has rules that all say when: never, so the gate never runs and the pipeline passes without it; remove the override',
                                $jobName,
                                $component,
                            ),
                            $file['path'],
                            $this->lineAfter($start, '/^\s+rules\s*:/', $lines),
                            null,
                            'skipped',
                        );
                    }
                    if ($mayFail) {
                        $findings[] = new Finding(
                            $this->id(),
                            Finding::STATUS_WARNING,
                            sprintf(
                                'preflight-gates-allow-failure: job %s (the Preflight %s gate) has a rules: entry with allow_failure: true, so the gate can fail and the pipeline still passes; remove it once the findings are fixed',
                                $jobName,
                                $component,
                            ),
                            $file['path'],
                            $this->lineAfter($start, '/^\s+(-\s+)?allow_failure\s*:/', $lines),
                            null,
                            'allow-failure',
                        );
                    }
                }
            }
            if (!$runsProctor && $this->aJobRunsProctor($file['data'])) {
                $runsProctor = true;
            }
        }

        if (!$runsProctor) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf(
                    'preflight-gates-no-proctor-job: no job in %s runs PROCTOR; include the Preflight proctor component, pinned to a release tag, so every pipeline checks the app against the policy',
                    $where,
                ),
                self::GITLAB_CI,
                1,
                null,
                'no-proctor-job',
            );
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'Preflight gates are included, none may fail softly, and PROCTOR runs',
            )];
        }

        // Stable on every OS: the root file first, then by path, line and
        // message.
        usort($findings, static function (Finding $a, Finding $b): int {
            return (($a->path !== self::GITLAB_CI) <=> ($b->path !== self::GITLAB_CI))
                ?: strcmp((string) $a->path, (string) $b->path)
                ?: ($a->line <=> $b->line)
                ?: strcmp($a->message, $b->message);
        });

        return $findings;
    }

    /**
     * The warning for a Preflight proctor include whose proctor_command
     * input disarms the job, or null when the input is absent (the default
     * runs `vendor/bin/proctor check`) or runs PROCTOR plainly.
     *
     * The component hands the input to the shell as written, so it is the
     * one free-text door into the job. Two shapes open it: a command with
     * no segment that runs `proctor check` or `proctor gate` (`true`, `echo
     * skipped`), and one that runs PROCTOR but pipes it or follows it with
     * `||`, so the shell reports another command's exit code and a failing
     * check passes. A redirect (`> report.json`) leaves the exit code alone
     * and is fine. The proctor watches the exam paper, not just the clock.
     *
     * @param array<mixed, mixed> $inputs the include's inputs
     * @param list<string>        $lines  the raw lines of the file it is in
     */
    private function proctorCommandFinding(string $componentPath, array $inputs, string $path, array $lines): ?Finding
    {
        $command = $inputs['proctor_command'] ?? null;
        if (!is_string($command)) {
            return null;
        }

        $runs = false;
        foreach (preg_split('/&&|\|\||;|\||\n/', $command) ?: [] as $segment) {
            $segment = trim($segment);
            if ($segment !== '' && !str_starts_with($segment, '#') && preg_match(self::PROCTOR_COMMAND_PATTERN, $segment) === 1) {
                $runs = true;
            }
        }

        if (!$runs) {
            $problem = 'runs neither proctor check nor proctor gate, so the job passes without grading the app';
        } elseif (preg_match('/\|/', $command) === 1) {
            $problem = 'pipes PROCTOR or follows it with ||, so the shell reports another command\'s exit code and a failing check still passes';
        } else {
            return null;
        }

        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                'preflight-gates-proctor-command: the Preflight proctor component\'s proctor_command "%s" %s; leave the input at its default or run vendor/bin/proctor check on its own',
                trim((string) preg_replace('/\s+/', ' ', $command)),
                $problem,
            ),
            $path,
            $this->lineAfter($this->lineOf($componentPath, $lines), '/^\s*proctor_command\s*:/', $lines),
            null,
            'proctor-command',
        );
    }

    /**
     * The Preflight component includes: each entry's component path, the
     * component name (the path segment after /preflight/, before the @) and
     * its inputs map.
     *
     * @return list<array{0: string, 1: string, 2: array<mixed, mixed>}>
     */
    private function preflightIncludes(mixed $include): array
    {
        if (!is_array($include)) {
            // A single string include is a file, never a component.
            return [];
        }
        // include: as one map rather than a list of entries.
        if (isset($include['component'])) {
            $include = [$include];
        }

        $found = [];
        foreach ($include as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $component = $entry['component'] ?? null;
            if (!is_string($component)) {
                continue;
            }
            $marker = strrpos($component, '/preflight/');
            if ($marker === false) {
                continue;
            }
            $name = substr($component, $marker + strlen('/preflight/'));
            $at = strpos($name, '@');
            if ($at !== false) {
                $name = substr($name, 0, $at);
            }
            $inputs = $entry['inputs'] ?? [];
            $found[] = [$component, $name, is_array($inputs) ? $inputs : []];
        }

        return $found;
    }

    /**
     * The job name each included gate component defines: its job_name
     * input, or the component name (every Preflight component's default),
     * mapped to the component.
     *
     * @param list<array{0: string, 1: string, 2: array<mixed, mixed>}> $includes
     *
     * @return array<string, string>
     */
    private function gateJobNames(array $includes): array
    {
        $names = [];
        foreach ($includes as [, $name, $inputs]) {
            if (!in_array($name, self::GATE_COMPONENTS, true)) {
                continue;
            }
            $jobName = $inputs['job_name'] ?? null;
            $names[is_string($jobName) && $jobName !== '' ? $jobName : $name] = $name;
        }

        return $names;
    }

    /**
     * Whether any job's script runs PROCTOR's check or gate. Hidden jobs
     * (a leading dot) and GitLab's reserved keys are not jobs. A segment
     * that starts with # is a comment inside a block string.
     *
     * @param array<mixed, mixed> $data
     */
    private function aJobRunsProctor(array $data): bool
    {
        foreach ($data as $jobName => $job) {
            $jobName = (string) $jobName;
            if (!is_array($job) || str_starts_with($jobName, '.') || in_array($jobName, self::RESERVED_KEYS, true)) {
                continue;
            }
            $script = $job['script'] ?? null;
            $scriptLines = is_string($script) ? [$script] : (is_array($script) ? $script : []);
            foreach ($scriptLines as $line) {
                if (!is_string($line)) {
                    continue;
                }
                $segments = preg_split('/&&|\|\||;|\n/', $line) ?: [];
                foreach ($segments as $segment) {
                    $segment = trim($segment);
                    if ($segment === '' || str_starts_with($segment, '#')) {
                        continue;
                    }
                    if (preg_match(self::PROCTOR_COMMAND_PATTERN, $segment) === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * The file's raw lines, or an empty list when it cannot be read.
     *
     * @return list<string>
     */
    private function lines(string $absolutePath): array
    {
        $lines = file($absolutePath, FILE_IGNORE_NEW_LINES);

        return $lines === false ? [] : array_map('strval', $lines);
    }

    /**
     * The 1-based line of the first raw line containing $needle, or 1.
     *
     * @param list<string> $lines
     */
    private function lineOf(string $needle, array $lines): int
    {
        foreach ($lines as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index + 1;
            }
        }

        return 1;
    }

    /**
     * The 1-based line of the top-level key for the named job, or 1.
     *
     * @param list<string> $lines
     */
    private function jobLine(string $jobName, array $lines): int
    {
        $pattern = '/^["\']?' . preg_quote($jobName, '/') . '["\']?\s*:/';
        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                return $index + 1;
            }
        }

        return 1;
    }

    /**
     * The 1-based line of the first line at or after $start that matches
     * $pattern, or $start when none does. The proctor reads on down the
     * page until it finds the note, and points at the heading if it never
     * turns up.
     *
     * @param list<string> $lines
     */
    private function lineAfter(int $start, string $pattern, array $lines): int
    {
        $count = count($lines);
        for ($index = $start - 1; $index < $count; $index++) {
            if ($index >= 0 && preg_match($pattern, $lines[$index]) === 1) {
                return $index + 1;
            }
        }

        return $start;
    }
}
