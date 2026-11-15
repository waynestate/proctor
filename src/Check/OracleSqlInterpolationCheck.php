<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use Waynestate\Proctor\Code\PhpFiles;
use Waynestate\Proctor\Code\RepoFiles;

/**
 * Warns when SQL sent to Oracle is built from PHP values.
 *
 * TELLER is the stack's read-only Oracle layer: named queries with bound
 * values, a read-only transaction and row caps. A query whose text is
 * assembled from PHP values at runtime, an interpolated string or a
 * concatenation of literals and variables, reaches the database one value at
 * a time, and every one of those values is a place where crafted input can
 * change what the query means. The warning points the reader at binding
 * (`:name` placeholders with oci_bind_by_name, or the facade's bindings
 * array) and at TELLER's named queries for reads.
 *
 * Scanned: every *.php file except vendor/, node_modules/, storage/, tests/
 * and .git/ at any depth, on the syntax tree with names resolved, so an
 * interpolated string in a comment or a log line is not flagged. Flagged:
 * oci_parse and ociparse (case-insensitive, after namespace fallback to the
 * global function) whose second argument is built SQL, and, on apps that
 * require yajra/laravel-oci8, DB::select, DB::selectOne, DB::statement,
 * DB::unprepared, DB::insert, DB::update and DB::delete (the class as
 * written DB or resolving to Illuminate\Support\Facades\DB) whose first
 * argument is built SQL. Built SQL is an interpolated string (a double-
 * quoted string or heredoc carrying a PHP part) or a concatenation with at
 * least one leaf that is not a plain string literal: a variable, a call
 * result or sprintf is not flagged, because the check cannot tell where the
 * text came from. Blade templates (*.blade.php) are skipped: no SQL belongs
 * in a template. A file that does not parse is skipped here; the
 * portability check reports it. One warning per call. Warning, never fail:
 * the fix is a refactor, and a new check must not turn a passing app into a
 * failing one.
 *
 * Emits proctor/oracle-sql-interpolation on pass and on every warning.
 *
 * The proctor notices the student who wrote the answers on the inside of
 * the exam paper.
 */
final class OracleSqlInterpolationCheck implements CheckInterface
{
    private const SKIP_DIRS = ['vendor', 'node_modules', 'storage', 'tests', '.git'];

    /** OCI entry points that take the SQL text as their second argument. */
    private const OCI_PARSE_FUNCTIONS = ['oci_parse', 'ociparse'];

    /** DB facade methods that take the SQL text as their first argument. */
    private const DB_SQL_METHODS = ['select', 'selectone', 'statement', 'unprepared', 'insert', 'update', 'delete'];

    /** The package that sends the DB facade's SQL to Oracle in this stack. */
    private const YAJRA_PACKAGE = 'yajra/laravel-oci8';

    public function id(): string
    {
        return 'oracle-sql-interpolation';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        $yajra = self::requiresYajra($appPath);
        $findings = [];
        $phpFiles = new PhpFiles();
        foreach (RepoFiles::list($appPath, $context->timeout, self::SKIP_DIRS)->files as $path) {
            if (!str_ends_with($path, '.php') || str_ends_with($path, '.blade.php')) {
                continue;
            }
            $statements = $phpFiles->statements($appPath . '/' . $path);
            if ($statements === null) {
                continue;
            }
            foreach ($this->builtSqlCalls($phpFiles, $phpFiles->resolveNames($statements), $yajra) as [$line, $name]) {
                $findings[] = $this->warning($path, $line, $name);
            }
        }

        if ($findings === []) {
            $findings[] = new Finding($this->id(), Finding::STATUS_PASS, 'no Oracle SQL is built from PHP values');
        }

        return $findings;
    }

