<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\Code\RepoFiles;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\Override\OverrideFile;
use Waynestate\Proctor\ToolError;

/**
 * Flags an override whose approver also wrote it.
 *
 * A note from home signed by the student is not a note from home. For each
 * entry of policy-overrides.json, git blame (read-only, whitespace ignored,
 * moved lines followed) names who last wrote every line of the entry, and
 * the entry is self-approved when its approved_by names one of those
 * authors: the git author name, the author email, the email's local part,
 * or the username in a GitLab or GitHub noreply address, compared trimmed
 * and case-insensitively, with a leading "@" and a "Name <email>" form
 * understood. A line that holds only the approved_by member is the
 * approver's signature, so its own author is not counted: an approver may
 * write their name into someone else's entry, but not approve an entry, or
 * an extension of one, that they wrote.
 *
 * Uncommitted lines are credited to the identity the next commit would
 * carry (git var GIT_AUTHOR_IDENT), so a local run warns before the commit.
 *
 * The check never passes on a guess. When git is missing, the app path is
 * not in a git checkout, or git cannot answer, it reports one
 * proctor/override-approver/could-not-run finding. In a shallow clone a line
 * credited to the clone's oldest commit may be older than the clone, so an
 * entry that has such a line and no proven self-approval is could-not-run
 * too. With every entry checked and none self-approved, one pass finding.
 *
 * The policy's rules.override_self_approval decides the status of a
 * self-approval and of a could-not-run: "warn" (the default when the key is
 * absent) or "fail". The merge request approval is the human control; this
 * check is the tripwire for the common slip, writing your own name. It runs
 * in `proctor check` only, never in `proctor gate`: the gate stays small,
 * offline and fast, and runs where there may be no git history at all.
 */
final class OverrideApproverCheck implements CheckInterface
{
    public const ID = 'override-approver';

    /** The policy rule that sets the status of a finding. */
    public const POLICY_KEY = 'override_self_approval';

    /** The status when the policy has no override_self_approval key. */
    public const DEFAULT_LEVEL = 'warn';

    /** The environment variables that point git at another repository. */
    private const REDIRECTING_GIT_VARS = [
        'GIT_DIR',
        'GIT_WORK_TREE',
        'GIT_INDEX_FILE',
        'GIT_OBJECT_DIRECTORY',
        'GIT_ALTERNATE_OBJECT_DIRECTORIES',
        'GIT_COMMON_DIR',
    ];

