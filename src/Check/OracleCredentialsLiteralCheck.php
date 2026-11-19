<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use Waynestate\Proctor\Code\PhpFiles;
use Waynestate\Proctor\Code\RepoFiles;

/**
 * Warns when an Oracle password is written into committed code or files.
 *
 * TELLER is the stack's read-only Oracle layer, and it reads its own
 * credentials from the environment (TELLER_PASSWORD or TELLER_PASSWORD_FILE).
 * A password typed into app code, a connect string or an Oracle config file
 * ships with the repository, so anyone who can read the repository can read
 * the database. The warning names the file, the line and the call, never the
 * password, and points the reader at the environment variable and at TELLER,
 * which already knows how to read it.
 *
 * Scanned: every committed file outside vendor/, node_modules/, storage/,
 * tests/ and .git/ at any depth. Flagged: a wallet by name (cwallet.sso,
 * ewallet.p12 or ewallet.pem), one warning per file on line 1; a tnsnames.ora
 * or sqlnet.ora whose first PASSWORD = line carries the credential, one
 * warning per file on that line; an oci_connect, oci_pconnect or
 * oci_new_connect call (case-insensitive, after namespace fallback to the
 * global function) whose second argument is a non-empty string literal; and
 * any plain string literal in a *.php file in the user/password@host:port/
 * service form. A password from env() or getenv(), a variable, a constant or
 * a method call, an empty string, a one-argument oci_connect, a method call
 * named like the OCI function and a plain path such as docs/readme.md are not
 * flagged. Blade templates (*.blade.php) are skipped, and a file that does
 * not parse is skipped here; the portability check reports it. One warning
 * per finding, sorted by file and then line. Warning, never fail: the fix is
 * a rotation and a refactor, and a new check must not turn a passing app into
 * a failing one.
 *
 * Emits proctor/oracle-credentials-literal on pass and on every warning.
 *
 * The proctor finds the answer key taped under the desk.
 */
final class OracleCredentialsLiteralCheck implements CheckInterface
{
    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', 'storage', 'tests', '.git'];

    /** Wallet basenames, lowercased: an Oracle wallet committed to the repo. */
    private const WALLET_BASENAMES = ['cwallet.sso', 'ewallet.p12', 'ewallet.pem'];

    /** Oracle config basenames, lowercased: a PASSWORD line there is a literal credential. */
    private const ORA_BASENAMES = ['tnsnames.ora', 'sqlnet.ora'];

    /** OCI entry points that take the password as their second argument. */
    private const OCI_CONNECT_FUNCTIONS = ['oci_connect', 'oci_pconnect', 'oci_new_connect'];

    /** The user/password@host:port/service connect string form. */
    private const EZCONNECT_PATTERN = '~^[A-Za-z][A-Za-z0-9_$#]*/[^/@\s"]+@(//)?[A-Za-z0-9][A-Za-z0-9.-]*(:\d+)?(/[A-Za-z0-9_.$#-]+)?$~';

    public function id(): string
    {
        return 'oracle-credentials-literal';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        $findings = [];
        $phpFiles = new PhpFiles();
        foreach (RepoFiles::list($appPath, $context->timeout, self::SKIP_DIRS)->files as $path) {
            $basename = strtolower(basename($path));
            if (in_array($basename, self::WALLET_BASENAMES, true)) {
                $findings[] = $this->walletWarning($path);
                continue;
            }
            if (in_array($basename, self::ORA_BASENAMES, true)) {
                $line = $this->firstPasswordLine($appPath . '/' . $path);
                if ($line !== null) {
                    $findings[] = $this->oraWarning($path, $line);
                }
                continue;
            }
            if (!str_ends_with($path, '.php') || str_ends_with($path, '.blade.php')) {
                continue;
            }
            $statements = $phpFiles->statements($appPath . '/' . $path);
            if ($statements === null) {
                continue;
            }
            $resolved = $phpFiles->resolveNames($statements);
            foreach ($this->literalPasswordCalls($phpFiles, $resolved) as [$line, $name]) {
                $findings[] = $this->connectWarning($path, $line, $name);
            }
            foreach ($this->ezconnectLines($phpFiles, $resolved) as $line) {
                $findings[] = $this->ezconnectWarning($path, $line);
            }
        }

        usort(
            $findings,
            static fn(Finding $a, Finding $b): int => [$a->path, $a->line, $a->message] <=> [$b->path, $b->line, $b->message],
        );

        if ($findings === []) {
            $findings[] = new Finding($this->id(), Finding::STATUS_PASS, 'no Oracle passwords are written into the repository');
        }

        return $findings;
    }

