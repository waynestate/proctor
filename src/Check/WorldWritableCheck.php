<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use Waynestate\Proctor\Code\PhpFiles;
use Waynestate\Proctor\Code\RepoFiles;

/**
 * Warns when the repository sets mode 777 on anything.
 *
 * A world-writable file or folder lets any process on the host change what
 * the app runs or serves. CREED's production chapter says mode 777 is never
 * used; the web server gets write access to the folders it needs and nothing
 * more.
 *
 * Read line by line: shell scripts (*.sh, *.bash), Dockerfiles and
 * Containerfiles, Makefiles, .gitlab-ci.yml, and the `scripts` of
 * composer.json and package.json. A line is flagged when it runs chmod with
 * mode 777, 0777, a+rwx or ugo+rwx; a line whose first character is # is a
 * comment and is skipped, and a sticky 1777 (the /tmp pattern) is not 777.
 * PHP files are read as an AST: a chmod() call whose mode is the literal
 * 0777 is flagged. mkdir() with 0777 is not, because the process umask
 * still applies to it; chmod() ignores the umask.
 *
 * Only committed files count (the git-tracked files, or the files on disk
 * when git cannot be asked). vendor/, node_modules/, tests/ and .git/ are
 * skipped at any depth.
 *
 * Warnings, never a fail. The proctor does not prop the exam-room door open
 * for anyone who wanders by.
 */
final class WorldWritableCheck implements CheckInterface
{
    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', 'tests', '.git'];

    /** chmod with a mode that makes the target world-writable as 777 does. */
    private const CHMOD_PATTERN = '/(?<![\w-])chmod\s+(?:-[A-Za-z]+\s+|--[a-z-]+(?:=\S+)?\s+)*(0?777|a\+rwx|ugo\+rwx)(?![\w+])\s*(\S*)/';

    public function id(): string
    {
        return 'world-writable';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        $findings = [];
        $phpFiles = new PhpFiles();
        foreach (RepoFiles::list($appPath, $context->timeout, self::SKIP_DIRS)->files as $path) {
            $base = basename($path);
            if (str_ends_with(strtolower($base), '.php')) {
                foreach ($this->phpChmodLines($phpFiles, $appPath . '/' . $path) as $line) {
                    $findings[] = $this->warning($path, $line, 'chmod(..., 0777)');
                }

                continue;
            }
            if (in_array($base, ['composer.json', 'package.json'], true)) {
                foreach ($this->scriptLines($appPath . '/' . $path) as [$line, $text]) {
                    foreach ($this->chmodHits($text) as $hit) {
                        $findings[] = $this->warning($path, $line, $hit);
                    }
                }

                continue;
            }
            if (!$this->isScriptFile($path, $base)) {
                continue;
            }
            $lines = @file($appPath . '/' . $path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $index => $raw) {
                $text = ltrim((string) $raw);
                if ($text === '' || $text[0] === '#') {
                    continue;
                }
                foreach ($this->chmodHits($text) as $hit) {
                    $findings[] = $this->warning($path, $index + 1, $hit);
                }
            }
        }

        if ($findings === []) {
            return [new Finding($this->id(), Finding::STATUS_PASS, 'no chmod 777 found')];
        }

        return $findings;
    }

    private function isScriptFile(string $path, string $base): bool
    {
        if (in_array($base, ['Dockerfile', 'Containerfile', 'Makefile', 'makefile', 'GNUmakefile'], true)
            || str_starts_with($base, 'Dockerfile.')
            || str_ends_with($base, '.Dockerfile')) {
            return !str_ends_with($base, '.dockerignore');
        }
        if ($path === '.gitlab-ci.yml') {
            return true;
        }

        return preg_match('/\.(sh|bash)$/i', $base) === 1;
    }

    /**
     * The chmod commands on a line that set mode 777, as "chmod MODE TARGET".
     *
     * @return list<string>
     */
    private function chmodHits(string $text): array
    {
        if (preg_match_all(self::CHMOD_PATTERN, $text, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }
        $hits = [];
        foreach ($matches as $m) {
            $target = trim($m[2], '"\';&|');
            $hits[] = trim('chmod ' . $m[1] . ' ' . $target);
        }

        return $hits;
    }

    /**
     * The script lines of composer.json or package.json: [line, text] for
     * every string under "scripts", with the line it sits on in the file.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function scriptLines(string $absolute): array
    {
        $raw = @file_get_contents($absolute);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || !is_array($data['scripts'] ?? null)) {
            return [];
        }
        $strings = [];
        array_walk_recursive($data['scripts'], static function (mixed $value) use (&$strings): void {
            if (is_string($value)) {
                $strings[] = $value;
            }
        });

        $lines = explode("\n", (string) $raw);
        $found = [];
        foreach ($strings as $script) {
            if ($this->chmodHits($script) === []) {
                continue;
            }
            $encoded = substr((string) json_encode($script, JSON_UNESCAPED_SLASHES), 1, -1);
            $line = 1;
            foreach ($lines as $index => $text) {
                if (str_contains($text, $encoded)) {
                    $line = $index + 1;
                    break;
                }
            }
            $found[] = [$line, $script];
        }

        return $found;
    }

    /**
     * The lines of chmod() calls whose mode is the literal 0777.
     *
     * @return list<int>
     */
    private function phpChmodLines(PhpFiles $phpFiles, string $absolute): array
    {
        $statements = $phpFiles->statements($absolute);
        if ($statements === null) {
            return [];
        }
        $lines = [];
        foreach ($phpFiles->findInstanceOf($phpFiles->resolveNames($statements), FuncCall::class) as $call) {
            if (!$call->name instanceof Name || strtolower($call->name->toString()) !== 'chmod') {
                continue;
            }
            $mode = $call->getArgs()[1] ?? null;
            if ($mode !== null && $mode->value instanceof Int_ && $mode->value->value === 0777) {
                $lines[] = $call->getStartLine();
            }
        }

        return $lines;
    }

    private function warning(string $path, int $line, string $what): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                '%s:%d runs %s, which makes it world-writable: any process on the host can change it. Give the web server user write access to the folders it needs (storage/ and bootstrap/cache/ in Laravel) and nothing more',
                $path,
                $line,
                $what,
            ),
            $path,
            $line,
        );
    }
}