    /**
     * @param string|null $git the git executable, or null to find it on PATH
     */
    public function __construct(
        private readonly ProcessRunnerInterface $process = new ProcessRunner(),
        private readonly ?string $git = null,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function run(CheckContext $context): array
    {
        if ($context->overrides === []) {
            return [];
        }

        $status = self::level($context->policy) === 'fail' ? Finding::STATUS_FAIL : Finding::STATUS_WARNING;

        $raw = @file_get_contents($context->appPath . '/' . OverrideFile::FILENAME);
        $entries = is_string($raw) ? self::entryLines($raw) : [];
        if (count($entries) !== count($context->overrides)) {
            return [$this->notChecked($status, 'PROCTOR could not find each entry\'s lines in ' . OverrideFile::FILENAME)];
        }

        $git = $this->git ?? RepoFiles::findGit();
        if ($git === null) {
            return [$this->notChecked($status, 'git is not on PATH')];
        }

        try {
            $authors = $this->lineAuthors($git, $context);
        } catch (ToolError $error) {
            return [$this->notChecked($status, 'git did not answer: ' . $error->getMessage())];
        }
        if (is_string($authors)) {
            return [$this->notChecked($status, $authors)];
        }
        [$lines, $shallow] = $authors;

        $findings = [];
        foreach ($entries as $index => $entry) {
            $override = $context->overrides[$index];
            $verdict = self::judge($override, $entry['lines'], $lines, $shallow);
            if ($verdict === null) {
                continue;
            }
            if ($verdict === 'shallow') {
                $findings[] = new Finding(
                    $this->id(),
                    $status,
                    sprintf(
                        'not checked: override for "%s" was last changed at or before the oldest commit of this shallow clone, so PROCTOR cannot see who wrote it; fetch the full history (GIT_DEPTH: 0 in GitLab CI)',
                        $override->check,
                    ),
                    OverrideFile::FILENAME,
                    $entry['start'],
                    null,
                    'could-not-run',
                );
                continue;
            }
            $findings[] = new Finding(
                $this->id(),
                $status,
                sprintf(
                    'override for "%s" is approved by "%s", who also wrote it (%s); someone who did not write the entry must approve it',
                    $override->check,
                    trim($override->approvedBy),
                    $verdict,
                ),
                OverrideFile::FILENAME,
                $entry['start'],
                null,
                'self-approved',
            );
        }

        if ($findings !== []) {
            return $findings;
        }

        $count = count($entries);

        return [new Finding(
            $this->id(),
            Finding::STATUS_PASS,
            sprintf(
                'no override is approved by its own author (%d %s checked against git)',
                $count,
                $count === 1 ? 'entry' : 'entries',
            ),
            OverrideFile::FILENAME,
        )];
    }

    /**
     * The policy's level for this check: "warn" or "fail".
     *
     * From rules.override_self_approval; "warn" when the key is absent, so a
     * policy shaped before the rule existed reports without failing.
     *
     * @throws ToolError when the value is neither "warn" nor "fail"
     */
    public static function level(Policy $policy): string
    {
        $value = $policy->rules()[self::POLICY_KEY] ?? self::DEFAULT_LEVEL;
        if ($value !== 'warn' && $value !== 'fail') {
            throw new ToolError(sprintf(
                'Policy rules carry an invalid "%s" value: %s; expected "warn" or "fail".',
                self::POLICY_KEY,
                var_export($value, true),
            ));
        }

        return $value;
    }

    /**
     * The lines of each top-level entry of a policy-overrides.json file, in
     * file order, so entry N lines up with the Nth Override that
     * OverrideFile::load() returns.
     *
     * `start` is the line of the entry's opening brace. `lines` is every line
     * from the opening to the closing brace except a line that holds only
     * the approved_by member: that line is the approver's signature. A line
     * shared with another member stays in. Lines are 1-based and counted on
     * "\n", as git blame counts them. The scanner reads only JSON structure
     * (strings, escapes, braces, commas), and the file has already been
     * validated by OverrideFile.
     *
     * @return list<array{start: int, lines: list<int>}>
     */
    public static function entryLines(string $raw): array
    {
        $entries = [];
        $line = 1;
        $depth = 0;
        $inString = false;
        $escaped = false;
        $keyStart = null;
        $keyLine = 1;
        $expectKey = false;
        $lastLine = 1;
        $entryStart = 1;
        /** @var list<array{key: string, start: int, end: int}> $members */
        $members = [];
        /** @var array{key: string, start: int, end: int}|null $member */
        $member = null;

        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];
            if ($char === "\n") {
                $line++;

                continue;
            }
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                    if ($keyStart !== null) {
                        $key = json_decode(substr($raw, $keyStart, $i - $keyStart + 1));
                        $member = ['key' => is_string($key) ? $key : '', 'start' => $keyLine, 'end' => $line];
                        $keyStart = null;
                    }
                }
                $lastLine = $line;

