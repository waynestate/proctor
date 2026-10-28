<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\ToolError;

/**
 * Checks that every runtime package constraint in composer.json is bounded.
 *
 * A constraint is unbounded when one of its tokens matches a banned pattern
 * from the policy's rules.banned_constraints, or when it is "*", names a dev-
 * branch, carries an @dev flag, or has a lower bound with no upper bound.
 * require-dev is not checked; php, ext-*, lib-*, composer-plugin-api and
 * composer-runtime-api are not packages.
 *
 * A path or vcs repository in composer.json is a warning, not a failure.
 */
final class UnboundedConstraintsCheck implements CheckInterface
{
    public function id(): string
    {
        return 'unbounded-constraints';
    }

    public function run(CheckContext $context): array
    {
        $file = $context->appPath . '/composer.json';
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new ToolError(sprintf('composer.json in "%s" could not be read.', $context->appPath));
        }

        // The CheckRunner has already validated the file, so this only fails
        // if the file changed between the two reads.
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ToolError(sprintf('composer.json in "%s" is not valid JSON.', $context->appPath));
        }

        $banned = $this->bannedPatterns($context);

        $require = $data['require'] ?? [];
        if (!is_array($require)) {
            $require = [];
        }

        $findings = [];
        foreach ($require as $package => $constraint) {
            $package = (string) $package;
            if ($this->isNotAPackage($package) || !is_string($constraint)) {
                continue;
            }

            if ($this->isUnbounded($constraint, $banned)) {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_FAIL,
                    sprintf('%s constraint "%s" is unbounded', $package, $constraint),
                    'composer.json',
                    $this->lineOfPackage($raw, $package),
                    null,
                    'unbounded',
                );
            }
        }

        foreach ($this->repositoryWarnings($data) as $warning) {
            $findings[] = $warning;
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'all require constraints are bounded',
                'composer.json',
            )];
        }

        return $findings;
    }

    /**
     * The policy's rules.banned_constraints, as a list of glob patterns.
     *
     * @return list<string>
     */
    private function bannedPatterns(CheckContext $context): array
    {
        $banned = $context->policy->rules()['banned_constraints'] ?? [];
        if (!is_array($banned)) {
            return [];
        }

        return array_values(array_filter(
            $banned,
            static fn(mixed $pattern): bool => is_string($pattern) && $pattern !== '',
        ));
    }

    /**
     * The entries of require that are not third-party packages.
     */
    private function isNotAPackage(string $package): bool
    {
        return $package === 'php'
            || str_starts_with($package, 'ext-')
            || str_starts_with($package, 'lib-')
            || $package === 'composer-plugin-api'
            || $package === 'composer-runtime-api';
    }

    /**
     * True when the constraint is unbounded.
     *
     * @param list<string> $banned banned patterns from the policy
     */
    private function isUnbounded(string $constraint, array $banned): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '') {
            return false;
        }

        foreach ($this->tokens($constraint) as $token) {
            if ($token === '*'
                || str_starts_with($token, 'dev-')
                || str_contains($token, '@dev')
            ) {
                return true;
            }
            foreach ($banned as $pattern) {
                if ($this->matchesBannedPattern($token, $pattern)) {
                    return true;
                }
            }
        }

        return $this->hasOpenUpperBound($constraint);
    }

    /**
     * True when $token matches the glob $pattern. The pattern "*" matches
     * only the exact token "*"; any other "*" in the pattern is a wildcard.
     */
    private function matchesBannedPattern(string $token, string $pattern): bool
    {
        if ($pattern === '*') {
            return $token === '*';
        }

        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';

        return preg_match($regex, $token) === 1;
    }

    /**
     * True when at least one "||" group of the constraint has a lower bound
     * but no upper bound, so the constraint allows arbitrarily new versions.
     */
    private function hasOpenUpperBound(string $constraint): bool
    {
        foreach (explode('||', $constraint) as $group) {
            $hasLower = false;
            $hasUpper = false;
            foreach ($this->tokens($group) as $token) {
                if (str_starts_with($token, '>')) {
                    $hasLower = true;
                }
                if (str_starts_with($token, '<')
                    || str_starts_with($token, '^')
                    || str_starts_with($token, '~')
                    || str_contains($token, '*')
                ) {
                    $hasUpper = true;
                }
            }
            if ($hasLower && !$hasUpper) {
                return true;
            }
        }

        return false;
    }

    /**
     * Splits a constraint into its version tokens, dropping the operators
     * (whitespace, "||", "&&") and words like "as".
     *
     * @return list<string>
     */
    private function tokens(string $constraint): array
    {
        $parts = preg_split('/\s+|\|\||&&/', $constraint) ?: [];

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn(string $part): bool => $part !== '',
        ));
    }

    /**
     * One warning finding per path or vcs repository in composer.json.
     *
     * @param array<string, mixed> $data
     *
     * @return list<Finding>
     */
    private function repositoryWarnings(array $data): array
    {
        $repositories = $data['repositories'] ?? [];
        if (!is_array($repositories)) {
            return [];
        }

        $findings = [];
        foreach ($repositories as $repository) {
            if (!is_array($repository)) {
                continue;
            }
            $type = $repository['type'] ?? null;
            if ($type === 'path' || $type === 'vcs') {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_WARNING,
                    sprintf('repository of type "%s" is not a stable source', $type),
                    'composer.json',
                    1,
                    null,
                    'repository',
                );
            }
        }

        return $findings;
    }

    /**
     * The 1-based line of the package's key in the raw composer.json text,
     * or 1 when the key cannot be found.
     */
    private function lineOfPackage(string $raw, string $package): int
    {
        $pattern = '/"' . preg_quote($package, '/') . '"\s*:/';
        if (preg_match($pattern, $raw, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return 1;
        }

        return substr_count($raw, "\n", 0, $matches[0][1]) + 1;
    }
}
