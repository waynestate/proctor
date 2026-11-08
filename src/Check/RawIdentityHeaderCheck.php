<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Waynestate\Proctor\Code\PhpFiles;
use Waynestate\Proctor\Code\RepoFiles;

/**
 * Warns when app code reads an identity header directly.
 *
 * DRAWBRIDGE, the gateway, signs the session into the X-Sesame-Assertion
 * header and passes the user's claims as request headers: X-Sesame-Assertion,
 * REMOTE_USER, X-Authenticated-*, X-Drawbridge-*, OIDC_CLAIM_* and friends.
 * Only SESAME's gateway driver may open that pair: it verifies the signature
 * before a claim becomes the logged-in user. App code that reads a claim
 * header itself accepts whatever value the proxy, or a client, put there.
 *
 * Runs on every PHP app. Scanned: every *.php file except vendor/,
 * node_modules/, storage/, tests/ and .git/ at any depth. PHP is read as an
 * AST with names resolved, so a header name in a comment or a log string, a
 * variable named $REMOTE_USER, and getenv() with a computed argument are not
 * flagged. Flagged read positions: a string key on $_SERVER or $_ENV, the
 * quoted argument of getenv() or apache_getenv(), the quoted first argument
 * of a method named header, server, hasHeader, getHeader or getHeaderLine
 * (and of get() on a ->headers object), and a string key on the result of
 * getallheaders() or apache_request_headers(), directly or through a
 * variable assigned from them. Names match case-insensitively with - and _
 * interchangeable; where the read comes from $_SERVER or the environment
 * (and from request()->server(), which reads the same bag) the HTTP_ prefix
 * Apache adds to request headers is accepted too. PHP_AUTH_USER is
 * basic-auth material, so it is not an identity claim and is not flagged.
 * Blade templates
 * (*.blade.php) are not PHP until compiled, so a Blade line is matched with
 * a pattern for $_SERVER['...'] and request()->header('...') /
 * request()->server('...'). A file that does not parse is skipped here; the
 * portability check reports it.
 *
 * One warning per read, never a fail. The proctor checks the ID at the door;
 * it does not trust the name written on the exam paper.
 */
final class RawIdentityHeaderCheck implements CheckInterface
{
    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', 'storage', 'tests', '.git'];

    /** Superglobals whose string keys are request data. */
    private const SERVER_VARS = ['_SERVER', '_ENV'];

    /** Function names whose quoted argument reads the environment. */
    private const ENV_FUNCTIONS = ['getenv', 'apache_getenv'];

    /** Functions that return the request headers as an array. */
    private const HEADER_SOURCE_FUNCTIONS = ['getallheaders', 'apache_request_headers'];

    /** Method names whose quoted first argument reads a request header. */
    private const HEADER_METHODS = ['header', 'server', 'hasheader', 'getheader', 'getheaderline'];

    /**
     * Blade is not PHP until compiled, so these patterns stand in for the
     * AST: $_SERVER['...'] and request()->header('...') /
     * request()->server('...').
     */
    private const BLADE_SERVER_PATTERN = '/\$_SERVER\s*\[\s*([\'"])([^\'"]+)\1\s*\]/';

    private const BLADE_REQUEST_PATTERN = '/request\(\)\s*->\s*(header|server)\s*\(\s*([\'"])([^\'"]+)\2\s*\)/i';

    public function id(): string
    {
        return 'raw-identity-header';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        $findings = [];
        $phpFiles = new PhpFiles();
        foreach (RepoFiles::list($appPath, $context->timeout, self::SKIP_DIRS)->files as $path) {
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $absolute = $appPath . '/' . $path;
            if (str_ends_with($path, '.blade.php')) {
                $reads = $this->bladeReads($absolute);
            } else {
                $statements = $phpFiles->statements($absolute);
                if ($statements === null) {
                    continue;
                }
                $reads = $this->phpReads($phpFiles, $phpFiles->resolveNames($statements));
            }
            foreach ($reads as [$line, $header]) {
                $findings[] = $this->warning($path, $line, $header);
            }
            unset($reads);
        }

        if ($findings === []) {
            $findings[] = new Finding($this->id(), Finding::STATUS_PASS, 'no app code reads an identity header directly');
        }

        return $findings;
    }

