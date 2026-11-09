<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Warns when a committed .htaccess or server config turns on directory
 * listing.
 *
 * CREED production says the web server must not list directory contents:
 * a committed config that leaves Apache's Indexes option or nginx's
 * autoindex on hands every visitor the folder's inventory. PROCTOR reads
 * every .htaccess at the app root and at any depth under public/, deploy/,
 * deployments/ and docker/, and every *.conf at any depth under deploy/,
 * deployments/ and docker/, where a server setting would ship with the
 * release.
 *
 * Emits proctor/directory-listing on pass, and
 * proctor/directory-listing/indexes on warning. Every finding is a
 * warning, never a failure.
 *
 * One warning per file, on the line of its first match. The proctor keeps
 * the shelves out of the crowd's sight.
 */
final class DirectoryListingCheck implements CheckInterface
{
    /** Top-level directories whose .htaccess files may carry the switch. */
    private const SCAN_DIRS = ['public', 'deploy', 'deployments', 'docker'];

    /** Top-level directories whose *.conf files are read (a root or public/ .conf is not). */
    private const CONF_DIRS = ['deploy', 'deployments', 'docker'];

    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git', 'tests'];

    /** Options argument words that turn directory listing on (case-insensitive). */
    private const LISTING_ON_WORDS = ['+indexes', 'indexes', 'all'];

    public function id(): string
    {
        return 'directory-listing';
    }

    public function run(CheckContext $context): array
    {
        $findings = [];
        foreach ($this->filesToScan($context->appPath) as $relativePath) {
            $raw = file_get_contents($context->appPath . '/' . $relativePath);
            if ($raw === false) {
                continue;
            }
            $line = $this->firstMatchingLine($raw);
            if ($line !== null) {
                $findings[] = $this->warn($relativePath, $line);
            }
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no committed .htaccess or server config turns on directory listing',
            )];
        }

        return $findings;
    }

    /**
     * The relative paths (forward slashes) this check scans, sorted: the
     * .htaccess at the app root, every .htaccess at any depth under a scan
     * directory, and every *.conf at any depth under a conf directory.
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
            if ($entry !== '.htaccess') {
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
                if (!$file->isFile() || !$this->isScannedName($name, $dir)) {
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
     * Whether the base name is a file this check reads under the given top-
     * level directory: an Apache .htaccess anywhere, a *.conf only under a
     * conf directory.
     */
    private function isScannedName(string $name, string $dir): bool
    {
        return $name === '.htaccess' || (in_array($dir, self::CONF_DIRS, true) && str_ends_with($name, '.conf'));
    }

    /**
     * The 1-based line of the first line that turns directory listing on,
     * or null when no line does. A line whose first non-space character is
     * # is ignored.
     */
    private function firstMatchingLine(string $raw): ?int
    {
        foreach (explode("\n", $raw) as $index => $line) {
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }
            if ($this->turnsOnListing($line)) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * Whether one line turns directory listing on: an Apache Options
     * directive (case-insensitive, leading spaces allowed) whose arguments
     * carry +Indexes, a bare Indexes or All, unless the same line also has
     * -Indexes after it, or an nginx autoindex on (case-insensitive, spaces
     * allowed before the ;).
     */
    private function turnsOnListing(string $line): bool
    {
        if (preg_match('/^\s*options\s+(.+)$/i', $line, $match) === 1) {
            return $this->optionsTurnOnListing($match[1]);
        }

        return preg_match('/^\s*autoindex\s+on\s*;/i', $line) === 1;
    }

    /**
     * Whether the arguments of an Apache Options directive turn directory
     * listing on: +Indexes, a bare Indexes or All, unless the same line
     * also has -Indexes after it.
     */
    private function optionsTurnOnListing(string $args): bool
    {
        $tokens = preg_split('/\s+/', trim($args)) ?: [];
        $onAt = null;
        $offAt = null;
        foreach ($tokens as $position => $token) {
            $word = strtolower($token);
            if ($word === '-indexes') {
                $offAt = $position;
            }
            if (in_array($word, self::LISTING_ON_WORDS, true)) {
                $onAt = $position;
            }
        }

        return $onAt !== null && ($offAt === null || $onAt > $offAt);
    }

    /**
     * A warning finding for one file that turns on directory listing.
     */
    private function warn(string $relativePath, int $line): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf('%s:%d turns on directory listing; the web server must not list directory contents (CREED production)', $relativePath, $line),
            $relativePath,
            $line,
            null,
            'indexes',
        );
    }
}
