<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Waynestate\Proctor\Code\PhpFiles;
use Waynestate\Proctor\Code\RepoFiles;

/**
 * Warns when the repository gives away the app's APP_KEY, or makes a leaked
 * one worse.
 *
 * Laravel's decrypt() unserializes what it decrypts, so anyone holding the
 * APP_KEY can forge a payload the app will run. Three rules:
 *
 * - `proctor/app-key/committed-env`: a real env file is committed (`.env`,
 *   `.env.production`, `.env.local`, any `.env.*` that is not an example or a
 *   test file).
 * - `proctor/app-key/committed-key`: a committed env file, examples included,
 *   sets APP_KEY or APP_PREVIOUS_KEYS to a real key (`base64:` and 16 or 32
 *   bytes), or config/app.php carries one as a literal. The key itself is
 *   never printed.
 * - `proctor/app-key/cookie-sessions`: SESSION_DRIVER=cookie in a committed
 *   env file, or a cookie default in config/session.php: the whole session
 *   then rides in a cookie sealed with the APP_KEY.
 *
 * Only committed files count. A real env file is graded only when PROCTOR
 * knows it is committed (git lists it, or the run is in CI, where the
 * checkout holds only the repository), so the `.env` on a developer's laptop
 * never raises a flag. Example files (`.env.example`, `.dist`, `.sample`,
 * `.template`) are meant to be committed and are always graded. Test files
 * (`.env.testing`, `.env.dusk.*`, `.env.ci`, `.env.test`) hold throwaway keys
 * and are skipped.
 *
 * Warnings, never a fail: a new check must not turn a passing app into a
 * failing one. The proctor does not read the answer key aloud; it only
 * notes that it was left on the desk.
 */
final class AppKeyCheck implements CheckInterface
{
    /** Env file name parts that mark a file meant to be committed. */
    private const EXAMPLE_PARTS = ['example', 'dist', 'sample', 'template'];

    /** Env file name parts that mark a test file with a throwaway key. */
    private const TEST_PARTS = ['testing', 'test', 'ci', 'dusk'];

    /** The variables that hold a key. */
    private const KEY_VARIABLES = ['APP_KEY', 'APP_PREVIOUS_KEYS'];

    public function id(): string
    {
        return 'app-key';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        $files = RepoFiles::list($appPath, $context->timeout);
        $committedKnown = $files->committedKnown();

        $findings = [];
        $examined = false;
        foreach ($files->files as $path) {
            $kind = $this->envKind(basename($path));
            if ($kind === null || $kind === 'test') {
                continue;
            }
            if ($kind === 'real') {
                if (!$committedKnown) {
                    continue;
                }
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_WARNING,
                    sprintf(
                        '%s is committed; an env file holds the real secrets, the APP_KEY among them. Run git rm --cached %s, add it to .gitignore, commit a .env.example with placeholders, and rotate every secret it held',
                        $path,
                        $path,
                    ),
                    $path,
                    1,
                    null,
                    'committed-env',
                );
            }
            $examined = true;
            foreach ($this->envFindings($appPath, $path) as $finding) {
                $findings[] = $finding;
            }
        }

        foreach (['config/app.php', 'config/session.php'] as $config) {
            if (!in_array($config, $files->files, true)) {
                continue;
            }
            $examined = true;
            foreach ($this->configFindings($appPath, $config) as $finding) {
                $findings[] = $finding;
            }
        }

