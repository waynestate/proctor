<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\OverrideApplier;
use Waynestate\Proctor\Check\OverrideApproverCheck;
use Waynestate\Proctor\Check\ProcessRunner;
use Waynestate\Proctor\Code\RepoFiles;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\Override\OverrideFile;
use Waynestate\Proctor\ToolError;

/**
 * The override-approver check against real git repositories in a temp dir:
 * who wrote each entry comes from git blame, and approved_by must name
 * someone else. No candidate may sign their own hall pass.
 *
 * The people here are made up: Avery Author writes overrides, Riley
 * Reviewer approves them.
 */
final class OverrideApproverCheckTest extends ProctorTestCase
{
    private const AUTHOR = ['Avery Author', 'avery@example.test'];

    private const REVIEWER = ['Riley Reviewer', 'riley@example.test'];

    /** @var string|null the temp dir of this test, null before setUp */
    private ?string $tempDir = null;

    private ProcessRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new ProcessRunner();
        $this->tempDir = sys_get_temp_dir() . '/proctor-override-approver-' . bin2hex(random_bytes(6));
        if (! mkdir($this->tempDir . '/repo', 0o777, true)) {
            self::fail('could not create the temp dir');
        }
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            $this->removeTree($this->tempDir);
        }
        $this->tempDir = null;
    }

    public function testAnOverrideWrittenAndApprovedInOneCommitBySamePersonWarns(): void
    {
        $this->requireGit();
        $this->gitInit();
        $this->writeOverrides([$this->entry('lock-committed', 'Avery Author')]);
        $this->commitAs(self::AUTHOR, 'add override');

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/override-approver/self-approved', $findings[0]->ruleId);
        self::assertSame('policy-overrides.json', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
        self::assertMatchesRegularExpression(
            '/^override for "lock-committed" is approved by "Avery Author", who also wrote it \(commit [0-9a-f]{8}\); someone who did not write the entry must approve it$/',
            $findings[0]->message,
        );
    }

    public function testAnotherPersonSigningTheApprovedByLinePasses(): void
    {
        $this->requireGit();
        $this->gitInit();
        $this->writeOverrides([$this->entry('lock-committed', 'pending review')]);
        $this->commitAs(self::AUTHOR, 'add override');
        // The reviewer writes their own name on the signature line only.
        $this->writeOverrides([$this->entry('lock-committed', 'riley@example.test')]);
        $this->commitAs(self::REVIEWER, 'approve override');

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/override-approver', $findings[0]->ruleId);
        self::assertSame('no override is approved by its own author (1 entry checked against git)', $findings[0]->message);
    }

    public function testAnApproverWhoExtendsTheirOwnApprovalIsSelfApproved(): void
    {
        $this->requireGit();
        $this->gitInit();
        $this->writeOverrides([$this->entry('lock-committed', 'Riley Reviewer')]);
        $this->commitAs(self::AUTHOR, 'add override, approved by the reviewer');
        self::assertSame(Finding::STATUS_PASS, $this->check()[0]->status);

        // The approver pushes the expiry out themselves: a new approval of
        // their own change.
        $this->writeOverrides([$this->entry('lock-committed', 'Riley Reviewer', '2027-01-15')]);
        $this->commitAs(self::REVIEWER, 'extend override');

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame('proctor/override-approver/self-approved', $findings[0]->ruleId);
        self::assertStringContainsString('approved by "Riley Reviewer", who also wrote it', $findings[0]->message);
    }

    public function testOnlyTheSelfApprovedEntryOfSeveralIsReported(): void
    {
        $this->requireGit();
        $this->gitInit();
        $this->writeOverrides([
            $this->entry('lock-committed', 'Riley Reviewer'),
            $this->entry('composer-audit', 'avery@example.test'),
        ]);
        $this->commitAs(self::AUTHOR, 'add overrides');

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame('proctor/override-approver/self-approved', $findings[0]->ruleId);
        self::assertStringStartsWith('override for "composer-audit" is approved by "avery@example.test"', $findings[0]->message);
        self::assertSame(8, $findings[0]->line);
    }

    public function testTheFailPolicyMakesASelfApprovalAFailure(): void
    {
        $this->requireGit();
        $this->gitInit();
        $this->writeOverrides([$this->entry('lock-committed', '@avery')]);
        $this->commitAs(['Avery Author', 'avery@example.test'], 'add override');

        $findings = $this->check($this->policyWith('fail'));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('proctor/override-approver/self-approved', $findings[0]->ruleId);
    }

    public function testAnInvalidPolicyLevelIsAToolError(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('Policy rules carry an invalid "override_self_approval" value: \'block\'; expected "warn" or "fail".');

        OverrideApproverCheck::level($this->policyWith('block'));
    }

    public function testThePolicyLevelDefaultsToWarnWhenTheKeyIsAbsent(): void
    {
        self::assertSame('warn', OverrideApproverCheck::level(Policy::fromFile(__DIR__ . '/fixtures/policy.json')));
    }

    public function testAShallowCloneNeverCreditsOldLinesToTheBoundaryCommit(): void
    {
        $this->requireGit();
        $this->gitInit();
        $this->writeOverrides([$this->entry('lock-committed', 'Riley Reviewer')]);
        $this->commitAs(self::AUTHOR, 'add override, approved by the reviewer');
        $this->writeFile('repo/notes.txt', "later work\n");
        $this->commitAs(self::REVIEWER, 'unrelated change by the approver');

        // Depth 1: the only commit is the reviewer's, and blame credits every
        // older line to it. Reading that as authorship would be a false
        // self-approval, so the entry is not checked instead.
        $clone = $this->shallowClone(1);
        $findings = $this->check(null, $clone);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/override-approver/could-not-run', $findings[0]->ruleId);
        self::assertStringStartsWith('not checked: override for "lock-committed" was last changed at or before the oldest commit of this shallow clone', $findings[0]->message);
        self::assertStringContainsString('GIT_DEPTH: 0', $findings[0]->message);
    }

    public function testAShallowCloneStillCatchesASelfApprovalInsideItsHistory(): void
    {
        $this->requireGit();
        $this->gitInit();
        $this->writeFile('repo/notes.txt', "first\n");
        $this->commitAs(self::REVIEWER, 'older work');
        $this->writeOverrides([$this->entry('lock-committed', 'Avery Author')]);
        $this->commitAs(self::AUTHOR, 'add override, self-approved');

        $clone = $this->shallowClone(2);
        $findings = $this->check(null, $clone);

        self::assertCount(1, $findings);
        self::assertSame('proctor/override-approver/self-approved', $findings[0]->ruleId);
    }

    public function testAnAppOutsideAGitCheckoutIsNotChecked(): void
    {
        $this->requireGit();
        $this->writeOverrides([$this->entry('lock-committed', 'Avery Author')]);

        $findings = $this->check($this->policyWith('fail'));

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('proctor/override-approver/could-not-run', $findings[0]->ruleId);
        self::assertStringStartsWith('not checked: the app path is not inside a git checkout', $findings[0]->message);
    }

    public function testAnUncommittedOverrideIsCreditedToTheNextCommitsAuthor(): void
    {
        $this->requireGit();
        $this->gitInit();
        $this->git('config', 'user.name', 'Avery Author');
        $this->git('config', 'user.email', 'avery@example.test');
        $this->writeFile('repo/README.md', "app\n");
        $this->commitAs(self::AUTHOR, 'first');
        $this->writeOverrides([$this->entry('lock-committed', 'Avery Author <avery@example.test>')]);

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame('proctor/override-approver/self-approved', $findings[0]->ruleId);
        self::assertStringContainsString('who also wrote it (an uncommitted change)', $findings[0]->message);
    }

    public function testAnAppWithoutOverridesHasNoFindings(): void
    {
        $context = new CheckContext(
            (string) $this->tempDir,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY),
        );

        self::assertSame([], (new OverrideApproverCheck())->run($context));
    }

    public function testWithoutGitOnPathTheCliReportsTheCheckCouldNotRun(): void
    {
        // The CLI tests run with a PATH that finds no tools, so no git.
        $result = $this->runProctor($this->app('override-expiring-app'), 'json');

        $data = json_decode($result['stdout'], true);
        self::assertIsArray($data);
        $found = array_values(array_filter(
            $data['findings'],
            static fn(array $finding): bool => $finding['check_id'] === OverrideApproverCheck::ID,
        ));
        self::assertCount(1, $found);
        self::assertSame('proctor/override-approver/could-not-run', $found[0]['rule_id']);
        self::assertSame('warning', $found[0]['status']);
        self::assertStringStartsWith('not checked: git is not on PATH', $found[0]['message']);
    }

    public function testAnOverrideCannotTargetTheApproverCheck(): void
    {
        $this->writeOverrides([$this->entry(OverrideApproverCheck::ID, 'Riley Reviewer')]);

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('an override cannot target the override-approver check.');

        OverrideFile::load($this->tempDir . '/repo');
    }

    public function testTheApplierNeverDowngradesAnApproverFailure(): void
    {
        $fail = new Finding(OverrideApproverCheck::ID, Finding::STATUS_FAIL, 'self-approved', 'policy-overrides.json', 2, null, 'self-approved');
        $applier = new OverrideApplier(
            [new Override(OverrideApproverCheck::ID, 'loophole', new DateTimeImmutable('2026-11-01'), 'someone')],
            new DateTimeImmutable(self::TODAY),
        );

        self::assertSame(Finding::STATUS_FAIL, $applier->apply([$fail])[0]->status);
    }

    public function testTheDeployGateDoesNotRunTheApproverCheck(): void
    {
        self::assertNotContains(OverrideApproverCheck::ID, CheckSet::GATE);
    }

    public function testEntryLinesLeaveOutALineThatOnlySignsTheEntry(): void
    {
        $raw = "[\n    {\n        \"check\": \"a\",\n        \"approved_by\": \"x\"\n    },\n"
            . "    {\"check\": \"b\", \"approved_by\": \"y\"}\n]\n";

        self::assertSame(
            [
                ['start' => 2, 'lines' => [2, 3, 5]],
                // One line holds every member, so the signature stays in.
                ['start' => 6, 'lines' => [6]],
            ],
            OverrideApproverCheck::entryLines($raw),
        );
    }

    public function testEntryLinesReadBracesAndQuotesInsideStrings(): void
    {
        $raw = "[{\"check\": \"a\", \"reason\": \"a } brace, a \\\" quote and a , comma\",\n"
            . "\"approved_by\": \"x\"}]";

        self::assertSame([['start' => 1, 'lines' => [1]]], OverrideApproverCheck::entryLines($raw));
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function approverForms(): iterable
    {
        yield 'the author name' => ['Avery Author', 'Avery Author', 'avery@example.test', true];
        yield 'case and spacing' => ['  avery   AUTHOR ', 'Avery Author', 'avery@example.test', true];
        yield 'the author email' => ['AVERY@example.test', 'Avery Author', 'avery@example.test', true];
        yield 'the email local part' => ['avery', 'Avery Author', 'avery@example.test', true];
        yield 'a mention' => ['@avery', 'Avery Author', 'avery@example.test', true];
        yield 'name and email' => ['Someone Else <avery@example.test>', 'Avery Author', 'avery@example.test', true];
        yield 'a GitLab noreply username' => ['@avery.a', 'Avery Author', '1234-avery.a@users.noreply.gitlab.example.test', true];
        yield 'a GitHub noreply username' => ['avery-a', 'Avery Author', '1234+avery-a@users.noreply.github.com', true];
        yield 'another person' => ['Riley Reviewer', 'Avery Author', 'avery@example.test', false];
        yield 'another domain with the same local part' => ['avery@other.example.test', 'Avery Author', 'avery@example.test', false];
        yield 'part of the name' => ['Avery', 'Avery Author', 'aa@example.test', false];
    }

    #[DataProvider('approverForms')]
    public function testApproverMatchesTheAuthorsForms(string $approvedBy, string $name, string $email, bool $expected): void
    {
        self::assertSame($expected, OverrideApproverCheck::namesAuthor($approvedBy, $name, $email));
    }

    /**
     * @return list<Finding>
     */
    private function check(?Policy $policy = null, ?string $appPath = null): array
    {
        $appPath ??= $this->tempDir . '/repo';
        $context = new CheckContext(
            $appPath,
            $policy ?? Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY),
            OverrideFile::load($appPath),
        );

        return (new OverrideApproverCheck())->run($context);
    }

    private function policyWith(string $level): Policy
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/policy.json'), true);
        self::assertIsArray($data);
        $data['rules'][OverrideApproverCheck::POLICY_KEY] = $level;

        return Policy::fromArray($data);
    }

    /**
     * @return array<string, string>
     */
    private function entry(string $check, string $approvedBy, string $expires = '2026-12-01'): array
    {
        return ['check' => $check, 'reason' => 'fixture', 'expires' => $expires, 'approved_by' => $approvedBy];
    }

    /**
     * Writes the entries pretty-printed, one member per line, the way a
     * developer writes the file: entry 1 opens on line 2, entry 2 on line 8.
     *
     * @param list<array<string, string>> $entries
     */
    private function writeOverrides(array $entries): void
    {
        $this->writeFile('repo/policy-overrides.json', json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function writeFile(string $relativePath, string $content): void
    {
        $path = $this->tempDir . '/' . $relativePath;
        if (file_put_contents($path, $content) === false) {
            self::fail("could not write {$path}");
        }
    }

    private function requireGit(): void
    {
        if (RepoFiles::findGit() === null) {
            self::markTestSkipped('git is not on PATH');
        }
    }

    private function gitInit(): void
    {
        $this->git('init', '-q');
        $this->git('config', 'commit.gpgsign', 'false');
    }

    /**
     * @param array{string, string} $who name and email
     */
    private function commitAs(array $who, string $message): void
    {
        $this->git('add', '-A');
        $this->git('-c', 'user.name=' . $who[0], '-c', 'user.email=' . $who[1], 'commit', '-q', '-m', $message);
    }

    /**
     * A shallow clone of the repo at the given depth, returning its path.
     */
    private function shallowClone(int $depth): string
    {
        $clone = $this->tempDir . '/clone';
        $this->git('clone', '-q', '--depth', (string) $depth, 'file://' . $this->tempDir . '/repo', $clone);

        return $clone;
    }

    /**
     * Runs git in the repo and fails the test when it exits nonzero.
     */
    private function git(string ...$args): void
    {
        try {
            $result = $this->runner->run(
                array_merge(['git', '-C', $this->tempDir . '/repo'], $args),
                (string) $this->tempDir,
                30,
            );
        } catch (ToolError $e) {
            self::fail('git could not be run: ' . $e->getMessage());
        }
        if ($result->exitCode !== 0) {
            self::fail(sprintf('git %s failed: %s', implode(' ', $args), trim($result->stderr)));
        }
    }

    /** Best-effort recursive delete of the temp dir. */
    private function removeTree(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}
