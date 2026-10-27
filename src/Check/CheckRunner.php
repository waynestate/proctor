<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use DateTimeImmutable;
use Waynestate\Proctor\Policy\PolicySource;
use Waynestate\Proctor\ToolError;

/**
 * Validates the app under test and runs the checks in the given order.
 */
final class CheckRunner
{
    /**
     * @param list<CheckInterface> $checks the checks to run, in output order
     */
    public function __construct(
        private readonly string $appPath,
        private readonly PolicySource $policy,
        private readonly DateTimeImmutable $today,
        private readonly array $checks,
    ) {
    }

    /**
     * Runs all checks and returns their findings in check order.
     *
     * @return list<Finding>
     *
     * @throws ToolError if the app path or its composer.json is unusable
     */
    public function run(): array
    {
        $this->validateApp();

        $context = new CheckContext($this->appPath, $this->policy, $this->today);
        $findings = [];
        foreach ($this->checks as $check) {
            foreach ($check->run($context) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * The app path must exist and carry a readable, valid composer.json,
     * because the php-floor check needs it.
     *
     * @throws ToolError
     */
    private function validateApp(): void
    {
        if (!is_dir($this->appPath)) {
            throw new ToolError(sprintf('Path "%s" does not exist or is not a directory.', $this->appPath));
        }

        $composerFile = $this->appPath . '/composer.json';
        if (!is_file($composerFile) || !is_readable($composerFile)) {
            throw new ToolError(sprintf('No readable composer.json in "%s".', $this->appPath));
        }

        $raw = file_get_contents($composerFile);
        if ($raw === false) {
            throw new ToolError(sprintf('composer.json in "%s" could not be read.', $this->appPath));
        }

        if (!is_array(json_decode($raw, true))) {
            throw new ToolError(sprintf(
                'composer.json in "%s" is not valid JSON: %s',
                $this->appPath,
                json_last_error_msg()
            ));
        }
    }
}
