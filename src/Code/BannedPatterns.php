<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Code;

/**
 * The portability rules as data, so adding a rule is one entry in a list and
 * no new code. Names are lowercase, fully qualified and without a leading
 * backslash; the check resolves `use` aliases before it looks them up, so a
 * rule written here matches however the app spelled the call.
 */
final class BannedPatterns
{
    /**
     * @param list<string> $iniFunctions     functions that set a php.ini key: (key, value, ...)
     * @param list<string> $iniStaticCalls   "class::method" calls that set a config key: (key, value, ...)
     * @param list<string> $hostPathPrefixes string literals starting with one of these are host paths
     * @param list<string> $proxyKeywords    a line with one of these puts an IPv4 literal in a proxy context
     * @param list<string> $notProxyAddresses IPv4 literals that never count as proxy addresses
     */
    public function __construct(
        public readonly array $iniFunctions,
        public readonly array $iniStaticCalls,
        public readonly array $hostPathPrefixes,
        public readonly array $proxyKeywords,
        public readonly array $notProxyAddresses,
    ) {}

    /**
     * The rules PROCTOR ships with.
     */
    public static function defaults(): self
    {
        return new self(
            ['ini_set', 'ini_alter'],
            ['illuminate\support\facades\config::set', 'config::set'],
            ['/var/', '/srv/', '/home/', '/opt/', '/etc/', '/tmp/'],
            ['trusted_proxies', 'trustproxies', 'x-forwarded', 'http_x_forwarded', 'remote_addr'],
            ['127.0.0.1', '0.0.0.0'],
        );
    }
}
