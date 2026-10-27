<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\ToolError;

/**
 * Checks that every runtime package constraint in composer.json is bounded.
 *
 * A constraint is unbounded when it is "*", starts with "dev-", or contains
 * a dev- branch name (e.g. "dev-master as 1.0", "dev-master || ^2.0").
 * require-dev is not checked; php, ext-*, lib-*, composer-plugin-api and
 * composer-runtime-api are not packages.
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

            if ($this->isUnbounded($constraint)) {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_FAIL,
                    sprintf('%s constraint "%s" is unbounded', $package, $constraint),
                    'composer.json',
                    $this->lineOfPackage($raw, $package)
                );
            }
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'all require constraints are bounded',
                'composer.json'
            )];
        }

        return $findings;
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
     * True when the constraint is "*" or names a dev- branch.
     */
    private function isUnbounded(string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '*') {
            return true;
        }

        foreach ($this->tokens($constraint) as $token) {
            if (str_starts_with($token, 'dev-')) {
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
            static fn (string $part): bool => $part !== ''
        ));
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
