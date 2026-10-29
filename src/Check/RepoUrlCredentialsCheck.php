<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

/**
 * Checks that no credential is written into a package source URL.
 *
 * Many WSU apps install private libraries through vcs repository entries
 * whose URL carries a deploy token (https://gitlab+deploy-token-12:SECRET@
 * host/group/lib.git), and the same URL then lands in composer.lock. The
 * stack decided (D-034) that PROCTOR flags this as a warning, not a
 * failure, because about sixteen production apps carry such URLs today.
 *
 * Scanned when present: composer.json repositories[*].url (array or object
 * form), composer.lock packages and packages-dev source.url and dist.url,
 * and .npmrc lines. A URL is flagged when its userinfo carries a secret
 * (scheme://user:secret@host), when the user part alone looks like a token
 * (20+ characters of [A-Za-z0-9_-]), or when a query parameter is named
 * private_token, access_token, token or password. A .npmrc line is flagged
 * when it sets _authToken, _auth or _password to a literal value; a value
 * of ${SOME_VAR} is fine. git@host: SSH URLs and a user@ without a secret
 * part are not flagged. The secret never appears in a message; it is
 * masked as ***.
 */
final class RepoUrlCredentialsCheck implements CheckInterface
{
    /**
     * Query parameter names that mark a URL as carrying a credential.
     *
     * @var list<string>
     */
    private const CREDENTIAL_QUERY_PARAMS = [
        'private_token',
        'access_token',
        'token',
        'password',
    ];

    /**
     * .npmrc keys whose value must not be a literal credential.
     *
     * @var list<string>
     */
    private const NPMRC_CREDENTIAL_KEYS = [
        '_authToken',
        '_auth',
        '_password',
    ];

    public function id(): string
    {
        return 'repo-url-credentials';
    }

