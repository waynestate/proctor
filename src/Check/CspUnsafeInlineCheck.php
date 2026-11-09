<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Warns when committed app config, server config or an example env file
 * lets scripts run inline ('unsafe-inline' in a script source).
 *
 * CREED runtime-and-headers says an app should not weaken the default
 * Content-Security-Policy with 'unsafe-inline' for scripts: HELMET gives
 * every page a nonce instead. PROCTOR reads every *.php directly in
 * config/ and bootstrap/, public/index.php and every *.php at any depth
 * under app/; every .htaccess at the app root and at any depth under
 * public/, deploy/, deployments/ and docker/, and every *.conf at any
 * depth under deploy/, deployments/ and docker/; and the HELMET_CSP= lines
 * of .env.example, .env.production and .env.squire.example. When the run
 * carries an EnvFileFilter (ADVISOR's in-process run), an env file it
 * refuses is not opened.
 *
 * A directive counts when its name is script-src, script-src-elem or
 * script-src-attr (case-insensitive). style-src and its -elem and -attr
 * forms never warn: HELMET's own default keeps style-src-attr
 * 'unsafe-inline'. A nonce or 'strict-dynamic' beside it still warns, and
 * so does a report-only policy.
 *
 * Emits proctor/csp-unsafe-inline on pass, and
 * proctor/csp-unsafe-inline/script-src on warning. Every finding is a
 * warning, never a failure.
 *
 * One warning per file, on the line of its first offending
 * 'unsafe-inline'. The proctor reads only the notes the hall hands out,
 * never ones slipped in through the window.
 */
final class CspUnsafeInlineCheck implements CheckInterface
{
    /** Top-level directories whose .htaccess files are read at any depth. */
    private const HTACCESS_DIRS = ['public', 'deploy', 'deployments', 'docker'];

    /** Top-level directories whose *.conf files are read at any depth. */
    private const CONF_DIRS = ['deploy', 'deployments', 'docker'];

    /** Top-level directories whose *.php files are read only when they sit directly inside. */
    private const PHP_FLAT_DIRS = ['config', 'bootstrap'];

    /** Top-level directory whose *.php files are read at any depth. */
    private const PHP_DEEP_DIR = 'app';

    /** Root env files, read only on their HELMET_CSP= lines. */
    private const ENV_FILES = ['.env.example', '.env.production', '.env.squire.example'];

    /** The env line prefix that carries a policy. */
    private const ENV_PREFIX = 'HELMET_CSP=';

    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git', 'tests'];

    /** A script directive name, not part of a longer name (case-insensitive). */
    private const SCRIPT_DIRECTIVE = '/(?<![a-z0-9-])script-src(?:-elem|-attr)?(?![a-z0-9-])/i';

    /** A script directive as a quoted PHP array key, then =>, then the opening [ of its value list. */
    private const SCRIPT_ARRAY_KEY = '/([\'"])script-src(?:-elem|-attr)?\1\s*=>\s*\[/i';

    /** The keyword with its single quotes, which a single-quoted PHP string may escape. */
    private const UNSAFE_INLINE = "/\\\\?'unsafe-inline\\\\?'/i";

    /** A line whose first non-space characters open a comment or continue a docblock. */
    private const COMMENT_LINE = '/^\s*(?:\/\/|#|\*)/';

    public function id(): string
    {
        return 'csp-unsafe-inline';
    }

    public function run(CheckContext $context): array
    {
        $findings = [];
        foreach ($this->filesToScan($context->appPath) as $relativePath) {
            if (in_array($relativePath, self::ENV_FILES, true) && !$context->mayReadEnvFile($this->id(), $relativePath)) {
                continue;
            }
            $raw = file_get_contents($context->appPath . '/' . $relativePath);
            if ($raw === false) {
                continue;
            }
            $line = $this->firstOffendingLine($raw, $relativePath);
            if ($line !== null) {
                $findings[] = $this->warn($relativePath, $line);
            }
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no committed config lets scripts run inline (\'unsafe-inline\' in script-src)',
            )];
        }

        return $findings;
    }

    /**
     * The relative paths (forward slashes) this check scans, sorted.
     *
     * @param string $appPath the application path
     *
     * @return list<string>
     */
    private function filesToScan(string $appPath): array
    {
        $appPath = rtrim($appPath, '/\\');
        $paths = [];

        foreach (array_merge(['.htaccess', 'public/index.php'], self::ENV_FILES) as $relative) {
            if (is_file($appPath . '/' . $relative)) {
                $paths[$relative] = $relative;
            }
        }

        foreach (self::PHP_FLAT_DIRS as $dir) {
            $base = $appPath . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            foreach (scandir($base) ?: [] as $entry) {
                if (str_ends_with($entry, '.php') && is_file($base . '/' . $entry)) {
                    $relative = $dir . '/' . $entry;
                    $paths[$relative] = $relative;
                }
            }
        }

        foreach (array_unique(array_merge(self::HTACCESS_DIRS, self::CONF_DIRS, [self::PHP_DEEP_DIR])) as $dir) {
            foreach ($this->walk($appPath, $dir) as $relative => $name) {
                if ($this->isScannedName($name, $dir)) {
                    $paths[$relative] = $relative;
                }
            }
        }

        $paths = array_values($paths);
        sort($paths);

        return $paths;
    }

    /**
     * Every file at any depth under one top-level directory, keyed by its
     * relative path (forward slashes), with its base name as the value.
     * The skip directories are not entered.
     *
     * @return array<string, string>
     */
    private function walk(string $appPath, string $dir): array
    {
        $base = $appPath . '/' . $dir;
        if (!is_dir($base)) {
            return [];
        }

        $inner = new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS);
        $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current): bool {
            return !($current->isDir() && in_array($current->getBasename(), self::SKIP_DIRS, true));
        });
        $files = [];
        foreach (new RecursiveIteratorIterator($outer, RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appPath) + 1));
            $files[$relative] = $file->getBasename();
        }

        return $files;
    }

    /**
     * Whether the base name is a file this check reads under the given top-
     * level directory: a .htaccess under an htaccess directory, a *.conf
     * under a conf directory, a *.php under app/.
     */
    private function isScannedName(string $name, string $dir): bool
    {
        if ($name === '.htaccess' && in_array($dir, self::HTACCESS_DIRS, true)) {
            return true;
        }
        if (str_ends_with($name, '.conf') && in_array($dir, self::CONF_DIRS, true)) {
            return true;
        }

        return $dir === self::PHP_DEEP_DIR && str_ends_with($name, '.php');
    }

    /**
     * The 1-based line of the first offending 'unsafe-inline' in the file,
     * or null when there is none. Comment lines (and, in an env file, every
     * line that is not a HELMET_CSP= line) are blanked first, so offsets and
     * line numbers stay those of the file.
     */
    private function firstOffendingLine(string $raw, string $relativePath): ?int
    {
        $isEnv = in_array($relativePath, self::ENV_FILES, true);
        $lines = explode("\n", $raw);
        foreach ($lines as $index => $line) {
            $ignored = $isEnv
                ? !str_starts_with($line, self::ENV_PREFIX)
                : preg_match(self::COMMENT_LINE, $line) === 1;
            if ($ignored) {
                $lines[$index] = str_repeat(' ', strlen($line));
            }
        }
        $text = implode("\n", $lines);

        $offsets = $this->headerStringOffsets($text);
        if (str_ends_with($relativePath, '.php')) {
            $offsets = array_merge($offsets, $this->phpArrayOffsets($text));
        }
        if ($offsets === []) {
            return null;
        }

        return substr_count(substr($text, 0, min($offsets)), "\n") + 1;
    }

    /**
     * The offsets of every 'unsafe-inline' in a header-string value list:
     * from a script directive name to the next ;, the end of the quoted
     * string (a double quote) or the end of the line.
     *
     * @return list<int>
     */
    private function headerStringOffsets(string $text): array
    {
        preg_match_all(self::SCRIPT_DIRECTIVE, $text, $matches, PREG_OFFSET_CAPTURE);
        $offsets = [];
        foreach ($matches[0] as [$name, $at]) {
            $start = $at + strlen($name);
            $length = strcspn($text, ";\"\n", $start);
            $offset = $this->firstUnsafeInline(substr($text, $start, $length));
            if ($offset !== null) {
                $offsets[] = $start + $offset;
            }
        }

        return $offsets;
    }

    /**
     * The offsets of every 'unsafe-inline' in a PHP array value list: from
     * the => after a quoted script directive key to the first ] after it,
     * which may be on a later line.
     *
     * @return list<int>
     */
    private function phpArrayOffsets(string $text): array
    {
        preg_match_all(self::SCRIPT_ARRAY_KEY, $text, $matches, PREG_OFFSET_CAPTURE);
        $offsets = [];
        foreach ($matches[0] as [$key, $at]) {
            $start = (int) strpos($text, '=>', $at);
            $end = strpos($text, ']', $at + strlen($key));
            $length = ($end === false ? strlen($text) : $end) - $start;
            $offset = $this->firstUnsafeInline(substr($text, $start, $length));
            if ($offset !== null) {
                $offsets[] = $start + $offset;
            }
        }

        return $offsets;
    }

    /**
     * The offset of the first 'unsafe-inline' (with its single quotes,
     * case-insensitive) in one value list, or null when it has none.
     */
    private function firstUnsafeInline(string $values): ?int
    {
        if (preg_match(self::UNSAFE_INLINE, $values, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return $match[0][1];
    }

    /**
     * A warning finding for one file that lets scripts run inline.
     */
    private function warn(string $relativePath, int $line): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf('%s:%d lets scripts run inline (\'unsafe-inline\' in script-src); use HELMET\'s CSP nonce instead (CREED runtime-and-headers)', $relativePath, $line),
            $relativePath,
            $line,
            null,
            'script-src',
        );
    }
}
