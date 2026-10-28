<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Proctor\Code\BannedPatterns;
use Waynestate\Proctor\Code\PhpFiles;

/**
 * Warns when an app hard-codes a platform fact: an absolute host path, a proxy
 * address, a local-disk session store, or a php_value/php_flag line in .htaccess
 * (those do nothing under php-fpm). Platform facts belong in env or config.
 *
 * PHP files are read as an AST with names resolved, so a platform fact that
 * only appears in a comment is not flagged, a call split over several lines is
 * still caught, and `use function ... as ...` aliases cannot hide a call. The
 * lists the rules read (function names, class calls, path prefixes) live in
 * BannedPatterns. Non-PHP files (.htaccess, plain config) are still scanned
 * line by line; the .htaccess php_value rule is the only regular expression
 * left, because .htaccess is not PHP and has no tree to walk.
 */
final class PortabilityCheck implements CheckInterface
{
    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', 'storage', 'tests', '.git'];

    private readonly BannedPatterns $rules;

    public function __construct(?BannedPatterns $rules = null)
    {
        $this->rules = $rules ?? BannedPatterns::defaults();
    }

    public function id(): string
    {
        return 'portability';
    }

    public function run(CheckContext $context): array
    {
        $findings = [];
        $phpFiles = new PhpFiles();
        foreach ($this->filesToScan($context->appPath) as $relativePath) {
            $absolutePath = $context->appPath . '/' . $relativePath;
            $lines = file($absolutePath, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            if (str_ends_with($relativePath, '.php')) {
                $statements = $phpFiles->statements($absolutePath);
                if ($statements === null) {
                    $findings[] = new Finding(
                        $this->id(),
                        Finding::STATUS_WARNING,
                        'could not parse ' . $relativePath . ': ' . $phpFiles->lastError(),
                        $relativePath,
                        $phpFiles->lastErrorLine(),
                        null,
                        'parse-error',
                    );
                    continue;
                }
                foreach ($this->findingsForPhpFile($relativePath, $statements, $phpFiles, $lines) as $finding) {
                    $findings[] = $finding;
                }
            } else {
                foreach ($lines as $index => $line) {
                    $line = rtrim((string) $line, "\r\n");
                    foreach ($this->messagesForLine($relativePath, $line) as [$rule, $message]) {
                        $findings[] = new Finding(
                            $this->id(),
                            Finding::STATUS_WARNING,
                            $message,
                            $relativePath,
                            $index + 1,
                            null,
                            $rule,
                        );
                    }
                }
            }
        }

        // Info findings ("cannot analyse") are notes, not problems: a file full
        // of closures still earns its pass.
        $problems = array_filter(
            $findings,
            static fn(Finding $finding): bool => Finding::STATUS_INFO !== $finding->status,
        );
        if ($problems === []) {
            $findings[] = new Finding($this->id(), Finding::STATUS_PASS, 'no hard-coded platform facts found');
        }

        // Stable on every OS: by path, then line.
        usort($findings, static function (Finding $a, Finding $b): int {
            return strcmp((string) $a->path, (string) $b->path) ?: ($a->line <=> $b->line);
        });

        return $findings;
    }

    /**
     * The relative paths (forward slashes) of the files this check scans:
     * every *.php file, every file under the top-level config/ directory,
     * and every file named .htaccess.
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
            if (str_ends_with($basename, '.php')
                || str_starts_with($relativePath, 'config/')
                || '.htaccess' === $basename) {
                $files[] = $relativePath;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The findings for one PHP file, read as an AST. A platform fact that only
     * appears in a comment is not a String_ node, so it is not flagged.
     *
     * @param string             $relativePath the file's path relative to the app
     * @param array<Node\Stmt>   $statements   the parsed statements
     * @param PhpFiles           $phpFiles     the shared AST layer
     * @param array<int, string> $lines        the file's lines (for proxy context)
     *
     * @return list<Finding>
     */
    private function findingsForPhpFile(string $relativePath, array $statements, PhpFiles $phpFiles, array $lines): array
    {
        $findings = [];
        $statements = $phpFiles->resolveNames($statements);
        $strings = $phpFiles->findInstanceOf($statements, String_::class);

        // host-path: a string literal that is an absolute host path.
        foreach ($strings as $string) {
            if ($this->isHostPath($string->value)) {
                $findings[] = $this->warning(
                    'host-path',
                    sprintf('absolute host path "%s"; read it from env or config', $string->value),
                    $relativePath,
                    $string->getStartLine(),
                );
            }
        }

        // proxy-address: an IPv4 literal in a string, in a proxy context.
        // 127.0.0.1 and 0.0.0.0 never count.
        foreach ($strings as $string) {
            foreach ($this->ipv4Literals($string->value) as $ip) {
                if (in_array($ip, $this->rules->notProxyAddresses, true)) {
                    continue;
                }
                if ($this->inProxyContext($string->getStartLine(), $lines, $relativePath)) {
                    $findings[] = $this->warning(
                        'proxy-address',
                        sprintf('hard-coded proxy address %s; use HELMET_PRESET or HELMET_PROXIES', $ip),
                        $relativePath,
                        $string->getStartLine(),
                    );
                }
            }
        }

        // local-session: at most one finding per line. The file driver in
        // config/session.php, or session.save_path set to an absolute path.
        $sessionLines = [];
        if ('config/session.php' === $relativePath) {
            foreach ($phpFiles->findInstanceOf($statements, ArrayItem::class) as $item) {
                if ($this->isFileDriver($item)) {
                    $sessionLines[$item->getStartLine()] = true;
                }
            }
        }

        // Calls are matched on their resolved names, so an aliased import or a
        // call split over several lines is still caught.
        $dynamicLines = [];
        foreach ($phpFiles->findInstanceOf($statements, FuncCall::class) as $call) {
            if (!$call->name instanceof Name) {
                $dynamicLines[] = $call->getStartLine();
                continue;
            }
            if (in_array($this->resolvedName($call->name), $this->rules->iniFunctions, true)
                && $this->setsAbsoluteSavePath($call->getArgs())) {
                $sessionLines[$call->getStartLine()] = true;
            }
        }
        foreach ($phpFiles->findInstanceOf($statements, StaticCall::class) as $call) {
            if (!$call->class instanceof Name || !$call->name instanceof Node\Identifier) {
                continue;
            }
            $target = $this->resolvedName($call->class) . '::' . strtolower($call->name->toString());
            if (in_array($target, $this->rules->iniStaticCalls, true)
                && $this->setsAbsoluteSavePath($call->getArgs())) {
                $sessionLines[$call->getStartLine()] = true;
            }
        }
        foreach (array_keys($sessionLines) as $line) {
            $findings[] = $this->warning(
                'local-session',
                'sessions on local disk; use a shared store (database or redis)',
                $relativePath,
                $line,
            );
        }

        // A call through a variable hides its target. Say so once per file
        // instead of letting it pass for clean (the tree cannot see through $f).
        if ($dynamicLines !== []) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_INFO,
                sprintf(
                    'dynamic-call: cannot analyse %d call(s) through a variable, like $f(); a banned pattern behind one is not seen',
                    count($dynamicLines),
                ),
                $relativePath,
                min($dynamicLines),
                null,
                'dynamic-call',
            );
        }

