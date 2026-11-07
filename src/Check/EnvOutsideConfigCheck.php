<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Waynestate\Proctor\Code\PhpFiles;
use Waynestate\Proctor\Code\RepoFiles;

/**
 * Warns when a Laravel app calls env() outside its config files.
 *
 * After `php artisan config:cache` Laravel no longer loads .env, so env()
 * outside config/ returns null in production while it works on a laptop.
 * Config files read env(); everything else reads config().
 *
 * Runs only on a Laravel app: an artisan file at the root, or
 * laravel/framework in composer.json `require`. Scanned: every *.php file
 * except the top-level config/ and bootstrap/cache/ folders, and tests/,
 * vendor/, node_modules/, storage/ and .git/ at any depth. PHP is read as an
 * AST with names resolved, so env() in a comment or a string, a method named
 * env (`$app->env()`), and a function imported from another namespace under
 * the name env are not flagged. Blade templates (*.blade.php) are not PHP
 * until compiled, so a Blade line is matched with a pattern that needs a
 * quoted first argument: `{{ env('APP_NAME') }}` (Blade's own
 * `@env('local')` directive is not a call). A file that does not parse
 * is skipped here; the portability check reports it.
 *
 * Larastan enforces the same rule (larastan.noEnvCallsOutsideOfConfig), so a
 * call that carries Larastan's own ignore marker for that identifier, on the
 * line or on a comment line right above, was reviewed already: it is reported as an info note
 * (proctor/env-outside-config/suppressed), visible but not counted. A
 * closure in bootstrap/app.php that runs before config is loaded is the
 * usual reason.
 *
 * One warning per call, never a fail. The proctor reads the answer sheet,
 * not the notes the student scribbled in the margin.
 */
final class EnvOutsideConfigCheck implements CheckInterface
{
    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', 'storage', 'tests', '.git'];

    /** Larastan's identifier for the same rule; its suppression is honored. */
    private const LARASTAN_RULE = 'larastan.noEnvCallsOutsideOfConfig';

    /** Top-level folders that are config or generated config. */
    private const SKIP_PREFIXES = ['config/', 'bootstrap/cache/'];

    /**
     * An env() call with a quoted first argument: not a method, a longer
     * name, or Blade's own @env('local') directive.
     */
    private const BLADE_PATTERN = '/(?<![\w$>:\\\\@])env\s*\(\s*([\'"])([^\'"]*)\1/';

    public function id(): string
    {
        return 'env-outside-config';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        if (!$this->isLaravel($appPath)) {
            return [];
        }

        $findings = [];
        $phpFiles = new PhpFiles();
        foreach (RepoFiles::list($appPath, $context->timeout, self::SKIP_DIRS)->files as $path) {
            if (!str_ends_with($path, '.php') || $this->skippedPrefix($path)) {
                continue;
            }
            $absolute = $appPath . '/' . $path;
            if (str_ends_with($path, '.blade.php')) {
                foreach ($this->bladeCalls($absolute) as [$line, $name]) {
                    $findings[] = $this->warning($path, $line, $name);
                }
                continue;
            }
            $statements = $phpFiles->statements($absolute);
            if ($statements === null) {
                continue;
            }
            $statements = $phpFiles->resolveNames($statements);
            $lines = null;
            foreach ($phpFiles->findInstanceOf($statements, FuncCall::class) as $call) {
                if (!$call->name instanceof Name || strtolower($call->name->toString()) !== 'env') {
                    continue;
                }
                $first = $call->getArgs()[0] ?? null;
                $name = $first !== null && $first->value instanceof String_ ? $first->value->value : null;
                $lines ??= @file($absolute, FILE_IGNORE_NEW_LINES) ?: [];
                $findings[] = $this->suppressed($lines, $call->getStartLine())
                    ? $this->suppressedNote($path, $call->getStartLine(), $name)
                    : $this->warning($path, $call->getStartLine(), $name);
            }
            unset($statements, $lines);
        }

        // Suppressed calls are notes, not problems: they still earn the pass.
        $problems = array_filter(
            $findings,
            static fn(Finding $finding): bool => Finding::STATUS_INFO !== $finding->status,
        );
        if ($problems === []) {
            $findings[] = new Finding($this->id(), Finding::STATUS_PASS, 'env() is called only from config files');
        }

        return $findings;
    }

    /**
     * A Laravel app: an artisan file at the root, or laravel/framework in
     * composer.json require.
     */
    private function isLaravel(string $appPath): bool
    {
        if (is_file($appPath . '/artisan')) {
            return true;
        }
        $raw = @file_get_contents($appPath . '/composer.json');
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data)
            && is_array($data['require'] ?? null)
            && array_key_exists('laravel/framework', $data['require']);
    }

    private function skippedPrefix(string $path): bool
    {
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The env() calls in a Blade template: [line, variable name].
     *
     * @return list<array{0: int, 1: string}>
     */
    private function bladeCalls(string $absolute): array
    {
        $lines = @file($absolute, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }
        $calls = [];
        foreach ($lines as $index => $line) {
            if (preg_match_all(self::BLADE_PATTERN, (string) $line, $matches) > 0) {
                foreach ($matches[2] as $name) {
                    $calls[] = [$index + 1, $name];
                }
            }
        }

        return $calls;
    }

    /**
     * Whether the call carries Larastan's own ignore marker for this rule:
     * the identifier on the call's line, or on a comment line right above.
     *
     * @param array<int, string> $lines the file's lines
     */
    private function suppressed(array $lines, int $line): bool
    {
        $current = (string) ($lines[$line - 1] ?? '');
        if (str_contains($current, self::LARASTAN_RULE)) {
            return true;
        }
        $previous = ltrim((string) ($lines[$line - 2] ?? ''));

        return str_contains($previous, self::LARASTAN_RULE)
            && (str_starts_with($previous, '//') || str_starts_with($previous, '/*')
                || str_starts_with($previous, '*') || str_starts_with($previous, '#'));
    }

    private function suppressedNote(string $path, int $line, ?string $name): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_INFO,
            sprintf(
                '%s in %s:%d carries @phpstan-ignore %s, so it is reviewed and not counted',
                $name === null ? 'env()' : sprintf("env('%s')", $name),
                $path,
                $line,
                self::LARASTAN_RULE,
            ),
            $path,
            $line,
            null,
            'suppressed',
        );
    }

    private function warning(string $path, int $line, ?string $name): Finding
    {
        $call = $name === null ? 'env()' : sprintf("env('%s')", $name);

        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                '%s is called in %s:%d, outside config/; after php artisan config:cache it returns null. Read the variable in a config file and use config() here',
                $call,
                $path,
                $line,
            ),
            $path,
            $line,
        );
    }
}
