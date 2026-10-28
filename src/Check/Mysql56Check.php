<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Warns when an app uses MySQL features the shared 5.6 server rejects:
 * JSON columns and queries, generated columns, renameColumn/renameIndex,
 * vector, invisible and spatial columns, schema introspection, raw-SQL
 * syntax, the upsert row alias, indexed strings over 191 characters,
 * date columns defaulting to the current time, and the db:show/db:monitor
 * commands. Warnings, not failures: an app on its own newer database is
 * not affected. The proctor only raises an eyebrow.
 */
final class Mysql56Check implements CheckInterface
{
    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', 'storage', 'tests', '.git'];

    /** Relative path prefixes whose *.php files are scanned. */
    private const SCAN_PREFIXES = ['database/migrations/', 'app/', 'routes/'];

    /** The config file scanned for the upsert-alias rule. */
    private const UPSERT_CONFIG = 'config/database.php';

    /** The provider that must call Schema::defaultStringLength(191). */
    private const APP_SERVICE_PROVIDER = 'app/Providers/AppServiceProvider.php';

    /**
     * Line rules, in rule order: rule id => [patterns, message]. The
     * patterns are case-insensitive; a line that matches a rule yields one
     * warning for that rule, and a line that breaks several rules yields
     * one warning per rule.
     *
     * @var array<string, array{0: list<string>, 1: string}>
     */
    private const LINE_RULES = [
        'mysql56-json' => [
            ['->json\(', '->jsonb\(', 'whereJson', 'where\'[\w.]+->'],
            'JSON columns and JSON queries need MySQL 5.7.8',
        ],
        'mysql56-generated' => [
            ['->virtualAs\(', '->storedAs\(', 'virtualAsJson', 'storedAsJson'],
            'generated columns need MySQL 5.7.6',
        ],
        'mysql56-rename' => [
            ['->renameIndex\(', '->renameColumn\('],
            'use a raw ALTER TABLE ... CHANGE on 5.6',
        ],
        'mysql56-newer-column' => [
            ['->vector\(', 'vectorIndex', '->invisible\(', '->instant\(', '->spatialIndex\('],
            'needs a newer MySQL than 5.6',
        ],
        'mysql56-introspection' => [
            ['Schema::(hasColumn|hasColumns|getColumns|getColumn|getColumnListing|whenTableHasColumn)\b', '->(joinLateral|leftJoinLateral)\('],
            'not reliable on MySQL 5.6; use Schema::hasTable',
        ],
        'mysql56-raw-sql' => [
            [
                'generated always',
                '\bover\s*\(',
                'with\s+(recursive\s+)?\w+\s+as\s*\(',
                'skip locked',
                'nowait',
                'rename (column|index)',
                'utf8mb4_0900',
                'algorithm\s*=\s*instant',
                'check\s*\(',
            ],
            'raw SQL uses syntax MySQL 5.6 rejects',
        ],
        'mysql56-upsert-alias' => [
            ['use_upsert_alias'],
            'the upsert row alias needs MySQL 8.0.19',
        ],
        'mysql56-string-length' => [
            ['->string\([^,)]+,\s*(19[2-9]|2\d\d|[3-9]\d\d)\)'],
            "indexed strings over 191 characters exceed 5.6's key limit",
        ],
        'mysql56-current-date' => [
            ['->(date|year)\(.*useCurrent'],
            '5.6 cannot default a date column to the current time; use timestamp',
        ],
        'mysql56-command' => [
            ['db:show', 'db:monitor'],
            'these commands do not support MySQL 5.6',
        ],
    ];

    public function id(): string
    {
        return 'mysql56';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        if (!is_dir($appPath . '/database/migrations')) {
            return [new Finding($this->id(), Finding::STATUS_PASS, 'no Laravel migrations found; skipped')];
        }

        $findings = [];
        foreach ($this->filesToScan($appPath) as $relativePath) {
            $lines = file($appPath . '/' . $relativePath, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $index => $line) {
                $line = rtrim((string) $line, "\r\n");
                foreach ($this->messagesForLine($relativePath, $line) as $message) {
                    $findings[] = new Finding(
                        $this->id(),
                        Finding::STATUS_WARNING,
                        $message,
                        $relativePath,
                        $index + 1
                    );
                }
            }
        }

        // The string-default rule is file-level: the provider must call
        // Schema::defaultStringLength(191) somewhere, or every indexed
        // string over 191 characters breaks on 5.6.
        $provider = $appPath . '/' . self::APP_SERVICE_PROVIDER;
        $contents = is_file($provider) ? (string) file_get_contents($provider) : '';
        if (!str_contains($contents, 'defaultStringLength(191)')) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'mysql56-string-default: call Schema::defaultStringLength(191) in boot()',
                self::APP_SERVICE_PROVIDER,
                1
            );
        }

        if ($findings === []) {
            return [new Finding($this->id(), Finding::STATUS_PASS, 'no MySQL 5.6 blockers found')];
        }

        // Stable on every OS: by path, then line.
        usort($findings, static function (Finding $a, Finding $b): int {
            return strcmp((string) $a->path, (string) $b->path) ?: ($a->line <=> $b->line);
        });

        return $findings;
    }

    /**
     * The relative paths (forward slashes) of the files this check scans:
     * every *.php file under database/migrations/, app/ and routes/, plus
     * config/database.php (scanned for the upsert-alias rule only).
     *
     * @param string $appPath the application path
     *
     * @return list<string>
     */
    private function filesToScan(string $appPath): array
    {
        $inner = new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS);
        $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current): bool {
            return !($current->isDir() && in_array($current->getBasename(), self::SKIP_DIRS, true));
        });
        $iterator = new RecursiveIteratorIterator($outer, RecursiveIteratorIterator::LEAVES_ONLY);

        $files = [];
        foreach ($iterator as $file) {
            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($appPath) + 1));
            if (self::UPSERT_CONFIG === $relativePath) {
                $files[] = $relativePath;
                continue;
            }
            if (str_ends_with($file->getBasename(), '.php')) {
                foreach (self::SCAN_PREFIXES as $prefix) {
                    if (str_starts_with($relativePath, $prefix)) {
                        $files[] = $relativePath;
                        break;
                    }
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The warning messages for one line, in rule order. A line that breaks
     * more than one rule produces more than one message.
     *
     * @param string $relativePath the file's path relative to the app
     * @param string $line         the line without its line ending
     *
     * @return list<string>
     */
    private function messagesForLine(string $relativePath, string $line): array
    {
        $messages = [];

        foreach (self::LINE_RULES as $ruleId => [$patterns, $message]) {
            // config/database.php is scanned for the upsert-alias rule only.
            if (self::UPSERT_CONFIG === $relativePath && 'mysql56-upsert-alias' !== $ruleId) {
                continue;
            }
            // raw-sql: only on lines that call DB::statement or DB::unprepared.
            if ('mysql56-raw-sql' === $ruleId && !$this->isRawSqlLine($line)) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (preg_match('/' . $pattern . '/i', $line) === 1) {
                    $messages[] = $ruleId . ': ' . $message;
                    break;
                }
            }
        }

        return $messages;
    }

    /**
     * Whether the line calls DB::statement or DB::unprepared, the only
     * lines the raw-sql rule inspects.
     */
    private function isRawSqlLine(string $line): bool
    {
        return str_contains($line, 'DB::statement') || str_contains($line, 'DB::unprepared');
    }
}