        return $findings;
    }

    /**
     * The warning messages for one line of a non-PHP file, in rule order. A
     * line that breaks more than one rule produces more than one message.
     *
     * @param string $relativePath the file's path relative to the app
     * @param string $line         the line without its line ending
     *
     * @return list<array{0: string, 1: string}> pairs of [rule, full message]
     */
    private function messagesForLine(string $relativePath, string $line): array
    {
        $messages = [];

        // host-path: a quoted literal that starts with a host path prefix or
        // a Windows drive letter.
        foreach ($this->quotedLiterals($line) as $literal) {
            if ($this->isHostPath($literal)) {
                $messages[] = ['host-path', sprintf(
                    'host-path: absolute host path "%s"; read it from env or config',
                    $literal,
                )];
            }
        }

        // proxy-address: an IPv4 literal in a proxy context. In any file the
        // context is a proxy keyword; under config/ the word "prox" alone
        // counts. 127.0.0.1 and 0.0.0.0 never count.
        $lowerLine = strtolower($line);
        $inProxyContext = false;
        foreach ($this->rules->proxyKeywords as $keyword) {
            if (str_contains($lowerLine, $keyword)) {
                $inProxyContext = true;
                break;
            }
        }
        if (!$inProxyContext && str_starts_with($relativePath, 'config/') && str_contains($lowerLine, 'prox')) {
            $inProxyContext = true;
        }
        if ($inProxyContext) {
            foreach ($this->ipv4Literals($line) as $ip) {
                if (in_array($ip, $this->rules->notProxyAddresses, true)) {
                    continue;
                }
                $messages[] = ['proxy-address', sprintf(
                    'proxy-address: hard-coded proxy address %s; use HELMET_PRESET or HELMET_PROXIES',
                    $ip,
                )];
            }
        }

        // local-session: session.save_path together with a quoted absolute
        // path, or the file driver in config/session.php.
        $sessionHit = str_contains($line, 'session.save_path')
            && $this->hasQuotedLiteral($line, static fn(string $literal): bool => str_starts_with($literal, '/'));
        if (!$sessionHit && 'config/session.php' === $relativePath) {
            $namesTheDriver = $this->hasQuotedLiteral($line, static fn(string $literal): bool => 'driver' === strtolower($literal))
                || str_contains($line, 'SESSION_DRIVER');
            $sessionHit = $namesTheDriver && $this->hasQuotedLiteral($line, static fn(string $literal): bool => 'file' === $literal);
        }
        if ($sessionHit) {
            $messages[] = ['local-session', 'local-session: sessions on local disk; use a shared store (database or redis)'];
        }

        // htaccess-php-setting: php_value/php_flag as the first word of a
        // .htaccess line; under php-fpm the line does nothing. The one regex
        // left in this check: .htaccess is not PHP, so there is no tree.
        if ('.htaccess' === pathinfo($relativePath, PATHINFO_BASENAME)
            && preg_match('/^\s*(php_value|php_flag)\b/i', $line, $matches) === 1) {
            $messages[] = ['htaccess-php-setting', sprintf(
                "htaccess-php-setting: %s does nothing under php-fpm; set it in the platform's php.ini or with ini_set",
                $matches[1],
            )];
        }

        return $messages;
    }

    /**
     * The contents of every single- or double-quoted string literal on the
     * line, in order of appearance. A literal holds no quote of either kind.
     *
     * @return list<string>
     */
    private function quotedLiterals(string $line): array
    {
        $literals = [];
        $length = strlen($line);
        $i = 0;
        while ($i < $length) {
            $quote = $line[$i];
            if ('"' !== $quote && "'" !== $quote) {
                ++$i;
                continue;
            }
            // The next quote of either kind either closes this literal or
            // shows this one was never an opener.
            $end = $i + 1 + strcspn($line, '"\'', $i + 1);
            if ($end < $length && $line[$end] === $quote) {
                $literals[] = substr($line, $i + 1, $end - $i - 1);
                $i = $end + 1;
            } else {
                ++$i;
            }
        }

        return $literals;
    }

    /**
     * Whether the line carries a quoted literal for which $accept is true.
     *
     * @param callable(string): bool $accept
     */
    private function hasQuotedLiteral(string $line, callable $accept): bool
    {
        foreach ($this->quotedLiterals($line) as $literal) {
            if ($accept($literal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a quoted literal is an absolute host path: one of the known
     * Unix prefixes, or a Windows drive letter such as C:\.
     */
    private function isHostPath(string $literal): bool
    {
        foreach ($this->rules->hostPathPrefixes as $prefix) {
            if (str_starts_with($literal, $prefix)) {
                return true;
            }
        }

        return strlen($literal) >= 3
            && ctype_alpha($literal[0])
            && ':' === $literal[1]
            && '\\' === $literal[2];
    }

    /**
     * The IPv4 literals in the text (four dot-separated numbers, each 0-255,
     * optionally followed by /<bits>), without the /<bits> part.
     *
     * @return list<string>
     */
    private function ipv4Literals(string $line): array
    {
        $ips = [];
        $length = strlen($line);
        $i = 0;
        while ($i < $length) {
            $startsHere = ctype_digit($line[$i])
                && (0 === $i || !(ctype_digit($line[$i - 1]) || '.' === $line[$i - 1]));
            $end = $i;
            $octets = $startsHere ? $this->octetsAt($line, $i, $end) : null;
            if (null === $octets) {
                ++$i;
                continue;
            }
            if ($octets !== [] && max($octets) <= 255) {
                $ips[] = implode('.', $octets);
            }
            $i = $end;
        }

        return $ips;
    }

    /**
     * Four dot-separated groups of one to three digits starting at $start,
     * not followed by another digit or a dot (a "/bits" suffix is fine).
     *
     * @param int $end set to the offset just past the fourth group
     *
     * @return list<int>|null
     */
    private function octetsAt(string $line, int $start, int &$end): ?array
    {
        $octets = [];
        $position = $start;
        for ($group = 0; $group < 4; ++$group) {
            $digits = strspn($line, '0123456789', $position);
            if ($digits < 1 || $digits > 3) {
                return null;
            }
            $octets[] = (int) substr($line, $position, $digits);
            $position += $digits;
            if (3 === $group) {
                break;
            }
            if ('.' !== ($line[$position] ?? '')) {
                return null;
            }
            ++$position;
        }
        $next = $line[$position] ?? '';
        if ('.' === $next || ('' !== $next && ctype_digit($next))) {
            return null;
        }
        $end = $position;

        return $octets;
    }

    /**
     * Whether the line carrying a node is in a proxy context: a proxy keyword
     * in any file, or the word "prox" under config/.
     *
     * @param array<int, string> $lines the file's lines
     */
    private function inProxyContext(int $line, array $lines, string $relativePath): bool
    {
        $lowerLine = strtolower((string) ($lines[$line - 1] ?? ''));
        foreach ($this->rules->proxyKeywords as $keyword) {
            if (str_contains($lowerLine, $keyword)) {
                return true;
            }
        }

        return str_starts_with($relativePath, 'config/') && str_contains($lowerLine, 'prox');
    }

    /**
     * Whether an array item is the file session driver: a "driver" key with a
     * "file" value.
     */
    private function isFileDriver(ArrayItem $item): bool
    {
        if (!$item->key instanceof String_ || 'driver' !== strtolower($item->key->value)) {
            return false;
        }

        return $item->value instanceof String_ && 'file' === $item->value->value;
    }

    /**
     * The lowercase fully qualified name, without a leading backslash.
     */
    private function resolvedName(Name $name): string
    {
        return strtolower($name->toString());
    }

    /**
     * Whether the arguments name session.save_path and an absolute path.
     *
     * @param array<Node\Arg|Node\VariadicPlaceholder> $args
     */
    private function setsAbsoluteSavePath(array $args): bool
    {
        $hasSavePath = false;
        $hasAbsolutePath = false;
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg || !$arg->value instanceof String_) {
                continue;
            }
            if ('session.save_path' === $arg->value->value) {
                $hasSavePath = true;
            }
            if (str_starts_with($arg->value->value, '/')) {
                $hasAbsolutePath = true;
            }
        }

        return $hasSavePath && $hasAbsolutePath;
    }

    /**
     * A warning finding for one rule.
     */
    private function warning(string $rule, string $message, string $relativePath, int $line): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            $rule . ': ' . $message,
            $relativePath,
            $line,
            null,
            $rule,
        );
    }
}
