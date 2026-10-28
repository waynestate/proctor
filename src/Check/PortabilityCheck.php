<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Warns when an app hard-codes a platform fact: an absolute host path, a proxy
 * address, a local-disk session store, or a php_value/php_flag line in .htaccess
 * (those do nothing under php-fpm). Platform facts belong in env or config.
 */
final class PortabilityCheck implements CheckInterface
{
    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', 'storage', 'tests', '.git'];

    /** Quoted literals starting with one of these are absolute host paths. */
    private const HOST_PATH_PREFIXES = ['/var/', '/srv/', '/home/', '/opt/', '/etc/', '/tmp/'];

    /**
     * Keywords that put an IPv4 literal on a line into a proxy context, in
     * any file (case-insensitive).
     */
    private const PROXY_KEYWORDS = ['trusted_proxies', 'trustproxies', 'x-forwarded', 'http_x_forwarded', 'remote_addr'];

    /** IPv4 literals that never count as hard-coded proxy addresses. */
    private const NOT_PROXY_ADDRESSES = ['127.0.0.1', '0.0.0.0'];

    public function id(): string
    {
        return 'portability';
    }

    public function run(CheckContext $context): array
    {
        $findings = [];
        foreach ($this->filesToScan($context->appPath) as $relativePath) {
            $lines = file($context->appPath . '/' . $relativePath, FILE_IGNORE_NEW_LINES);
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

        if ($findings === []) {
            return [new Finding($this->id(), Finding::STATUS_PASS, 'no hard-coded platform facts found')];
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

        // host-path: a quoted literal that starts with a host path prefix or
        // a Windows drive letter.
        foreach ($this->quotedLiterals($line) as $literal) {
            if ($this->isHostPath($literal)) {
                $messages[] = sprintf(
                    'host-path: absolute host path "%s"; read it from env or config',
                    $literal
                );
            }
        }

        // proxy-address: an IPv4 literal in a proxy context. In any file the
        // context is a proxy keyword; under config/ the word "prox" alone
        // counts. 127.0.0.1 and 0.0.0.0 never count.
        $lowerLine = strtolower($line);
        $inProxyContext = false;
        foreach (self::PROXY_KEYWORDS as $keyword) {
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
                if (in_array($ip, self::NOT_PROXY_ADDRESSES, true)) {
                    continue;
                }
                $messages[] = sprintf(
                    'proxy-address: hard-coded proxy address %s; use HELMET_PRESET or HELMET_PROXIES',
                    $ip
                );
            }
        }

        // local-session: session.save_path together with a quoted absolute
        // path, or the file driver in config/session.php.
        $sessionHit = str_contains($line, 'session.save_path')
            && $this->hasQuotedLiteral($line, static fn (string $literal): bool => str_starts_with($literal, '/'));
        if (!$sessionHit && 'config/session.php' === $relativePath) {
            $namesTheDriver = preg_match("/['\"]driver['\"]/i", $line) === 1
                || str_contains($line, 'SESSION_DRIVER');
            $sessionHit = $namesTheDriver && $this->hasQuotedLiteral($line, static fn (string $literal): bool => 'file' === $literal);
        }
        if ($sessionHit) {
            $messages[] = 'local-session: sessions on local disk; use a shared store (database or redis)';
        }

        // htaccess-php-setting: php_value/php_flag as the first word of a
        // .htaccess line; under php-fpm the line does nothing.
        if ('.htaccess' === pathinfo($relativePath, PATHINFO_BASENAME)
            && preg_match('/^\s*(php_value|php_flag)\b/i', $line, $matches) === 1) {
            $messages[] = sprintf(
                "htaccess-php-setting: %s does nothing under php-fpm; set it in the platform's php.ini or with ini_set",
                $matches[1]
            );
        }

        return $messages;
    }

    /**
     * The contents of every single- or double-quoted string literal on the
     * line, in order of appearance.
     *
     * @return list<string>
     */
    private function quotedLiterals(string $line): array
    {
        if (preg_match_all('/(["\'])([^"\']*)\1/', $line, $matches) === false) {
            return [];
        }

        return $matches[2];
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
        foreach (self::HOST_PATH_PREFIXES as $prefix) {
            if (str_starts_with($literal, $prefix)) {
                return true;
            }
        }

        return preg_match('/^[A-Za-z]:\\\\/', $literal) === 1;
    }

    /**
     * The IPv4 literals on the line (four dot-separated numbers, each 0-255,
     * optionally followed by /<bits>), without the /<bits> part.
     *
     * @return list<string>
     */
    private function ipv4Literals(string $line): array
    {
        $ips = [];
        $pattern = '/(?<![\d.])(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?:\/(\d{1,2}))?(?![\d.])/';
        if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER) === false) {
            return $ips;
        }

        foreach ($matches as $match) {
            $octets = [$match[1], $match[2], $match[3], $match[4]];
            foreach ($octets as $octet) {
                if ((int) $octet > 255) {
                    $octets = null;
                    break;
                }
            }
            if (null !== $octets) {
                $ips[] = implode('.', $octets);
            }
        }

        return $ips;
    }
}
