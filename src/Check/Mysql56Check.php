<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Proctor\Code\PhpFiles;

/**
 * Warns when an app uses MySQL features the shared 5.6 server rejects:
 * JSON columns and queries, generated columns, renameColumn/renameIndex,
 * vector, invisible and spatial columns, schema introspection, raw-SQL
 * syntax, the upsert row alias, indexed strings over 191 characters,
 * date columns defaulting to the current time, and the db:show/db:monitor
 * commands. Warnings, not failures: an app on its own newer database is
 * not affected. The proctor only raises an eyebrow.
 *
 * The rules run on the AST, not on raw lines, so a pattern that appears in
 * a comment or a string literal is not flagged, and a call that spans
 * several lines is still seen as one call.
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
     * The raw-SQL patterns, applied to the SQL string of a DB::statement /
     * DB::unprepared call. Case-insensitive.
     *
     * @var list<string>
     */
    private const RAW_SQL_PATTERNS = [
        'generated always',
        '\bover\s*\(',
        'with\s+(recursive\s+)?\w+\s+as\s*\(',
        'skip locked',
        'nowait',
        'rename (column|index)',
        'utf8mb4_0900',
        'algorithm\s*=\s*instant',
        'check\s*\(',
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

        $phpFiles = new PhpFiles();
        $findings = [];
        foreach ($this->filesToScan($appPath) as $relativePath) {
            $statements = $phpFiles->statements($appPath . '/' . $relativePath);
            if ($statements === null) {
                $findings[] = new Finding(
                    'proctor.parse-error',
                    Finding::STATUS_WARNING,
                    'could not parse: ' . $phpFiles->lastError(),
                    $relativePath,
                    $phpFiles->lastErrorLine(),
                );
                continue;
            }

            foreach ($this->findingsForFile($relativePath, $statements, $phpFiles) as $finding) {
                $findings[] = $finding;
            }
        }

        // The string-default rule is file-level: the provider must call
        // Schema::defaultStringLength(191) somewhere, or every indexed
        // string over 191 characters breaks on 5.6.
        $stringDefault = $this->stringDefaultFinding($appPath, $phpFiles);
        if ($stringDefault !== null) {
            $findings[] = $stringDefault;
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
     * The findings for one parsed file, in rule order.
     *
     * @param string          $relativePath the file's path relative to the app
     * @param array<Node\Stmt> $statements  the parsed statements
     *
     * @return list<Finding>
     */
    private function findingsForFile(string $relativePath, array $statements, PhpFiles $phpFiles): array
    {
        $findings = [];

        // config/database.php is scanned for the upsert-alias rule only.
        if (self::UPSERT_CONFIG === $relativePath) {
            foreach ($phpFiles->findInstanceOf($statements, String_::class) as $string) {
                if ($string->value === 'use_upsert_alias') {
                    $findings[] = $this->warning(
                        'mysql56-upsert-alias',
                        'the upsert row alias needs MySQL 8.0.19',
                        $relativePath,
                        $string->getStartLine(),
                    );
                }
            }

            return $findings;
        }

        $methodCalls = $phpFiles->findInstanceOf($statements, MethodCall::class);
        $staticCalls = $phpFiles->findInstanceOf($statements, StaticCall::class);
        $strings = $phpFiles->findInstanceOf($statements, String_::class);

        foreach ([...$methodCalls, ...$staticCalls] as $call) {
            $line = $call->getStartLine();
            $name = $this->callName($call);
            if ($name === null) {
                continue;
            }
            $lowerName = strtolower($name);

            // mysql56-json: json/jsonb columns, whereJson* queries, and
            // where('col->sub') style JSON column queries.
            if ($lowerName === 'json' || $lowerName === 'jsonb'
                || str_contains($lowerName, 'wherejson')
                || ($this->isWhereCall($lowerName) && $this->firstStringArgContains($call->args, '->'))) {
                $findings[] = $this->warning(
                    'mysql56-json',
                    'JSON columns and JSON queries need MySQL 5.7.8',
                    $relativePath,
                    $line,
                );
            }

            // mysql56-generated: virtual/stored generated columns.
            if (in_array($lowerName, ['virtualas', 'storedas', 'virtualasjson', 'storedasjson'], true)) {
                $findings[] = $this->warning(
                    'mysql56-generated',
                    'generated columns need MySQL 5.7.6',
                    $relativePath,
                    $line,
                );
            }

            // mysql56-rename: renameColumn/renameIndex.
            if (in_array($lowerName, ['renameindex', 'renamecolumn'], true)) {
                $findings[] = $this->warning(
                    'mysql56-rename',
                    'use a raw ALTER TABLE ... CHANGE on 5.6',
                    $relativePath,
                    $line,
                );
            }

            // mysql56-newer-column: vector, invisible, instant, spatial.
            if (in_array($lowerName, ['vector', 'vectorindex', 'invisible', 'instant', 'spatialindex'], true)) {
                $findings[] = $this->warning(
                    'mysql56-newer-column',
                    'needs a newer MySQL than 5.6',
                    $relativePath,
                    $line,
                );
            }

            // mysql56-introspection: Schema::hasColumn and friends, lateral joins.
            if ($this->isSchemaIntrospection($call) || in_array($lowerName, ['joinlateral', 'leftjoinlateral'], true)) {
                $findings[] = $this->warning(
                    'mysql56-introspection',
                    'not reliable on MySQL 5.6; use Schema::hasTable',
                    $relativePath,
                    $line,
                );
            }

            // mysql56-raw-sql: DB::statement / DB::unprepared with 5.7+ syntax.
            if ($this->isRawSqlCall($call)) {
                $findings[] = $this->warning(
                    'mysql56-raw-sql',
                    'raw SQL uses syntax MySQL 5.6 rejects',
                    $relativePath,
                    $line,
                );
            }

            // mysql56-string-length: indexed strings over 191 characters.
            if ($lowerName === 'string' && $this->secondIntArgGreaterThan($call->args, 191)) {
                $findings[] = $this->warning(
                    'mysql56-string-length',
                    "indexed strings over 191 characters exceed 5.6's key limit",
                    $relativePath,
                    $line,
                );
            }

            // mysql56-current-date: date/year columns defaulting to now.
            if ($lowerName === 'usecurrent' && $this->varChainHasMethod($call, ['date', 'year'])) {
                $findings[] = $this->warning(
                    'mysql56-current-date',
                    '5.6 cannot default a date column to the current time; use timestamp',
                    $relativePath,
                    $line,
                );
            }
        }

        // mysql56-command: the db:show / db:monitor commands.
        foreach ($strings as $string) {
            if (str_contains($string->value, 'db:show') || str_contains($string->value, 'db:monitor')) {
                $findings[] = $this->warning(
                    'mysql56-command',
                    'these commands do not support MySQL 5.6',
                    $relativePath,
                    $string->getStartLine(),
                );
            }
        }

        return $findings;
    }

    /**
     * The file-level string-default finding, or null when the provider
     * already calls Schema::defaultStringLength(191).
     */
    private function stringDefaultFinding(string $appPath, PhpFiles $phpFiles): ?Finding
    {
        $provider = $appPath . '/' . self::APP_SERVICE_PROVIDER;
        if (!is_file($provider)) {
            return $this->stringDefaultWarning();
        }

        $statements = $phpFiles->statements($provider);
        if ($statements === null) {
            // Fall back to the string check if the file cannot be parsed.
            $contents = (string) file_get_contents($provider);
            if (str_contains($contents, 'defaultStringLength(191)')) {
                return null;
            }

            return $this->stringDefaultWarning();
        }

        foreach ($phpFiles->findInstanceOf($statements, StaticCall::class) as $call) {
            if ($this->isDefaultStringLength191($call)) {
                return null;
            }
        }

        return $this->stringDefaultWarning();
    }

    private function stringDefaultWarning(): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            'mysql56-string-default: call Schema::defaultStringLength(191) in boot()',
            self::APP_SERVICE_PROVIDER,
            1,
        );
    }

    /**
     * Whether the call is Schema::defaultStringLength(191).
     */
    private function isDefaultStringLength191(StaticCall $call): bool
    {
        $class = $call->class;
        if (!$class instanceof Name || strtolower($class->getLast()) !== 'schema') {
            return false;
        }
        $name = $this->callName($call);
        if ($name === null || strtolower($name) !== 'defaultstringlength') {
            return false;
        }
        if (!isset($call->args[0]) || !$call->args[0] instanceof Arg) {
            return false;
        }
        $value = $call->args[0]->value;

        return $value instanceof Int_ && $value->value === 191;
    }

    /**
     * The name of the call, or null when it is dynamic (not a plain
     * identifier).
     */
    private function callName(MethodCall|StaticCall $call): ?string
    {
        return $call->name instanceof Identifier ? $call->name->name : null;
    }

    /**
     * Whether the method name is a where-style call (where, orWhere, ...).
     */
    private function isWhereCall(string $lowerName): bool
    {
        return str_ends_with($lowerName, 'where');
    }

    /**
     * Whether the first argument is a string containing the needle.
     *
     * @param array<Arg|\PhpParser\Node\ArgPlaceholder|\PhpParser\Node\VariadicPlaceholder> $args
     */
    private function firstStringArgContains(array $args, string $needle): bool
    {
        if (isset($args[0]) && $args[0] instanceof Arg && $args[0]->value instanceof String_) {
            return str_contains($args[0]->value->value, $needle);
        }

        return false;
    }

    /**
     * The first argument's string value, or null when it is not a string.
     *
     * @param array<Arg|\PhpParser\Node\ArgPlaceholder|\PhpParser\Node\VariadicPlaceholder> $args
     */
    private function firstStringArg(array $args): ?string
    {
        if (isset($args[0]) && $args[0] instanceof Arg && $args[0]->value instanceof String_) {
            return $args[0]->value->value;
        }

        return null;
    }

    /**
     * Whether the second argument is an int greater than the threshold.
     *
     * @param array<Arg|\PhpParser\Node\ArgPlaceholder|\PhpParser\Node\VariadicPlaceholder> $args
     */
    private function secondIntArgGreaterThan(array $args, int $threshold): bool
    {
        if (isset($args[1]) && $args[1] instanceof Arg && $args[1]->value instanceof Int_) {
            return $args[1]->value->value > $threshold;
        }

        return false;
    }

    /**
     * Whether the call is a Schema:: introspection method.
     */
    private function isSchemaIntrospection(MethodCall|StaticCall $call): bool
    {
        if (!$call instanceof StaticCall) {
            return false;
        }
        $class = $call->class;
        if (!$class instanceof Name || strtolower($class->getLast()) !== 'schema') {
            return false;
        }
        $name = $this->callName($call);
        if ($name === null) {
            return false;
        }

        return in_array(
            strtolower($name),
            ['hascolumn', 'hascolumns', 'getcolumns', 'getcolumn', 'getcolumnlisting', 'whentablehascolumn'],
            true,
        );
    }

    /**
     * Whether the call is DB::statement / DB::unprepared with SQL that uses
     * syntax MySQL 5.6 rejects.
     */
    private function isRawSqlCall(MethodCall|StaticCall $call): bool
    {
        if (!$call instanceof StaticCall) {
            return false;
        }
        $class = $call->class;
        if (!$class instanceof Name || strtolower($class->getLast()) !== 'db') {
            return false;
        }
        $name = $this->callName($call);
        if ($name === null || !in_array(strtolower($name), ['statement', 'unprepared'], true)) {
            return false;
        }
        $sql = $this->firstStringArg($call->args);
        if ($sql === null) {
            return false;
        }

        foreach (self::RAW_SQL_PATTERNS as $pattern) {
            if (preg_match('/' . $pattern . '/i', $sql) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the call's receiver chain includes one of the given method
     * names (e.g. the date in $table->date('day')->useCurrent()).
     *
     * @param list<string> $names
     */
    private function varChainHasMethod(MethodCall|StaticCall $call, array $names): bool
    {
        $node = $call;
        while ($node instanceof MethodCall) {
            $name = $node->name instanceof Identifier ? strtolower($node->name->name) : null;
            if (in_array($name, $names, true)) {
                return true;
            }
            $node = $node->var;
        }

        return false;
    }

    /**
     * A warning finding for one rule.
     */
    private function warning(string $ruleId, string $message, string $relativePath, int $line): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            $ruleId . ': ' . $message,
            $relativePath,
            $line,
        );
    }
}
