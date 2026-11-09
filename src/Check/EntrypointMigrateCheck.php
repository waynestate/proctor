<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Warns when a container entrypoint runs migrations at start.
 *
 * WELD practice W24 runs migrations once per release as a job, never in a
 * container entrypoint: with two replicas both would run migrate at the same
 * moment, and a failed migration crash-loops the container. Squire's own
 * entrypoint takes a migrate role (php artisan migrate --force, run once as
 * a job), so a line inside a migrate) case branch is not flagged.
 *
 * Emits proctor/entrypoint-migrate on pass and
 * proctor/entrypoint-migrate/runs on warning.
 *
 * One warning per file, on the line of its first flagged line. The proctor
 * marks the paper, it does not rewrite it.
 */
final class EntrypointMigrateCheck implements CheckInterface
{
    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

    /** The basename marker: every file whose name carries it is an entrypoint. */
    private const NAME_MARKER = 'entrypoint';

    /**
     * A line that runs the plain migrate command: artisan migrate followed by
     * whitespace, ;, &, |, a quote or the end of the line, so migrate
     * --force and a bare migrate flag while migrate:status, migrate:fresh
     * and the other subcommands do not.
     */
    private const MIGRATE_LINE_PATTERN = "/artisan\s+migrate(?=[\s;&|'\"]|$)/";

    /**
     * A case branch label that runs the migration as a job: migrate) or
     * migrate|...) with optional spaces before it.
     */
    private const BRANCH_LABEL_PATTERN = '/^migrate(\|[^)]*)?\)/';

    public function id(): string
    {
        return 'entrypoint-migrate';
    }

    public function run(CheckContext $context): array
    {
        $findings = [];
        foreach ($this->filesToScan($context->appPath) as $relativePath) {
            $raw = file_get_contents($context->appPath . '/' . $relativePath);
            if ($raw === false) {
                continue;
            }
            $line = $this->firstMigrateLine($raw);
            if ($line !== null) {
                $findings[] = $this->warn($relativePath, $line);
            }
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no container entrypoint runs migrations at start',
            )];
        }

        return $findings;
    }

    /**
     * The relative paths (forward slashes) this check scans, sorted: every
     * file under the app path whose basename contains entrypoint
     * (case-insensitive) at any depth. Dockerfiles themselves are not read.
     *
     * @param string $appPath the application path
     *
     * @return list<string>
     */
    private function filesToScan(string $appPath): array
    {
        $appPath = rtrim($appPath, '/\\');
        if (!is_dir($appPath)) {
            return [];
        }
        $inner = new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS);
        $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current): bool {
            return !($current->isDir() && in_array($current->getBasename(), self::SKIP_DIRS, true));
        });
        $iterator = new RecursiveIteratorIterator($outer, RecursiveIteratorIterator::LEAVES_ONLY);

        $paths = [];
        foreach ($iterator as $file) {
            if (stripos($file->getBasename(), self::NAME_MARKER) === false) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appPath) + 1));
            $paths[$relative] = $relative;
        }
        $paths = array_values($paths);
        sort($paths);

        return $paths;
    }

    /**
     * The 1-based line of the first line that runs the plain migrate
     * command, or null when no line does. A line whose first non-space
     * character is # is ignored, a trailing shell comment is removed first,
     * and a line inside a migrate) case branch, to the branch's ;; or esac,
     * is not flagged.
     */
    private function firstMigrateLine(string $raw): ?int
    {
        $inBranch = false;
        foreach (explode("\n", $raw) as $index => $line) {
            $trimmed = trim(rtrim($line, "\r"));
            if ($inBranch) {
                if ($trimmed === 'esac' || str_ends_with($trimmed, ';;')) {
                    $inBranch = false;
                }
                continue;
            }
            if (preg_match(self::BRANCH_LABEL_PATTERN, $trimmed) === 1) {
                $inBranch = !($trimmed === 'esac' || str_ends_with($trimmed, ';;'));
                continue;
            }
            if (str_starts_with($trimmed, '#')) {
                continue;
            }
            $code = preg_replace('/\s#.*$/', '', $line);
            if (preg_match(self::MIGRATE_LINE_PATTERN, (string) $code) === 1) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * A warning finding for one entrypoint that migrates at start.
     */
    private function warn(string $relativePath, int $line): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                'entrypoint runs migrations at container start (%s:%d); run them once per release as a job',
                $relativePath,
                $line,
            ),
            $relativePath,
            $line,
            null,
            'runs',
        );
    }
}