    public function run(CheckContext $context): array
    {
        $findings = [];

        $composerJson = $this->readJson($context->appPath . '/composer.json');
        if (is_array($composerJson)) {
            foreach ($this->repositoryUrls($composerJson) as $url) {
                $this->inspectUrl($url, 'repository URL in composer.json', 'composer.json', 1, $findings);
            }
        }

        $lock = $this->readJson($context->appPath . '/composer.lock');
        if (is_array($lock)) {
            foreach (['packages', 'packages-dev'] as $section) {
                $packages = $lock[$section] ?? [];
                if (!is_array($packages)) {
                    continue;
                }
                foreach ($packages as $package) {
                    if (!is_array($package)) {
                        continue;
                    }
                    $name = isset($package['name']) && is_string($package['name'])
                        ? $package['name']
                        : 'unknown package';
                    foreach (['source', 'dist'] as $kind) {
                        $url = $package[$kind]['url'] ?? null;
                        if (is_string($url) && $url !== '') {
                            $this->inspectUrl(
                                $url,
                                sprintf('%s URL for %s in composer.lock', $kind, $name),
                                'composer.lock',
                                1,
                                $findings,
                            );
                        }
                    }
                }
            }
        }

        $npmrcFile = $context->appPath . '/.npmrc';
        if (is_file($npmrcFile)) {
            $raw = file_get_contents($npmrcFile);
            if ($raw !== false) {
                $lines = preg_split('/\R/', $raw) ?: [];
                foreach ($lines as $index => $line) {
                    $this->inspectNpmrcLine($line, $index + 1, $findings);
                }
            }
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no credentials found in repository URLs',
            )];
        }

        return $findings;
    }

    /**
     * Adds a warning finding for each credential the URL carries. The
     * message names the file and the host, never the secret.
     *
     * @param list<Finding> $findings
     */
    private function inspectUrl(string $url, string $what, string $file, int $line, array &$findings): void
    {
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return;
        }

        // scheme://user:secret@host and scheme://token@host put the
        // credential in the userinfo. git@host: has no scheme at all and
        // ssh://git@host has a short plain user, so both fall through.
        $userinfo = $parsed['user'] ?? null;
        if (is_string($userinfo) && $userinfo !== '') {
            $secret = $parsed['pass'] ?? null;
            if (is_string($secret) && $secret !== '') {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_WARNING,
                    sprintf(
                        '%s contains a credential for host %s (user "***", password "***")',
                        $what,
                        $this->hostOf($parsed),
                    ),
                    $file,
                    $line,
                );
            } elseif ($secret === null && preg_match('/^[A-Za-z0-9_-]{20,}$/', $userinfo) === 1) {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_WARNING,
                    sprintf(
                        '%s uses a bare token as the user for host %s (masked as "***")',
                        $what,
                        $this->hostOf($parsed),
                    ),
                    $file,
                    $line,
                );
            }
        }

        $query = $parsed['query'] ?? null;
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            foreach (self::CREDENTIAL_QUERY_PARAMS as $param) {
                if (isset($params[$param])) {
                    $findings[] = new Finding(
                        $this->id(),
                        Finding::STATUS_WARNING,
                        sprintf(
                            '%s carries a "%s" query parameter for host %s (value masked as "***")',
                            $what,
                            $param,
                            $this->hostOf($parsed),
                        ),
                        $file,
                        $line,
                    );
                }
            }
        }
    }

    /**
     * Flags a .npmrc line that sets a credential key to a literal value,
     * and any URL in the line that carries a credential.
     *
     * @param list<Finding> $findings
     */
    private function inspectNpmrcLine(string $line, int $lineNo, array &$findings): void
    {
        $trimmed = trim($line);
        if ($trimmed !== '' && !str_starts_with($trimmed, '#')) {
            $equals = strpos($trimmed, '=');
            if ($equals !== false) {
                $key = substr($trimmed, 0, $equals);
                // Keys may be scoped to a registry: //host/path:_authToken.
                $colon = strrpos($key, ':');
                if ($colon !== false) {
                    $key = substr($key, $colon + 1);
                }
                $value = trim(substr($trimmed, $equals + 1));
                if (in_array($key, self::NPMRC_CREDENTIAL_KEYS, true)
                    && $value !== ''
                    && !$this->isVariableReference($value)
                ) {
                    $findings[] = new Finding(
                        $this->id(),
                        Finding::STATUS_WARNING,
                        sprintf('.npmrc line %d sets "%s" to a literal value (masked as "***")', $lineNo, $key),
                        '.npmrc',
                        $lineNo,
                    );
                }
            }
        }

        if (preg_match_all('/\b[a-zA-Z][a-zA-Z0-9+.-]*:\/\/[^\s]+/', $line, $matches) === 1) {
            foreach ($matches[0] as $candidate) {
                $this->inspectUrl($candidate, sprintf('.npmrc line %d URL', $lineNo), '.npmrc', $lineNo, $findings);
            }
        }
    }

    /**
     * True when the whole value is an environment variable reference,
     * ${SOME_VAR} or $SOME_VAR, which npm expands at install time.
     */
    private function isVariableReference(string $value): bool
    {
        return preg_match('/^\$(\{[^}]+\}|[A-Za-z_][A-Za-z0-9_]*)$/', $value) === 1;
    }

    /**
     * The urls of the composer.json repositories, in order, from the array
     * form (list of objects) and the object form (name to object map).
     *
     * @param array<string, mixed> $composerJson
     *
     * @return list<string>
     */
    private function repositoryUrls(array $composerJson): array
    {
        $repos = $composerJson['repositories'] ?? [];
        if (!is_array($repos)) {
            return [];
        }

        $urls = [];
        foreach ($repos as $repo) {
            if (is_string($repo) && $repo !== '') {
                $urls[] = $repo;
            } elseif (is_array($repo)) {
                $url = $repo['url'] ?? null;
                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * Reads a JSON file as an array, or null when absent or unreadable.
     * composer.json is validated by the CheckRunner before any check runs;
     * composer.lock and .npmrc are optional inputs this check skips when
     * they are missing or malformed.
     *
     * @return array<string, mixed>|null
     */
    private function readJson(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * The host part of a parsed URL, for the message.
     *
     * @param array<string, mixed> $parsed
     */
    private function hostOf(array $parsed): string
    {
        return isset($parsed['host']) && is_string($parsed['host']) && $parsed['host'] !== ''
            ? $parsed['host']
            : 'unknown host';
    }
}
