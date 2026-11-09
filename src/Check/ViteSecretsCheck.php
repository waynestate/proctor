<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\Code\RepoFiles;

/**
 * Warns when a secret-looking variable carries Vite's VITE_ prefix.
 *
 * Vite exposes every VITE_ variable to the client code it bundles, so the
 * value ships to every visitor's browser. A name that says it holds a
 * secret is a leak waiting for a value. Two rules:
 *
 * - `proctor/vite-secrets/env-file`: a committed env file declares a
 *   secret-looking VITE_ name (`VITE_API_KEY=...`). Example files
 *   (`.env.example`, `.env.squire.example`, `.dist`, `.sample`, `.template`)
 *   are meant to be committed and are always read. Every other `.env*` file
 *   is read only when PROCTOR knows it is committed (git lists it, or the run
 *   is in CI), so the `.env` on a developer's laptop is never opened.
 * - `proctor/vite-secrets/source`: front-end code under the top-level
 *   `resources/js/` or `src/` reads one (`import.meta.env.VITE_API_KEY`, or
 *   the bracket form with a quoted name).
 *
 * Secret-looking: a word of the name after VITE_ is SECRET, TOKEN, PASSWORD,
 * PASSWD, PASSPHRASE or CREDENTIAL (plural too), starts with PRIVATE, or is
 * APIKEY; KEY right after API, ACCESS, SIGNING or ENCRYPTION; or the name is
 * VITE_APP_KEY, Laravel's own key. Pusher and Reverb app keys
 * (VITE_PUSHER_APP_KEY, VITE_REVERB_APP_KEY) are public by design and pass.
 *
 * Only the name is matched: the value after the = is never parsed or
 * printed, and nothing here reads the process environment. One warning per
 * line, never a fail, and not in the deploy gate. With nothing to flag, one
 * pass when the app uses Vite (a root vite.config file, or vite in
 * package.json); otherwise no finding. The proctor reads the names on the
 * envelopes, never the letters inside.
 */
final class ViteSecretsCheck implements CheckInterface
{
    /** Env file name parts that mark a file meant to be committed. */
    private const EXAMPLE_PARTS = ['example', 'dist', 'sample', 'template'];

    /** Words that make a name look like a secret on their own. */
    private const SECRET_WORDS = [
        'SECRET', 'SECRETS', 'TOKEN', 'TOKENS', 'PASSWORD', 'PASSWORDS', 'PASSWD', 'PASSPHRASE',
        'CREDENTIAL', 'CREDENTIALS', 'APIKEY',
    ];

    /** Words that make a following KEY a secret: API_KEY, ACCESS_KEY and the like. */
    private const KEY_QUALIFIERS = ['API', 'ACCESS', 'SIGNING', 'ENCRYPTION'];

    /** The top-level folders that hold front-end code. */
    private const SOURCE_DIRS = ['resources/js', 'src'];

    /** The file extensions read as front-end code. */
    private const SOURCE_EXTENSIONS = ['js', 'mjs', 'cjs', 'jsx', 'ts', 'mts', 'cts', 'tsx', 'vue', 'svelte'];

    /** A VITE_ declaration in an env file: the name only, up to the =. */
    private const ENV_PATTERN = '/^\s*(?:export\s+)?(VITE_[A-Za-z0-9_]+)\s*=/';

    /** A read of a VITE_ variable: import.meta.env.NAME or import.meta.env['NAME']. */
    private const SOURCE_PATTERN = '/import\.meta\.env(?:\.(VITE_[A-Za-z0-9_]+)|\[\s*([\'"`])(VITE_[A-Za-z0-9_]+)\2\s*\])/';

