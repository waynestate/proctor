<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\ToolError;

/**
 * Checks that development-only packages sit in require-dev, not require.
 *
 * A package in require installs in production. Some packages are only meant
 * for local work: debugging tools that expose app internals (Laravel Boost,
 * Debugbar and Telescope) and test tooling (PHPUnit, Pest, Mockery, Sail,
 * Pail and Collision). Shipping them in production leaks internals over HTTP
 * or drags a test harness into a live release, so they belong in require-dev.
 */
final class DevOnlyPackagesCheck implements CheckInterface
{
    /**
     * Packages that expose app internals when installed in production, by
     * package name and the status their presence in require earns.
     *
     * @var array<string, string>
     */
    private const EXPOSES_INTERNALS = [
        'laravel/boost' => Finding::STATUS_FAIL,
        'barryvdh/laravel-debugbar' => Finding::STATUS_FAIL,
        'fruitcake/laravel-debugbar' => Finding::STATUS_FAIL,
        'laravel/telescope' => Finding::STATUS_WARNING,
    ];

    /**
     * Test tooling that bloats a production install and is never needed there.
     *
     * @var list<string>
     */
    private const TEST_TOOLS = [
        'phpunit/phpunit',
        'pestphp/pest',
        'mockery/mockery',
        'laravel/sail',
        'laravel/pail',
        'nunomaduro/collision',
    ];

    public function id(): string
    {
        return 'dev-only-packages';
    }

    public function run(CheckContext $context): array
    {
        $file = $context->appPath . '/composer.json';
        if (!is_file($file)) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no composer.json, so no development packages to check',
                'composer.json',
            )];
        }

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
        foreach (array_keys($require) as $package) {
            $finding = $this->findingFor($raw, (string) $package);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no development tool is in require',
                'composer.json',
            )];
        }

        return $findings;
    }

    /**
     * One finding when $package is a development-only package found in
     * require, or null when it is not on either list.
     */
    private function findingFor(string $raw, string $package): ?Finding
    {
        if (array_key_exists($package, self::EXPOSES_INTERNALS)) {
            $status = self::EXPOSES_INTERNALS[$package];
            $rule = 'exposes-internals';
        } elseif (in_array($package, self::TEST_TOOLS, true)) {
            $status = Finding::STATUS_WARNING;
            $rule = 'test-tool';
        } else {
            return null;
        }

        $message = sprintf(
            '%s is in require, so it installs in production; move it to require-dev (composer remove %s && composer require --dev %s)',
            $package,
            $package,
            $package,
        );
        if ($package === 'laravel/telescope') {
            $message .= ", or keep it in require only if the app registers it for local use as Laravel's Telescope docs describe";
        }

        return new Finding(
            $this->id(),
            $status,
            $message,
            'composer.json',
            $this->lineOfKey($raw, $package),
            null,
            $rule,
        );
    }

    /**
     * The 1-based line of the key in the raw composer.json text, or 1 when
     * the key cannot be found.
     */
    private function lineOfKey(string $raw, string $key): int
    {
        $pattern = '/"' . preg_quote($key, '/') . '"\s*:/';
        if (preg_match($pattern, $raw, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return 1;
        }

        return substr_count($raw, "\n", 0, $matches[0][1]) + 1;
    }
}