                continue;
            }
            if ($char === ' ' || $char === "\t" || $char === "\r") {
                continue;
            }

            // A comma or closing brace at depth 2 ends a member of an entry.
            if ($depth === 2 && ($char === ',' || $char === '}')) {
                if ($member !== null) {
                    $member['end'] = $lastLine;
                    $members[] = $member;
                    $member = null;
                }
                if ($char === ',') {
                    $expectKey = true;

                    continue;
                }
                $entries[] = ['start' => $entryStart, 'lines' => self::contentLines($entryStart, $line, $members)];
                $members = [];
                $depth--;

                continue;
            }

            $lastLine = $line;
            if ($char === '"') {
                $inString = true;
                if ($depth === 2 && $expectKey) {
                    $keyStart = $i;
                    $keyLine = $line;
                    $expectKey = false;
                }

                continue;
            }
            if ($char === '{' || $char === '[') {
                $depth++;
                if ($char === '{' && $depth === 2) {
                    $entryStart = $line;
                    $expectKey = true;
                }

                continue;
            }
            if ($char === '}' || $char === ']') {
                $depth--;
            }
        }

        return $entries;
    }

    /**
     * Whether an approver string names the author of a commit: the author
     * name, the author email, the email's local part, or the username in a
     * GitLab (`123-name@users.noreply.<host>`) or GitHub
     * (`123+name@users.noreply.github.com`) noreply address. The approver
     * may be a name, an email, a username with or without a leading "@", or
     * "Name <email>". Comparisons are trimmed, whitespace-collapsed and
     * case-insensitive.
     */
    public static function namesAuthor(string $approvedBy, string $authorName, string $authorEmail): bool
    {
        $approver = self::approverForms($approvedBy);
        if ($approver === []) {
            return false;
        }

        return array_intersect($approver, self::authorForms($authorName, $authorEmail)) !== [];
    }

    /**
     * Null when the entry is not self-approved, 'shallow' when a line may
     * predate a shallow clone and no self-approval was proven, otherwise
     * where the approver wrote the entry ("commit 1a2b3c4d" or "an
     * uncommitted change").
     *
     * @param list<int>                                                      $entryLines the entry's lines
     * @param array<int, array{sha: string, name: string, email: string}>   $authors    git's author of each line
     * @param array<string, true>                                            $shallow    the clone's shallow commits
     */
    private static function judge(Override $override, array $entryLines, array $authors, array $shallow): ?string
    {
        $undecided = false;
        foreach ($entryLines as $number) {
            $author = $authors[$number] ?? null;
            if ($author === null || isset($shallow[$author['sha']])) {
                // The shallow clone's oldest commit carries every line older
                // than the clone, under its own author, so it proves nothing.
                $undecided = true;

                continue;
            }
            if (self::namesAuthor($override->approvedBy, $author['name'], $author['email'])) {
                return preg_match('/^0+$/', $author['sha']) === 1
                    ? 'an uncommitted change'
                    : 'commit ' . substr($author['sha'], 0, 8);
            }
        }

        return $undecided ? 'shallow' : null;
    }

    /**
     * Who last wrote each line of the overrides file, and the shallow
     * commits of the clone, or a reason the question cannot be answered.
     *
     * @return array{0: array<int, array{sha: string, name: string, email: string}>, 1: array<string, true>}|string
     *
     * @throws ToolError when git cannot be started or times out
     */
    private function lineAuthors(string $git, CheckContext $context): array|string
    {
        $where = rtrim($context->appPath, '/\\');

        $repo = $this->git($git, ['rev-parse', '--is-inside-work-tree', '--git-path', 'shallow'], $context);
        $answer = preg_split('/\R/', trim($repo->stdout)) ?: [];
        if ($repo->exitCode !== 0 || ($answer[0] ?? '') !== 'true') {
            $why = $repo->firstStderrLine();

            return 'the app path is not inside a git checkout' . ($why !== '' ? ' (' . $why . ')' : '');
        }
        $shallow = self::shallowCommits($where, $answer[1] ?? '');

        $inHead = $this->git($git, ['cat-file', '-e', 'HEAD:./' . OverrideFile::FILENAME], $context);
        if ($inHead->exitCode === 0) {
            $blame = $this->git($git, [
                '-c', 'core.fsmonitor=false',
                'blame', '--line-porcelain', '-w', '-M', '--root', '--no-textconv',
                '--', OverrideFile::FILENAME,
            ], $context);
            if ($blame->exitCode !== 0) {
                return 'git blame failed: ' . $blame->firstStderrLine();
            }
            $lines = self::parseBlame($blame->stdout);
        } else {
            // Not committed yet: every line belongs to the next commit.
            $raw = (string) @file_get_contents($where . '/' . OverrideFile::FILENAME);
            $lines = [];
            $total = substr_count($raw, "\n") + (str_ends_with($raw, "\n") ? 0 : 1);
            for ($number = 1; $number <= $total; $number++) {
                $lines[$number] = ['sha' => str_repeat('0', 40), 'name' => '', 'email' => ''];
            }
        }

        $hasUncommitted = false;
        foreach ($lines as $author) {
            if (preg_match('/^0+$/', $author['sha']) === 1) {
                $hasUncommitted = true;
                break;
            }
        }
        if ($hasUncommitted) {
            $ident = $this->git($git, ['var', 'GIT_AUTHOR_IDENT'], $context);
            if ($ident->exitCode !== 0 || preg_match('/^(.*?) <([^<>]*)> \d+ [+-]\d{4}$/', trim($ident->stdout), $match) !== 1) {
                return OverrideFile::FILENAME . ' has uncommitted lines and git has no author identity to credit them to (set user.name and user.email)';
            }
            foreach ($lines as $number => $author) {
                if (preg_match('/^0+$/', $author['sha']) === 1) {
                    $lines[$number] = ['sha' => $author['sha'], 'name' => $match[1], 'email' => $match[2]];
                }
            }
        }

        return [$lines, $shallow];
    }

    /**
     * Runs one read-only git command in the app path, with the variables
     * that would point git at another repository removed and optional
     * locks off, so PROCTOR never writes to the index.
     *
     * @param list<string> $args
     *
     * @throws ToolError when git cannot be started or times out
     */
    private function git(string $git, array $args, CheckContext $context): ProcessResult
    {
        $env = getenv();
        foreach (array_keys($env) as $key) {
            if (in_array(strtoupper((string) $key), self::REDIRECTING_GIT_VARS, true)) {
                unset($env[$key]);
            }
        }
        $env['GIT_OPTIONAL_LOCKS'] = '0';
        $env['GIT_TERMINAL_PROMPT'] = '0';

        return $this->process->run([$git, ...$args], $context->appPath, $context->timeout, $env);
    }

    /**
     * The shallow commits of the clone, from git's shallow file (absent in a
     * full clone).
     *
     * @return array<string, true>
     */
    private static function shallowCommits(string $appPath, string $shallowPath): array
    {
        if ($shallowPath === '') {
            return [];
        }
        $isAbsolute = str_starts_with($shallowPath, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $shallowPath) === 1;
        $file = $isAbsolute ? $shallowPath : $appPath . '/' . $shallowPath;
        if (!is_file($file)) {
            return [];
        }

        $commits = [];
        foreach (preg_split('/\R/', (string) @file_get_contents($file)) ?: [] as $sha) {
            $sha = strtolower(trim($sha));
            if ($sha !== '') {
                $commits[$sha] = true;
            }
        }

        return $commits;
    }

    /**
     * The author of each final line in `git blame --line-porcelain` output.
     *
     * @return array<int, array{sha: string, name: string, email: string}>
     */
    private static function parseBlame(string $output): array
    {
        $lines = [];
        $current = null;
        foreach (explode("\n", $output) as $row) {
            if ($current === null) {
                if (preg_match('/^([0-9a-f]{40}|[0-9a-f]{64}) \d+ (\d+)/', $row, $match) === 1) {
                    $current = ['line' => (int) $match[2], 'sha' => $match[1], 'name' => '', 'email' => ''];
                }

                continue;
            }
            if (str_starts_with($row, "\t")) {
                $lines[$current['line']] = ['sha' => $current['sha'], 'name' => $current['name'], 'email' => $current['email']];
                $current = null;

                continue;
            }
            if (str_starts_with($row, 'author ')) {
                $current['name'] = substr($row, 7);
            } elseif (str_starts_with($row, 'author-mail ')) {
                $current['email'] = trim(substr($row, 12), '<> ');
            }
        }

        return $lines;
    }

    /**
     * The lines of an entry minus the approver's signature line (see
     * entryLines()).
     *
     * @param list<array{key: string, start: int, end: int}> $members
     *
     * @return list<int>
     */
    private static function contentLines(int $start, int $end, array $members): array
    {
        $signature = [];
        $other = [];
        foreach ($members as $member) {
            foreach (range($member['start'], $member['end']) as $number) {
                if ($member['key'] === 'approved_by') {
                    $signature[$number] = true;
                } else {
                    $other[$number] = true;
                }
            }
        }

        $lines = [];
        foreach (range($start, $end) as $number) {
            if (isset($signature[$number]) && !isset($other[$number])) {
                continue;
            }
            $lines[] = $number;
        }

        return $lines;
    }

    /**
     * The forms an approved_by string can name a person by.
     *
     * @return list<string>
     */
    private static function approverForms(string $approvedBy): array
    {
        $value = trim($approvedBy);
        $candidates = preg_match('/^(.*?)\s*<([^<>]+)>$/', $value, $match) === 1
            ? [$match[1], $match[2]]
            : [$value];

        $forms = [];
        foreach ($candidates as $candidate) {
            $form = ltrim(self::normalize($candidate), '@');
            if ($form !== '') {
                $forms[] = $form;
            }
        }

        return $forms;
    }

    /**
     * The forms a git author is known by.
     *
     * @return list<string>
     */
    private static function authorForms(string $name, string $email): array
    {
        $forms = [];
        $name = self::normalize($name);
        if ($name !== '') {
            $forms[] = $name;
        }

        $email = self::normalize($email);
        $at = strrpos($email, '@');
        if ($email !== '' && $at !== false && $at > 0) {
            $forms[] = $email;
            $local = substr($email, 0, $at);
            $forms[] = $local;
            if (str_starts_with(substr($email, $at + 1), 'users.noreply.') && preg_match('/^\d+[-+](.+)$/', $local, $match) === 1) {
                $forms[] = $match[1];
            }
        }

        return array_values(array_unique($forms));
    }

    private static function normalize(string $value): string
    {
        $value = (string) preg_replace('/\s+/u', ' ', trim($value));

        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function notChecked(string $status, string $reason): Finding
    {
        return new Finding(
            $this->id(),
            $status,
            sprintf('not checked: %s, so PROCTOR cannot tell who wrote each override; run proctor check in a full git checkout', $reason),
            OverrideFile::FILENAME,
            1,
            null,
            'could-not-run',
        );
    }
}
