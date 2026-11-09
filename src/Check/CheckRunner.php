<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use DateTimeImmutable;
use InvalidArgumentException;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\ToolError;

/**
 * Validates the app under test and runs the checks in the given order.
 *
 * After all checks have run, active overrides are applied to the findings
 * (fail -> warning), except for fails whose state is eol_grace_expired.
 */
final class CheckRunner
{
    /**
     * @param list<CheckInterface> $checks     the checks to run, in output order
     * @param list<Override>       $overrides  the app's policy-overrides.json entries
     * @param int                  $timeout    maximum seconds for an external process
     *                                         (composer audit) before it is killed
     * @param string|null          $phpRuntime the PHP version that will serve the app
     *                                         (--php-runtime), or null when not given
     * @param EnvFileFilter|null   $envFiles   which env files the checks may read; null
     *                                         (the CLI) lets each check read its own
     */
    public function __construct(
        private readonly string $appPath,
        private readonly Policy $policy,
        private readonly DateTimeImmutable $today,
        private readonly array $checks,
        private readonly array $overrides = [],
        private readonly int $timeout = 300,
        private readonly ?string $phpRuntime = null,
        private readonly ?EnvFileFilter $envFiles = null,
    ) {}

    /**
     * Runs all checks, applies the overrides, and returns the findings in
     * check order.
     *
     * @return list<Finding>
     *
     * @throws ToolError if the app path is not a directory, its composer.json
     *                   (when present) is unreadable or malformed, or the
     *                   policy's override_max_days value is not a positive
     *                   integer
     */
    public function run(): array
    {
        $this->validateApp();

        try {
            $maxOverrideDays = $this->policy->overrideMaxDays();
        } catch (InvalidArgumentException $error) {
            throw new ToolError($error->getMessage());
        }

        $context = new CheckContext(
            $this->appPath,
            $this->policy,
            $this->today,
            $this->overrides,
            $this->timeout,
            $this->phpRuntime,
            $this->envFiles,
        );
        $unapplied = [];
        foreach ($this->checks as $check) {
            foreach ($check->run($context) as $finding) {
                $unapplied[] = $finding;
            }
        }

        $applier = new OverrideApplier(
            $this->overrides,
            $this->today,
            ComposerAuditCheck::composerJsonIgnoreEntries($this->appPath),
            $maxOverrideDays,
        );

        return array_merge($applier->apply($unapplied), $applier->unusedWarnings($unapplied));
    }

    /**
     * The app path must exist. A composer.json, when present, must be
     * readable and valid JSON. An app without one (raw PHP, no Composer)
     * gets a verdict instead of a tool error: the php-floor check fails and
     * unbounded-constraints passes.
     *
     * @throws ToolError
     */
    private function validateApp(): void
    {
        if (!is_dir($this->appPath)) {
            throw new ToolError(sprintf('Path "%s" does not exist or is not a directory.', $this->appPath));
        }

        $composerFile = $this->appPath . '/composer.json';
        if (!is_file($composerFile)) {
            return;
        }
        if (!is_readable($composerFile)) {
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
                json_last_error_msg(),
            ));
        }
    }
}
