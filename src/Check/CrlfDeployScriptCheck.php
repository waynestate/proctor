<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Fails when a deploy script carries Windows line endings (CRLF): bash on
 * Linux reads the carriage return as part of each command, so `set -e`
 * becomes `set -e\r` and the script breaks on the host that runs it. Git on
 * Windows adds CRLF easily, so the fix is converting the file to LF and
 * pinning the ending in .gitattributes.
 *
 * Scanned: the top-level build.sh and release.sh, and every .sh file under a
 * deployments/, deploy_scripts/, deploy/ or scripts/ directory at any depth.
 * Skipped at any depth: vendor/, node_modules/ and .git/. One finding per
 * file, on the line of its first carriage return.
 *
 * The proctor reads the answer sheet in Unix.
 */
final class CrlfDeployScriptCheck implements CheckInterface
{
    /** Top-level directories whose .sh files are deploy scripts. */
    private const DEPLOY_DIRS = ['deployments', 'deploy_scripts', 'deploy', 'scripts'];

    /** Top-level files that are deploy scripts. */
    private const DEPLOY_FILES = ['build.sh', 'release.sh'];

    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

    public function id(): string
    {
        return 'crlf-deploy-script';
    }

    public function run(CheckContext $context): array
    {
        $findings = [];
        foreach ($this->filesToScan($context->appPath) as $relativePath) {
            $raw = file_get_contents($context->appPath . '/' . $relativePath);
            if ($raw === false) {
                continue;
            }
            $line = $this->lineOfFirstCarriageReturn($raw);
            if (null !== $line) {
                $findings[] = $this->fail($relativePath, $line);
            }
        }

        if ($findings === []) {
            return [new Finding($this->id(), Finding::STATUS_PASS, 'No deploy script has Windows line endings.')];
        }

        return $findings;
    }

    /**
     * The relative paths (forward slashes) of the deploy scripts this check
     * scans: every .sh file under a deploy directory at any depth, and the
     * top-level build.sh and release.sh.
     *
     * @param string $appPath the application path
     *
     * @return list<string>
     */
    private function filesToScan(string $appPath): array
    {
        $appPath = rtrim($appPath, '/\\');
        $inner = new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS);
        $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current): bool {
            return !($current->isDir() && in_array($current->getBasename(), self::SKIP_DIRS, true));
        });
        $iterator = new RecursiveIteratorIterator($outer, RecursiveIteratorIterator::LEAVES_ONLY);

        $files = [];
        foreach ($iterator as $file) {
            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($appPath) + 1));
            $basename = $file->getBasename();
            $segments = explode('/', $relativePath);
            if (1 === count($segments)) {
                if (in_array($basename, self::DEPLOY_FILES, true)) {
                    $files[] = $relativePath;
                }
            } elseif (array_intersect(array_slice($segments, 0, -1), self::DEPLOY_DIRS) !== []
                && str_ends_with($basename, '.sh')) {
                $files[] = $relativePath;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The 1-based line of the first carriage return in the raw bytes, or
     * null when the file has none.
     */
    private function lineOfFirstCarriageReturn(string $raw): ?int
    {
        foreach (explode("\n", $raw) as $index => $line) {
            if (str_contains($line, "\r")) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * A fail finding for one deploy script with Windows line endings.
     */
    private function fail(string $relativePath, int $line): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_FAIL,
            sprintf('%s has Windows line endings (CRLF); bash on Linux reads the carriage return as part of each command. Convert it to LF and add "*.sh text eol=lf" to .gitattributes.', $relativePath),
            $relativePath,
            $line,
        );
    }
}