    public function id(): string
    {
        return 'vite-secrets';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        $files = RepoFiles::list($appPath, $context->timeout);
        $committedKnown = $files->committedKnown();

        $findings = [];
        foreach ($files->files as $path) {
            $kind = $this->envKind(basename($path));
            if ($kind !== null) {
                if ($kind === 'example' || $committedKnown) {
                    foreach ($this->envFindings($appPath, $path) as $finding) {
                        $findings[] = $finding;
                    }
                }
                continue;
            }
            if ($this->isSource($path)) {
                foreach ($this->sourceFindings($appPath, $path) as $finding) {
                    $findings[] = $finding;
                }
            }
        }

        if ($findings === [] && $this->usesVite($appPath, $files->files)) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no secret-looking VITE_ variable is committed or read by the front-end code',
            )];
        }

        return $findings;
    }

    /**
     * Whether a VITE_ name looks like it holds a secret.
     */
    public static function secretLooking(string $name): bool
    {
        if (!str_starts_with($name, 'VITE_')) {
            return false;
        }
        $words = array_values(array_filter(
            explode('_', strtoupper(substr($name, strlen('VITE_')))),
            static fn(string $word): bool => $word !== '',
        ));
        if ($words === ['APP', 'KEY']) {
            return true;
        }
        foreach ($words as $index => $word) {
            if (in_array($word, self::SECRET_WORDS, true) || str_starts_with($word, 'PRIVATE')) {
                return true;
            }
            if (($word === 'KEY' || $word === 'KEYS') && $index > 0
                && in_array($words[$index - 1], self::KEY_QUALIFIERS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The kind of an env file by its name: 'example', 'other', or null when
     * the name is not an env file.
     */
    private function envKind(string $basename): ?string
    {
        if ($basename !== '.env' && !str_starts_with($basename, '.env.')) {
            return null;
        }
        $parts = array_map('strtolower', array_slice(explode('.', $basename), 2));
        foreach ($parts as $part) {
            if (in_array($part, self::EXAMPLE_PARTS, true)) {
                return 'example';
            }
        }

        return 'other';
    }

    private function isSource(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::SOURCE_EXTENSIONS, true)) {
            return false;
        }
        foreach (self::SOURCE_DIRS as $dir) {
            if (str_starts_with($path, $dir . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Finding>
     */
    private function envFindings(string $appPath, string $path): array
    {
        $lines = @file($appPath . '/' . $path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }
        $findings = [];
        foreach ($lines as $index => $line) {
            if (preg_match(self::ENV_PATTERN, (string) $line, $match) !== 1 || !self::secretLooking($match[1])) {
                continue;
            }
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf(
                    '%s in %s:%d looks like a secret, and Vite exposes every VITE_ variable to the browser bundle; keep the secret on the PHP side under a name without the VITE_ prefix, or rename the variable if its value is public by design',
                    $match[1],
                    $path,
                    $index + 1,
                ),
                $path,
                $index + 1,
                null,
                'env-file',
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function sourceFindings(string $appPath, string $path): array
    {
        $lines = @file($appPath . '/' . $path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }
        $findings = [];
        foreach ($lines as $index => $line) {
            $line = (string) $line;
            $trimmed = ltrim($line);
            // A comment line is not code; nothing it names reaches the bundle.
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '*')) {
                continue;
            }
            if (preg_match_all(self::SOURCE_PATTERN, $line, $matches, PREG_SET_ORDER) < 1) {
                continue;
            }
            $seen = [];
            foreach ($matches as $match) {
                $name = ($match[1] ?? '') !== '' ? $match[1] : ($match[3] ?? '');
                if ($name === '' || isset($seen[$name]) || !self::secretLooking($name)) {
                    continue;
                }
                $seen[$name] = true;
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_WARNING,
                    sprintf(
                        'import.meta.env.%s in %s:%d reads a secret-looking variable into the browser bundle, where every visitor can read it; call a PHP route that holds the secret instead',
                        $name,
                        $path,
                        $index + 1,
                    ),
                    $path,
                    $index + 1,
                    null,
                    'source',
                );
            }
        }

        return $findings;
    }

    /**
     * Whether the app builds with Vite: a vite.config file at the root, or
     * vite in package.json dependencies or devDependencies.
     *
     * @param list<string> $files
     */
    private function usesVite(string $appPath, array $files): bool
    {
        foreach ($files as $path) {
            if (preg_match('/^vite\.config\.(js|mjs|cjs|ts|mts|cts)$/', $path) === 1) {
                return true;
            }
        }
        $raw = @file_get_contents($appPath . '/package.json');
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }
        foreach (['dependencies', 'devDependencies'] as $section) {
            if (is_array($data[$section] ?? null) && array_key_exists('vite', $data[$section])) {
                return true;
            }
        }

        return false;
    }
}
