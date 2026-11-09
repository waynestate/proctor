<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Policy\PolicyResolver;

/**
 * CREED's handbook tags each rule "PROCTOR check: `<id>`" (or "PROCTOR
 * checks: `<id>` and `<id>`"), and its prose says "PROCTOR's `<id>` check".
 * This test reads that handbook and fails when either names an id PROCTOR
 * does not register, so a rule cannot point readers at a check that was
 * never shipped, or was renamed (the dependencies chapter once named
 * `package-sources` and `internal-vendor-shadowing`, which never existed).
 *
 * PROCTOR does not bundle the handbook (src/Creed holds the reader and the
 * policy only), and the Composer dist of waynestate/creed export-ignores
 * handbook/. So the handbook comes from a CREED checkout: CREED_PATH when it
 * is set (the same variable creed-copy:check reads), else the sibling
 * ../creed checkout, else vendor/waynestate/creed when it was installed from
 * source. With none of them, the check is skipped with a message that says
 * so. The parser itself is tested on a fixed text every run.
 *
 * The proctor checks that every seat on the plan has a student in it.
 */
final class CreedHandbookCheckIdsTest extends TestCase
{
    public function testEveryCheckTheHandbookNamesIsARegisteredCheck(): void
    {
        $handbook = self::handbookDir();
        if ($handbook === null) {
            self::markTestSkipped(
                'CREED handbook not found: set CREED_PATH to a CREED checkout (or keep one at ../creed) to check the '
                . 'handbook\'s "PROCTOR check:" ids; the waynestate/creed Composer package ships without handbook/.',
            );
        }

        $registered = CheckSet::ids(CheckSet::all(PolicyResolver::resolve(null, sys_get_temp_dir())));
        $files = glob($handbook . '/*.md') ?: [];
        sort($files);
        self::assertNotEmpty($files, sprintf('no chapters in %s', $handbook));

        $named = 0;
        $unknown = [];
        foreach ($files as $file) {
            foreach (self::namedIds((string) file_get_contents($file)) as [$line, $id]) {
                $named++;
                if (!in_array($id, $registered, true)) {
                    $unknown[] = sprintf('%s:%d names `%s`', basename($file), $line, $id === '' ? '(no id)' : $id);
                }
            }
        }

        self::assertGreaterThan(0, $named, 'the handbook names no PROCTOR check at all; has the tag changed?');
        self::assertSame(
            [],
            $unknown,
            "The CREED handbook names PROCTOR checks that CheckSet::all() does not register.\n"
            . 'Fix the tag in CREED, or ship the check: ' . implode('; ', $unknown),
        );
    }

    public function testTheParserReadsEveryTagShape(): void
    {
        $text = implode("\n", [
            '# Chapter',                                                                    // 1
            '',                                                                             // 2
            '- A rule. PROCTOR check: `php-floor`.',                                        // 3
            '- A wrapped rule (a decision). PROCTOR check:',                                // 4
            '  `repo-url-credentials`.',                                                    // 5
            '- Two checks. PROCTOR checks: `app-key` (a committed `.env` or `APP_KEY`)',   // 6
            '  and `served-folder` (dumps in `public/`). Preflight\'s `sbom` is not one.', // 7
            '- A list. PROCTOR checks: `mysql56`, `mysql-eol` or `mysql-minimum`.',        // 8
            '- A placeholder: "PROCTOR check: `<id>`" names the check.',                    // 9
            '- A bad tag. PROCTOR check: `Not An Id`.',                                     // 10
            '- An empty tag. PROCTOR check:',                                               // 11
            '- No tag here: a PROCTOR check for this is planned.',                          // 12
            '```',                                                                          // 13
            'PROCTOR check: `in-a-code-block`',                                             // 14
            '```',                                                                          // 15
            'PROCTOR check: `app-debug`, on PROCTOR main (`proctor/app-debug/enabled`).',   // 16
            'PROCTOR\'s `repo-url-credentials`, `package-sources` and',                     // 17
            '`internal-vendor-shadowing` checks enforce rules 1, 2 and 4.',                 // 18
            'PROCTOR\'s own pipeline runs `proctor check`; PROCTOR\'s `mysql56` check flags it.', // 19
            '',
        ]);

        self::assertSame([
            [3, 'php-floor'],
            [4, 'repo-url-credentials'],
            [6, 'app-key'],
            [6, 'served-folder'],
            [8, 'mysql56'],
            [8, 'mysql-eol'],
            [8, 'mysql-minimum'],
            [10, 'Not An Id'],
            [11, ''],
            [16, 'app-debug'],
            [17, 'repo-url-credentials'],
            [17, 'package-sources'],
            [17, 'internal-vendor-shadowing'],
            [19, 'mysql56'],
        ], self::namedIds($text));
    }