    /**
     * The identity header reads in a parsed PHP file: [line, header as
     * written], sorted by line.
     *
     * @param array<Node\Stmt> $statements
     *
     * @return list<array{0: int, 1: string}>
     */
    private function phpReads(PhpFiles $phpFiles, array $statements): array
    {
        $reads = [];

        // $headers = getallheaders(); $headers['REMOTE_USER'] carries the
        // headers too, so remember the variables assigned from the two
        // header-source functions in this file.
        $headerVariables = [];
        foreach ($phpFiles->findInstanceOf($statements, Assign::class) as $assign) {
            $source = $this->functionName($assign->expr);
            if ($assign->var instanceof Variable
                && is_string($assign->var->name)
                && $source !== null
                && in_array($source, self::HEADER_SOURCE_FUNCTIONS, true)
            ) {
                $headerVariables[strtolower($assign->var->name)] = true;
            }
        }

        foreach ($phpFiles->findInstanceOf($statements, ArrayDimFetch::class) as $fetch) {
            if (!$fetch->dim instanceof String_) {
                continue;
            }
            $written = $fetch->dim->value;
            $allowHttpPrefix = false;
            $isHeaderSource = false;
            if ($fetch->var instanceof Variable && is_string($fetch->var->name)) {
                if (in_array(strtoupper($fetch->var->name), self::SERVER_VARS, true)) {
                    $allowHttpPrefix = true;
                } elseif (isset($headerVariables[strtolower($fetch->var->name)])) {
                    $isHeaderSource = true;
                }
            } else {
                $source = $this->functionName($fetch->var);
                $isHeaderSource = $source !== null && in_array($source, self::HEADER_SOURCE_FUNCTIONS, true);
            }
            if (!$allowHttpPrefix && !$isHeaderSource) {
                continue;
            }
            $header = $this->matchName($written, $allowHttpPrefix);
            if ($header !== null) {
                $reads[] = [$fetch->getStartLine(), $header];
            }
        }

        foreach ($phpFiles->findInstanceOf($statements, FuncCall::class) as $call) {
            $name = $this->functionName($call);
            if ($name === null || !in_array($name, self::ENV_FUNCTIONS, true)) {
                continue;
            }
            $arg = $call->getArgs()[0] ?? null;
            if ($arg !== null && $arg->value instanceof String_ && ($header = $this->matchName($arg->value->value, true)) !== null) {
                $reads[] = [$call->getStartLine(), $header];
            }
        }

        foreach ($phpFiles->findInstanceOf($statements, MethodCall::class) as $call) {
            $name = $call->name instanceof Identifier ? strtolower($call->name->name) : null;
            if ($name === null) {
                continue;
            }
            $fromServerBag = $name === 'server';
            if (!in_array($name, self::HEADER_METHODS, true)
                && !($name === 'get' && $this->onHeaders($call))
            ) {
                continue;
            }
            $arg = $call->getArgs()[0] ?? null;
            if ($arg !== null && $arg->value instanceof String_ && ($header = $this->matchName($arg->value->value, $fromServerBag)) !== null) {
                $reads[] = [$call->getStartLine(), $header];
            }
        }

        usort($reads, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return $reads;
    }

    /**
     * The identity header reads in a Blade template: [line, header as
     * written], sorted by line.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function bladeReads(string $absolute): array
    {
        $lines = @file($absolute, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }
        $reads = [];
        foreach ($lines as $index => $line) {
            $text = (string) $line;
            if (preg_match_all(self::BLADE_SERVER_PATTERN, $text, $matches) > 0) {
                foreach ($matches[2] as $written) {
                    if (($header = $this->matchName($written, true)) !== null) {
                        $reads[] = [$index + 1, $header];
                    }
                }
            }
            if (preg_match_all(self::BLADE_REQUEST_PATTERN, $text, $matches) > 0) {
                foreach ($matches[0] as $i => $full) {
                    $method = $matches[1][$i];
                    $written = $matches[3][$i];
                    $fromServerBag = strtolower($method) === 'server';
                    if (($header = $this->matchName($written, $fromServerBag)) !== null) {
                        $reads[] = [$index + 1, $header];
                    }
                }
            }
        }
        usort($reads, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return $reads;
    }

    /**
     * Whether the call is ->get() on a ->headers object, as in
     * $request->headers->get('X-Drawbridge-Role').
     */
    private function onHeaders(MethodCall $call): bool
    {
        $inner = $call->var;
        if ($inner instanceof MethodCall || $inner instanceof PropertyFetch) {
            return $inner->name instanceof Identifier && strtolower($inner->name->name) === 'headers';
        }

        return false;
    }

    /**
     * The resolved global function name of a call expression, or null when
     * the expression is not a plain function call.
     */
    private function functionName(Expr $expr): ?string
    {
        if (!$expr instanceof FuncCall || !$expr->name instanceof Name) {
            return null;
        }

        return strtolower($expr->name->toString());
    }

    /**
     * The header name as written when it names an identity claim, else null.
     *
     * Names match case-insensitively with - and _ interchangeable. Where
     * $allowHttpPrefix is set, the read comes from $_SERVER or the
     * environment, and the HTTP_ prefix Apache adds to request headers is
     * stripped before matching.
     */
    private function matchName(string $written, bool $allowHttpPrefix): ?string
    {
        $name = strtolower(str_replace('_', '-', $written));
        if ($allowHttpPrefix && str_starts_with($name, 'http-')) {
            $name = substr($name, 5);
        }
        if ($name === 'remote-user'
            || $name === 'auth-user'
            || $name === 'x-remote-user'
            || $name === 'x-sesame-assertion'
            || $name === 'oidc-access-token'
            || str_starts_with($name, 'x-authenticated-')
            || str_starts_with($name, 'x-drawbridge-')
            || str_starts_with($name, 'oidc-claim-')
        ) {
            return $written;
        }

        return null;
    }

    private function warning(string $path, int $line, string $header): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                'Reads the identity header %s directly in %s:%d; take the user from SESAME (gateway driver), which verifies the signed assertion. CREED auth-3.',
                $header,
                $path,
                $line,
            ),
            $path,
            $line,
        );
    }
}
