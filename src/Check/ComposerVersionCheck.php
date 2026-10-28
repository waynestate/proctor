<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\ToolError;

/**
 * Runs "composer --version --no-ansi" and fails when the Composer on PATH
 * carries the command-injection holes CVE-2024-35241 and CVE-2024-35242:
 * 2.2.x below 2.2.24 on the 2.2 LTS line, or any other 2.x below 2.7.7.
 * Composer 1 is end of life and fails outright. When composer cannot be run
 * or its version cannot be parsed, the check warns instead of failing, the
 * same way ComposerAuditCheck handles a missing composer.
 *
 * The parsed version is kept on the check (composerVersion) so the report
 * can show it in its tools section.
 *
 * The invigilator checks its own pen before the exam.
 */
final class ComposerVersionCheck implements CheckInterface
{
    /** The patched version on the 2.2 LTS line (CVE-2024-35241, CVE-2024-35242). */
    private const LTS_FLOOR = '2.2.24';

    /** The patched version on the mainline (CVE-2024-35241, CVE-2024-35242). */
    private const MAINLINE_FLOOR = '2.7.7';

    /**
     * The Composer version as printed by "composer --version", or null when
     * it could not be determined. Set by run().
     */
    public ?string $composerVersion = null;

    public function __construct(
        private readonly ProcessRunnerInterface $process = new ProcessRunner(),
    ) {}

    public function id(): string
    {
        return 'composer-version';
    }

    public function run(CheckContext $context): array
    {
        $composer = ComposerAuditCheck::findComposer();
        if ($composer === null) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'composer executable not found on PATH; composer version check skipped',
                null,
                1,
                null,
                'not-found',
            )];
        }

        try {
            $result = $this->process->run(
                [$composer, '--version', '--no-ansi'],
                $context->appPath,
                $context->timeout,
            );
        } catch (ToolError $e) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'composer version could not be determined: ' . $e->getMessage(),
                null,
                1,
                null,
                'could-not-determine',
            )];
        }

        $version = $this->parseVersion($result->stdout);
        if ($version === null) {
            $firstLine = $result->firstStderrLine();
            return [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'composer version could not be determined: ' . ($firstLine !== '' ? $firstLine : 'no error output'),
                null,
                1,
                null,
                'could-not-determine',
            )];
        }

        $this->composerVersion = $version;

        return [new Finding(
            $this->id(),
            $this->status($version),
            $this->message($version),
        )];
    }

    /**
     * fail for Composer 1 (end of life) and for a version below its floor,
     * pass otherwise.
     */
    private function status(string $version): string
    {
        if ($this->isComposerOne($version)) {
            return Finding::STATUS_FAIL;
        }

        return version_compare($version, $this->floor($version), '<')
            ? Finding::STATUS_FAIL
            : Finding::STATUS_PASS;
    }

    private function message(string $version): string
    {
        if ($this->isComposerOne($version)) {
            return sprintf('composer %s: Composer 1 is end of life', $version);
        }

        $floor = $this->floor($version);
        if (version_compare($version, $floor, '<')) {
            return sprintf(
                'composer %s is below the %s floor (CVE-2024-35241, CVE-2024-35242)',
                $version,
                $floor,
            );
        }

        return sprintf('composer %s is at or above the %s floor', $version, $floor);
    }

    /**
     * The floor for the version's line: 2.2.24 on the 2.2 LTS line, 2.7.7
     * on every other line.
     */
    private function floor(string $version): string
    {
        $parts = explode('.', $version);
        if ($parts[0] === '2' && ($parts[1] ?? '') === '2') {
            return self::LTS_FLOOR;
        }

        return self::MAINLINE_FLOOR;
    }

    private function isComposerOne(string $version): bool
    {
        return explode('.', $version)[0] === '1';
    }

    /**
     * The version from the first line of "composer --version" output
     * ("Composer version 2.10.3 2025-05-19 13:50:22"), or null when the
     * output carries no parseable X.Y.Z version.
     */
    private function parseVersion(string $stdout): ?string
    {
        $firstLine = trim(explode("\n", $stdout)[0]);
        if (preg_match('/^Composer version (\S+)/', $firstLine, $matches) !== 1) {
            return null;
        }

        $version = $matches[1];
        if (preg_match('/^\d+\.\d+\.\d+/', $version) !== 1) {
            return null;
        }

        return $version;
    }
}