        if ($findings === [] && $examined) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no committed env file, APP_KEY or cookie session driver found',
            )];
        }

        return $findings;
    }

    /**
     * The kind of an env file by its name: 'real', 'example', 'test', or
     * null when the name is not an env file.
     */
    private function envKind(string $basename): ?string
    {
        if ($basename !== '.env' && !str_starts_with($basename, '.env.')) {
            return null;
        }
        $parts = array_map('strtolower', array_slice(explode('.', $basename), 2));
        foreach ($parts as $part) {
            if (in_array($part, self::TEST_PARTS, true)) {
                return 'test';
            }
        }
        foreach ($parts as $part) {
            if (in_array($part, self::EXAMPLE_PARTS, true)) {
                return 'example';
            }
        }

        return 'real';
    }

    /**
     * The committed-key and cookie-sessions findings for one env file.
     *
     * @return list<Finding>
     */
    private function envFindings(string $appPath, string $path): array
    {
        $lines = @file($appPath . '/' . $path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }
        $findings = [];
        foreach ($lines as $index => $raw) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', (string) $raw, $m) !== 1) {
                continue;
            }
            $variable = $m[1];
            $value = $this->envValue($m[2]);
            if (in_array($variable, self::KEY_VARIABLES, true)) {
                foreach (explode(',', $value) as $candidate) {
                    if ($this->isRealKey(trim($candidate))) {
                        $findings[] = $this->keyFinding($path, $index + 1, $variable);
                        break;
                    }
                }
            } elseif ($variable === 'SESSION_DRIVER' && strtolower($value) === 'cookie') {
                $findings[] = $this->cookieFinding($path, $index + 1, 'SESSION_DRIVER=cookie');
            }
        }

        return $findings;
    }

    /**
     * The value of an env line without surrounding quotes or a trailing
     * comment.
     */
    private function envValue(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $end = strpos($value, $value[0], 1);

            return $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
        }

        return trim((string) preg_replace('/\s+#.*$/', '', $value));
    }

    /**
     * Whether a value is a real Laravel key: base64: and 16 or 32 bytes.
     */
    private function isRealKey(string $value): bool
    {
        if (preg_match('/^base64:([A-Za-z0-9+\/]+={0,2})$/', $value, $m) !== 1) {
            return false;
        }
        $bytes = base64_decode($m[1], true);

        return $bytes !== false && in_array(strlen($bytes), [16, 32], true);
    }

    /**
     * The findings for config/app.php (a literal key) or config/session.php
     * (a cookie driver default).
     *
     * @return list<Finding>
     */
    private function configFindings(string $appPath, string $path): array
    {
        $phpFiles = new PhpFiles();
        $statements = $phpFiles->statements($appPath . '/' . $path);
        if ($statements === null) {
            return [];
        }

        $findings = [];
        if ($path === 'config/app.php') {
            foreach ($phpFiles->findInstanceOf($statements, String_::class) as $string) {
                if ($this->isRealKey($string->value)) {
                    $findings[] = $this->keyFinding($path, $string->getStartLine(), 'a literal key');
                }
            }

            return $findings;
        }

        foreach ($phpFiles->findInstanceOf($statements, ArrayItem::class) as $item) {
            if (!$item->key instanceof String_ || strtolower($item->key->value) !== 'driver') {
                continue;
            }
            $value = $item->value;
            if ($value instanceof FuncCall && $value->name instanceof Name
                && strtolower($value->name->toString()) === 'env') {
                $default = $value->getArgs()[1] ?? null;
                $value = $default?->value;
            }
            if ($value instanceof String_ && strtolower($value->value) === 'cookie') {
                $findings[] = $this->cookieFinding($path, $item->getStartLine(), "the session driver defaults to 'cookie'");
            }
        }

        return $findings;
    }

    private function keyFinding(string $path, int $line, string $what): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                '%s:%d commits a real APP_KEY (%s). Whoever holds the key can forge encrypted payloads the app will unserialize, which can mean code execution. Remove it and rotate the key (php artisan key:generate) on every environment that used it',
                $path,
                $line,
                $what,
            ),
            $path,
            $line,
            null,
            'committed-key',
        );
    }

    private function cookieFinding(string $path, int $line, string $what): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                '%s:%d: %s keeps each session in a cookie sealed with the APP_KEY, so a leaked key opens every session; use the database or redis driver',
                $path,
                $line,
                $what,
            ),
            $path,
            $line,
            null,
            'cookie-sessions',
        );
    }
}
