<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Warns when a committed php.ini, .user.ini or .htaccess turns display_errors
 * on or log_errors off.
 *
 * CREED runtime-and-headers keeps display_errors Off and log_errors On in
 * production: a committed ini or .htaccess that flips either one ships stack
 * traces to users over HTTP, or swallows the errors that would show the
 * problem otherwise. PROCTOR reads every *.ini at the app root and at any
 * depth under public/, deploy/, deployments/ and docker/, and every
 * .htaccess at the root or at any depth under those folders, where a server
 * setting would ship with the release.
 *
 * Emits proctor/display-errors on pass, and proctor/display-errors/on and
 * proctor/display-errors/log-off on warning. Every finding is a warning,
 * never a failure.
 *
 * One warning per file per rule, on the line of its first match. The proctor
 * marks the paper, it does not rewrite it.
 */
final class DisplayErrorsCheck implements CheckInterface
{
    /** Top-level directories whose ini and .htaccess files may carry the switches. */
    private const SCAN_DIRS = ['public', 'deploy', 'deployments', 'docker'];

    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git', 'tests'];

    /** Base-name words that mark a local-development file (case-insensitive). */
    private const SKIP_NAME_WORDS = ['dev', 'local', 'development'];

    /** ini values that turn display_errors on (case-insensitive, optional quotes). */
    private const DISPLAY_ERRORS_ON = ['on', '1', 'true', 'yes', 'stdout', 'stderr'];

    /** ini values that turn log_errors off (case-insensitive, optional quotes). */
    private const LOG_ERRORS_OFF = ['off', '0', 'false', 'no'];

    public function id(): string
    {
        return 'display-errors';
    }

    public function run(CheckContext $context): array
    {
        $findings = [];
        foreach ($this->filesToScan($context->appPath) as $relativePath) {
            $raw = file_get_contents($context->appPath . '/' . $relativePath);
            if ($raw === false) {
                continue;
            }
            [$onLine, $logOffLine] = $this->firstMatchingLines($raw, str_ends_with($relativePath, '.ini'));
            if ($onLine !== null) {
                $findings[] = $this->warn($relativePath, $onLine, 'on');
            }
            if ($logOffLine !== null) {
                $findings[] = $this->warn($relativePath, $logOffLine, 'log-off');
            }
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no committed php.ini, .user.ini or .htaccess turns display_errors on or log_errors off',
            )];
        }

        return $findings;
    }

    /**
     * The relative paths (forward slashes) this check scans, sorted: every
     * *.ini and .htaccess at the app root, and every *.ini and .htaccess at
     * any depth under a scan directory. A file whose base name contains dev,
     * local or development is a local-development file and is not read.
     *
     * @param string $appPath the application path
     *
     * @return list<string>
     */
    private function filesToScan(string $appPath): array
    {
        $appPath = rtrim($appPath, '/\\');
        $paths = [];

        foreach (scandir($appPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_file($appPath . '/' . $entry)) {
                continue;
            }
            if (!($this->isScannedName($entry) && !$this->skipName($entry))) {
                continue;
            }
            $paths[$entry] = $entry;
        }

        foreach (self::SCAN_DIRS as $dir) {
            $base = $appPath . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $inner = new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS);
            $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current): bool {
                return !($current->isDir() && in_array($current->getBasename(), self::SKIP_DIRS, true));
            });
            $iterator = new RecursiveIteratorIterator($outer, RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($iterator as $file) {
                $name = $file->getBasename();
                if (!$file->isFile() || !$this->isScannedName($name) || $this->skipName($name)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appPath) + 1));
                $paths[$relative] = $relative;
            }
        }

        $paths = array_values($paths);
        sort($paths);

        return $paths;
    }

    /**
     * Whether the base name is a file this check reads: an ini file (a name
     * ending in .ini, .user.ini included) or an Apache .htaccess.
     */
    private function isScannedName(string $name): bool
    {
        return str_ends_with($name, '.ini') || $name === '.htaccess';
    }

    /**
     * Whether the base name marks a local-development file, such as
     * php.dev.ini or php.local.ini (case-insensitive).
     */
    private function skipName(string $name): bool
    {
        $lower = strtolower($name);
        foreach (self::SKIP_NAME_WORDS as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The 1-based lines of the first display_errors-on line and the first
     * log_errors-off line, or null for each when no line matches. A line
     * whose first non-space character is ; or # is ignored.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function firstMatchingLines(string $raw, bool $ini): array
    {
        $onLine = null;
        $logOffLine = null;

        foreach (explode("\n", $raw) as $index => $line) {
            if (preg_match('/^\s*[;#]/', $line) === 1) {
                continue;
            }
            if ($ini) {
                if (preg_match('/^\s*([A-Za-z0-9_.\-]+)\s*=\s*(.*)$/', $line, $match) !== 1) {
                    continue;
                }
                $key = strtolower($match[1]);
                $value = $this->iniValue($match[2]);
                if ($key === 'display_errors' && $onLine === null && in_array($value, self::DISPLAY_ERRORS_ON, true)) {
                    $onLine = $index + 1;
                }
                if ($key === 'log_errors' && $logOffLine === null && in_array($value, self::LOG_ERRORS_OFF, true)) {
                    $logOffLine = $index + 1;
                }
                continue;
            }
            if ($onLine === null
                && (preg_match('/^\s*php_flag\s+display_errors\s+on\b/i', $line) === 1
                    || preg_match('/^\s*php_value\s+display_errors\s+1\b/i', $line) === 1)
            ) {
                $onLine = $index + 1;
            }
            if ($logOffLine === null
                && (preg_match('/^\s*php_flag\s+log_errors\s+off\b/i', $line) === 1
                    || preg_match('/^\s*php_value\s+log_errors\s+0\b/i', $line) === 1)
            ) {
                $logOffLine = $index + 1;
            }
        }

        return [$onLine, $logOffLine];
    }

    /**
     * The value of an ini line: the part before the first ; that is outside
     * quotes (PHP reads the rest as an inline comment), trimmed and without
     * one pair of surrounding quotes, lower-cased so the comparison is
     * case-insensitive.
     */
    private function iniValue(string $raw): string
    {
        $value = '';
        $quote = null;
        foreach (str_split($raw) as $char) {
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                $value .= $char;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $value .= $char;
                continue;
            }
            if ($char === ';') {
                break;
            }
            $value .= $char;
        }

        $value = trim($value);
        if (strlen($value) >= 2
            && (($value[0] === '"' && $value[strlen($value) - 1] === '"')
                || ($value[0] === "'" && $value[strlen($value) - 1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        return strtolower(trim($value));
    }

    /**
     * A warning finding for one file that turns display_errors on or
     * log_errors off.
     */
    private function warn(string $relativePath, int $line, string $rule): Finding
    {
        $message = $rule === 'on'
            ? sprintf('display_errors is On in %s:%d; production must keep it Off and log_errors On (CREED runtime-and-headers)', $relativePath, $line)
            : sprintf('log_errors is Off in %s:%d; production must keep it On (CREED runtime-and-headers)', $relativePath, $line);

        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            $message,
            $relativePath,
            $line,
            null,
            $rule,
        );
    }
}