    /**
     * Every id the text's "PROCTOR check:" and "PROCTOR checks:" tags name:
     * [1-based line of the tag, id]. The ids are the code spans right after
     * the tag, joined by commas, "and" or "or", once parenthesized asides
     * are dropped; the tag ends with its list item or paragraph. A
     * placeholder (`<id>`) is skipped; a tag with no code span after it
     * yields an empty id, so it fails as unknown. The prose shape, "PROCTOR's
     * `<id>` check" or a list of code spans before "checks", counts too.
     * Fenced code is not read.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function namedIds(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Blank out fenced code, keeping its newlines so line numbers hold.
        $text = (string) preg_replace_callback(
            '/^\s*```.*?^\s*```[^\n]*$/ms',
            static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $text,
        );

        // A list of code spans joined by commas, "and" or "or".
        $list = '`[^`]+`(?:\s*,?\s*(?:(?:and|or)\s+)?`[^`]+`)*';
        $found = [];

        preg_match_all('/PROCTOR checks?:/', $text, $tags, PREG_OFFSET_CAPTURE);
        foreach ($tags[0] as [$tag, $offset]) {
            $rest = substr($text, $offset + strlen($tag));
            // The tag's list item or paragraph ends at a blank line, the next
            // list item or a heading.
            $rest = (preg_split('/\n\s*\n|\n\s*[-*] |\n#/', $rest, 2) ?: [''])[0];
            do {
                $before = $rest;
                $rest = (string) preg_replace('/\([^()]*\)/', '', $rest);
            } while ($rest !== $before);

            $found[] = preg_match('/^\s*(' . $list . ')/', $rest, $match) === 1
                ? [$offset, self::spans($match[1])]
                : [$offset, ['']];
        }

        // The prose shape: "PROCTOR's `a`, `b` and `c` checks enforce ...".
        preg_match_all('/PROCTOR\'s\s+(' . $list . ')\s+checks?\b/', $text, $prose, PREG_OFFSET_CAPTURE);
        foreach ($prose[1] as [$spans, $offset]) {
            $found[] = [$offset, self::spans($spans)];
        }

        usort($found, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $named = [];
        foreach ($found as [$offset, $ids]) {
            $line = substr_count(substr($text, 0, $offset), "\n") + 1;
            foreach ($ids as $id) {
                $named[] = [$line, $id];
            }
        }

        return $named;
    }

    /**
     * The ids in a list of code spans, placeholders (`<id>`) left out.
     *
     * @return list<string>
     */
    private static function spans(string $list): array
    {
        preg_match_all('/`([^`]+)`/', $list, $spans);

        return array_values(array_filter($spans[1], static fn(string $id): bool => !str_starts_with($id, '<')));
    }

    /**
     * The handbook folder of the CREED checkout in reach, or null. A
     * CREED_PATH that is set but holds no handbook is a mistake, not a
     * reason to skip, so it fails.
     */
    private static function handbookDir(): ?string
    {
        $creedPath = getenv('CREED_PATH');
        if ($creedPath !== false && $creedPath !== '') {
            $dir = rtrim($creedPath, '/\\') . '/handbook';
            self::assertDirectoryExists($dir, sprintf('CREED_PATH is set to %s, which has no handbook/ folder', $creedPath));

            return $dir;
        }

        $root = dirname(__DIR__);
        foreach ([dirname($root) . '/creed', $root . '/vendor/waynestate/creed'] as $candidate) {
            if (is_dir($candidate . '/handbook')) {
                return $candidate . '/handbook';
            }
        }

        return null;
    }
}
