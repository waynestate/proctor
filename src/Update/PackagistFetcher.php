<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Update;

use Throwable;

/**
 * The default LatestReleaseFetcher: a GET of the Packagist p2 metadata over
 * a stream context, about 1.5 s in total (connect 1 s).
 *
 * It never throws and never warns: anything wrong returns null, which the
 * notice treats as "offline" and caches as such for the day. Packagist is a
 * CDN that git.wayne.edu's throttle does not touch, and it is the only
 * source this notice reads.
 */
final class PackagistFetcher implements LatestReleaseFetcher
{
    private const URL = 'https://repo.packagist.org/p2/waynestate/proctor.json';

    private const PACKAGE = 'waynestate/proctor';

    public function fetch(): ?array
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 1.5,
                    'connect_timeout' => 1,
                    'header' => "User-Agent: waynestate-proctor-update-check\r\n",
                    // Read the body even on an HTTP error so parse() can
                    // reject it by shape instead of file_get_contents
                    // failing outright.
                    'ignore_errors' => true,
                ],
            ]);

            $body = $this->readBody(self::URL, $context);

            return $body === null ? null : self::parse($body);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The package entries of a p2 metadata body, or null when the body is
     * not the p2 document for waynestate/proctor. Pure: no I/O, so tests
     * can feed it malformed JSON without a network.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function parse(string $body): ?array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }
        /** @var array<string, mixed> $decoded */

        $packages = $decoded['packages'] ?? null;
        if (!is_array($packages) || !isset($packages[self::PACKAGE])) {
            return null;
        }
        /** @var array<string, mixed> $packages */

        $entries = $packages[self::PACKAGE];
        if (!is_array($entries)) {
            return null;
        }
        /** @var array<mixed> $entries */

        $stable = [];
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $stable[] = $entry;
            }
        }

        return $stable;
    }

    /**
     * Read the body without a warning reaching stderr: file_get_contents
     * raises a warning on failure, and the notice must stay silent, so a
     * temporary error handler swallows it and the result is checked.
     *
     * @param resource $context the stream context from fetch()
     */
    private function readBody(string $url, mixed $context): ?string
    {
        $previous = set_error_handler(static fn(): bool => true);
        try {
            $body = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        return is_string($body) ? $body : null;
    }
}
