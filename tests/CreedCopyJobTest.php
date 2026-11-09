<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the creed-copy-refresh job in .gitlab-ci.yml and
 * scripts/creed-copy-refresh.sh honest, read as text: the job runs only on
 * its schedule or by hand on the default branch, the script proves the copy
 * (creed-copy:check, then composer ci) before it commits and pushes, it
 * pushes to the default branch without force, and no line prints the token
 * URL. The CI file is not parsed with a YAML library.
 */
final class CreedCopyJobTest extends TestCase
{
    private const SCRIPT = 'scripts/creed-copy-refresh.sh';

    public function testTheJobRunsOnItsScheduleOrByHandAndOnlyOnTheDefaultBranch(): void
    {
        $block = self::jobBlock('creed-copy-refresh');

        self::assertStringContainsString(
            "- if: '\$CI_PIPELINE_SOURCE == \"schedule\" && \$PROCTOR_JOB == \"creed-copy\" && \$CI_COMMIT_BRANCH == \$CI_DEFAULT_BRANCH'",
            $block,
        );
        self::assertStringContainsString(
            "- if: '\$CI_PIPELINE_SOURCE == \"web\" && \$CI_COMMIT_BRANCH == \$CI_DEFAULT_BRANCH'",
            $block,
        );
        self::assertSame(2, preg_match_all('/^\s*- if:/m', $block), 'Exactly the schedule rule and the web rule.');
        foreach (explode("\n", $block) as $line) {
            if (str_contains($line, '- if:')) {
                self::assertStringContainsString('$CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH', $line, 'Every rule keeps the default-branch guard.');
            }
        }
    }

    public function testTheJobUsesTheCachedComposerImageFullHistoryAndTheScript(): void
    {
        $block = self::jobBlock('creed-copy-refresh');

        self::assertStringContainsString('extends: .composer-cache', $block);
        self::assertStringContainsString('image: ${CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX}/composer:2.10.3', $block);
        self::assertStringContainsString('GIT_DEPTH: "0"', $block);
        self::assertStringContainsString('- sh ' . self::SCRIPT, $block);
    }

    public function testTheScriptProvesTheCopyBeforeItCommitsAndPushes(): void
    {
        self::assertSame(0, self::firstCommand('/^set -eu$/'), 'set -eu comes first.');

        $clone = self::firstCommand('/^if ! git clone -q /');
        $export = self::firstCommand('/^php "\$TMP\/creed\/bin\/export\.php" --namespace="Waynestate\\\\Proctor\\\\Creed" --to=src\/Creed$/');
        $check = self::firstCommand('/^CREED_PATH="\$TMP\/creed" composer creed-copy:check$/');
        $ci = self::firstCommand('/^CREED_PATH="\$TMP\/creed" composer ci$/');
        $commit = self::firstCommand('/^git .*\bcommit\b/');
        $push = self::firstCommand('/git push\b/');

        self::assertLessThan($export, $clone);
        self::assertLessThan($check, $export);
        self::assertLessThan($ci, $check);
        self::assertLessThan($commit, $ci);
        self::assertLessThan($push, $commit);
    }

    public function testItPushesToTheDefaultBranchWithoutForce(): void
    {
        $push = self::commands()[self::firstCommand('/git push\b/')];
        self::assertStringContainsString('"HEAD:refs/heads/${CI_DEFAULT_BRANCH}"', $push);

        foreach (self::commands() as $code) {
            self::assertDoesNotMatchRegularExpression('/--force|\s-f\b|"\+HEAD|\s\+HEAD/', $code, "No force push: {$code}");
        }
    }

    public function testItStagesNamedPathsAndNeverEverything(): void
    {
        $add = self::commands()[self::firstCommand('/^git add\b/')];
        self::assertStringStartsWith('git add -- src/Creed ', $add);

        foreach (self::commands() as $code) {
            self::assertDoesNotMatchRegularExpression('/git add (-A|--all|\.)(\s|$)|git commit .*-a\b/', $code, "Stage by name only: {$code}");
        }
    }

    public function testNoLinePrintsTheTokenUrl(): void
    {
        $lines = array_merge(explode("\n", self::file(self::SCRIPT)), explode("\n", self::jobBlock('creed-copy-refresh')));

        foreach ($lines as $line) {
            self::assertStringNotContainsString('set -x', $line, 'No shell tracing: it would print the token URL.');
            if (!str_contains($line, 'CI_JOB_TOKEN') && !str_contains($line, 'gitlab-ci-token:$')) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/\b(echo|printf|printenv|env)\b/', $line, "The token on a printing line: {$line}");
        }
    }

    public function testTheMessagesAndTheCleanupAreExact(): void
    {
        $script = self::file(self::SCRIPT);

        self::assertStringContainsString('die "could not clone CREED with the job token"', $script);
        self::assertStringContainsString('echo "CREED copy already matches CREED $VERSION; nothing to do"', $script);
        self::assertStringContainsString('echo "pushed the CREED $VERSION copy to ${CI_DEFAULT_BRANCH}"', $script);
        self::assertStringContainsString('git -C "$TMP/creed" describe --tags --always', $script);
        self::assertStringContainsString("trap 'rm -rf \"\$TMP\"' EXIT", $script);

        foreach (self::commands() as $code) {
            if (str_contains($code, 'rm -rf')) {
                self::assertSame("trap 'rm -rf \"\$TMP\"' EXIT", $code, 'rm -rf only ever cleans $TMP.');
            }
        }
    }

    /**
     * A repo file as text.
     */
    private static function file(string $path): string
    {
        $text = @file_get_contents(__DIR__ . '/../' . $path);
        self::assertIsString($text, "{$path} is missing.");

        return $text;
    }

    /**
     * The body of one top-level job: from its key to the next top-level key.
     */
    private static function jobBlock(string $job): string
    {
        self::assertSame(
            1,
            preg_match('/^' . preg_quote($job, '/') . ':\n((?:^[ \t].*\n?|\n)*)/m', self::file('.gitlab-ci.yml'), $m),
            "The {$job} job is missing from .gitlab-ci.yml.",
        );

        return $m[1];
    }

    /**
     * The script's command lines: trimmed, without blank lines and comments.
     *
     * @return list<string>
     */
    private static function commands(): array
    {
        $commands = [];
        foreach (explode("\n", self::file(self::SCRIPT)) as $line) {
            $code = trim($line);
            if ($code !== '' && !str_starts_with($code, '#')) {
                $commands[] = $code;
            }
        }

        return $commands;
    }

    /**
     * Index of the first command line matching the pattern.
     */
    private static function firstCommand(string $pattern): int
    {
        foreach (self::commands() as $i => $code) {
            if (preg_match($pattern, $code) === 1) {
                return $i;
            }
        }
        self::fail('No command line in ' . self::SCRIPT . " matches {$pattern}.");
    }
}
