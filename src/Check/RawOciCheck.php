<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Waynestate\Proctor\Code\PhpFiles;
use Waynestate\Proctor\Code\RepoFiles;

/**
 * Warns when app code talks to Oracle directly.
 *
 * TELLER is the stack's read-only Oracle layer: named queries with bound
 * values, a read-only transaction and row caps. App code that opens its own
 * connection with the OCI extension, or a PDO oci: DSN, bypasses all of it,
 * so one ad-hoc query can read more than the app should ever see.
 *
 * Runs on every PHP app. Scanned: every *.php file except vendor/,
 * node_modules/, storage/, tests/ and .git/ at any depth. PHP is read as an
 * AST with names resolved, so an oci_parse in a comment or a log string, and
 * a method call named oci_parse ($db->oci_parse()), are not flagged. Flagged
 * calls: oci_connect, oci_pconnect, oci_new_connect, ocilogon, ociplogon,
 * ocinlogon, oci_parse and ociparse, matched case-insensitively after
 * namespace fallback to the global function, and new PDO(...) / new \PDO(...)
 * whose first argument is a string literal starting with oci:. Blade
 * templates (*.blade.php) are skipped: no Oracle calls belong in a template.
 * A file that does not parse is skipped here; the portability check reports
 * it.
 *
 * One warning per call, never a fail. The proctor notices the student who
 * brought their own answer key to the exam.
 */
final class RawOciCheck implements CheckInterface
{
    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', 'storage', 'tests', '.git'];

    /** OCI extension entry points that open a connection or prepare a statement. */
    private const OCI_FUNCTIONS = [
        'oci_connect',
        'oci_pconnect',
        'oci_new_connect',
        'ocilogon',
        'ociplogon',
        'ocinlogon',
        'oci_parse',
        'ociparse',
    ];

    public function id(): string
    {
        return 'raw-oci';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
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
            foreach ($this->oracleCalls($phpFiles, $phpFiles->resolveNames($statements)) as [$line, $label]) {
                $findings[] = $this->warning($path, $line, $label);
            }
        }

        if ($findings === []) {
            $findings[] = new Finding($this->id(), Finding::STATUS_PASS, 'no app code talks to Oracle directly');
        }

        return $findings;
    }

    /**
     * The direct Oracle calls in a parsed PHP file: [line, label as written],
     * sorted by line.
     *
     * @param array<Node\Stmt> $statements
     *
     * @return list<array{0: int, 1: string}>
     */
    private function oracleCalls(PhpFiles $phpFiles, array $statements): array
    {
        $calls = [];
        foreach ($phpFiles->findInstanceOf($statements, FuncCall::class) as $call) {
            if (!$call->name instanceof Name) {
                continue;
            }
            $name = strtolower($call->name->toString());
            if (in_array($name, self::OCI_FUNCTIONS, true)) {
                $calls[] = [$call->getStartLine(), $call->name->toString() . '()'];
            }
        }
        foreach ($phpFiles->findInstanceOf($statements, New_::class) as $new) {
            if (!$new->class instanceof Name || strtolower($new->class->toString()) !== 'pdo') {
                continue;
            }
            $arg = $new->getArgs()[0] ?? null;
            if ($arg !== null && $arg->value instanceof String_ && stripos($arg->value->value, 'oci:') === 0) {
                $calls[] = [$new->getStartLine(), "new PDO('oci:...')"];
            }
        }
        usort($calls, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return $calls;
    }

    private function warning(string $path, int $line, string $label): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                '`%s` at %s:%d talks to Oracle directly. Read through TELLER\'s named queries instead (bound values, a read-only transaction, row caps): https://git.wayne.edu/enterprise-apps/teller',
                $label,
                $path,
                $line,
            ),
            $path,
            $line,
        );
    }
}