    /**
     * Whether the app's root composer.json requires the yajra Oracle driver
     * in require or require-dev: only there does the DB facade send its SQL
     * to Oracle.
     */
    private static function requiresYajra(string $appPath): bool
    {
        $composerFile = $appPath . '/composer.json';
        if (!is_file($composerFile)) {
            return false;
        }
        $decoded = json_decode((string) file_get_contents($composerFile), true);
        if (!is_array($decoded)) {
            return false;
        }

        foreach (['require', 'require-dev'] as $section) {
            $packages = $decoded[$section] ?? [];
            if (is_array($packages) && array_key_exists(self::YAJRA_PACKAGE, $packages)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The calls whose SQL is built from PHP values: [line, name as written],
     * sorted by line.
     *
     * @param array<Node\Stmt> $statements
     *
     * @return list<array{0: int, 1: string}>
     */
    private function builtSqlCalls(PhpFiles $phpFiles, array $statements, bool $yajra): array
    {
        $calls = [];
        foreach ($phpFiles->findInstanceOf($statements, FuncCall::class) as $call) {
            if (!$call->name instanceof Name) {
                continue;
            }
            if (!in_array(strtolower($call->name->toString()), self::OCI_PARSE_FUNCTIONS, true)) {
                continue;
            }
            $sql = $call->getArgs()[1] ?? null;
            if ($sql !== null && $this->isBuiltSql($sql->value)) {
                $calls[] = [$call->getStartLine(), $call->name->toString()];
            }
        }
        if ($yajra) {
            foreach ($phpFiles->findInstanceOf($statements, StaticCall::class) as $call) {
                if (!$call->class instanceof Name || !$this->isDbFacade($call->class)) {
                    continue;
                }
                if (!$call->name instanceof Identifier) {
                    continue;
                }
                if (!in_array(strtolower($call->name->toString()), self::DB_SQL_METHODS, true)) {
                    continue;
                }
                $sql = $call->getArgs()[0] ?? null;
                if ($sql !== null && $this->isBuiltSql($sql->value)) {
                    // The class may resolve to the facade's full name; the
                    // warning names the call as the reader knows it.
                    $calls[] = [$call->getStartLine(), 'DB::' . $call->name->toString()];
                }
            }
        }
        usort($calls, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return $calls;
    }

    /**
     * The DB facade as written (DB) or once its name resolves to the
     * Laravel facade, case-insensitively.
     */
    private function isDbFacade(Name $name): bool
    {
        $resolved = strtolower($name->toString());

        return $resolved === 'db' || $resolved === 'illuminate\support\facades\db';
    }

    /**
     * Whether an expression is SQL built from PHP values: an interpolated
     * string (a double-quoted string or heredoc carrying a PHP part), or a
     * concatenation with at least one leaf that is not a plain string
     * literal. A variable, a call result, a nowdoc or a heredoc without
     * interpolation is not built here: the check cannot tell where the text
     * came from.
     */
    private function isBuiltSql(Node\Expr $expression): bool
    {
        if ($expression instanceof InterpolatedString) {
            return true;
        }

        return $expression instanceof Concat && $this->concatBuildsSql($expression);
    }

    /**
     * Walks a concatenation tree: built when any leaf is not a plain string
     * literal (a single-quoted string, a nowdoc, or a quoted string or
     * heredoc with no interpolation parts).
     */
    private function concatBuildsSql(Concat $concat): bool
    {
        foreach ([$concat->left, $concat->right] as $side) {
            if ($side instanceof Concat) {
                if ($this->concatBuildsSql($side)) {
                    return true;
                }
                continue;
            }
            if (!$side instanceof String_) {
                return true;
            }
        }

        return false;
    }

    /**
     * The warning for one call, naming the call, the file and the line.
     */
    private function warning(string $path, int $line, string $name): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                '`%s()` at %s:%d builds its SQL from PHP values. Bind them instead (`:name` placeholders with `oci_bind_by_name`, or the bindings array), or read through TELLER\'s named queries: https://git.wayne.edu/enterprise-apps/teller',
                $name,
                $path,
                $line,
            ),
            $path,
            $line,
        );
    }
}