    /**
     * The connection calls whose password is a non-empty string literal:
     * [line, name as written], sorted by line.
     *
     * @param array<Node\Stmt> $statements
     *
     * @return list<array{0: int, 1: string}>
     */
    private function literalPasswordCalls(PhpFiles $phpFiles, array $statements): array
    {
        $calls = [];
        foreach ($phpFiles->findInstanceOf($statements, FuncCall::class) as $call) {
            if (!$call->name instanceof Name) {
                continue;
            }
            if (!in_array(strtolower($call->name->toString()), self::OCI_CONNECT_FUNCTIONS, true)) {
                continue;
            }
            $password = $call->getArgs()[1]->value ?? null;
            if ($password instanceof String_ && $password->value !== '') {
                $calls[] = [$call->getStartLine(), $call->name->toString()];
            }
        }
        usort($calls, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return $calls;
    }

    /**
     * The lines of the plain string literals in the user/password@host:port/
     * service form, sorted by line. Parts of an interpolated string are not
     * plain literals: they are graded where the interpolation is.
     *
     * @param array<Node\Stmt> $statements
     *
     * @return list<int>
     */
    private function ezconnectLines(PhpFiles $phpFiles, array $statements): array
    {
        $inside = [];
        foreach ($phpFiles->findInstanceOf($statements, InterpolatedString::class) as $interpolated) {
            foreach ($interpolated->parts as $part) {
                if ($part instanceof String_) {
                    $inside[spl_object_id($part)] = true;
                }
            }
        }
        $lines = [];
        foreach ($phpFiles->findInstanceOf($statements, String_::class) as $string) {
            if (isset($inside[spl_object_id($string)])) {
                continue;
            }
            if (preg_match(self::EZCONNECT_PATTERN, $string->value) === 1) {
                $lines[] = $string->getStartLine();
            }
        }
        sort($lines);

        return $lines;
    }

    /**
     * The line of the first PASSWORD = line in an Oracle config file, or
     * null when the file has none or cannot be read.
     */
    private function firstPasswordLine(string $absolutePath): ?int
    {
        $content = @file_get_contents($absolutePath);
        if (!is_string($content)) {
            return null;
        }
        foreach (preg_split('/\R/', $content) ?: [] as $i => $line) {
            if (preg_match('/\bpassword\s*=/i', (string) $line) === 1) {
                return $i + 1;
            }
        }

        return null;
    }

    /** The warning for a committed wallet file. */
    private function walletWarning(string $path): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                '`%s` is an Oracle wallet committed to the repository. Remove it from git and mount it at deploy time: https://git.wayne.edu/enterprise-apps/teller',
                $path,
            ),
            $path,
            1,
        );
    }

    /** The warning for an Oracle config file carrying a password line. */
    private function oraWarning(string $path, int $line): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                '`%s` line %d carries a password. Keep only the connect descriptors in the repository and read the password from the environment',
                $path,
                $line,
            ),
            $path,
            $line,
        );
    }

    /** The warning for a connection call with its password in the code. */
    private function connectWarning(string $path, int $line, string $name): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                '`%s()` at %s:%d has its password written in the code. Read it from the environment (`TELLER_PASSWORD` or `TELLER_PASSWORD_FILE` with TELLER): https://git.wayne.edu/enterprise-apps/teller',
                $name,
                $path,
                $line,
            ),
            $path,
            $line,
        );
    }

    /** The warning for a connect string that carries a password. */
    private function ezconnectWarning(string $path, int $line): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                'A connect string at %s:%d carries a password. Read it from the environment instead: https://git.wayne.edu/enterprise-apps/teller',
                $path,
                $line,
            ),
            $path,
            $line,
        );
    }
}
