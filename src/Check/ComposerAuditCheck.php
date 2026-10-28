<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\Policy\PolicySource;
use Waynestate\Proctor\ToolError;

/**
 * Runs "composer audit --format=json --locked" in the app path and reports
 * the advisories and abandoned packages it finds.
 *
 * Composer exits non-zero (1, 2 or 3) when it reports findings; that is
 * expected, so the decision is made from stdout, never from the exit code
 * alone. An advisory whose CVE or advisory id is in the app's audit.ignore
 * list is a warning, not a fail. Abandoned packages are warnings. When the
 * composer executable or composer.lock is missing, the check reports one
 * info finding and nothing else.
 */
final class ComposerAuditCheck implements CheckInterface
{
    private const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public function __construct(
        private readonly ProcessRunnerInterface $process = new ProcessRunner(),
    ) {}

    public function id(): string
    {
        return 'composer-audit';
    }

    public function run(CheckContext $context): array
    {
        $composer = self::findComposer();
        if ($composer === null) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_INFO,
                'composer executable not found on PATH; composer audit skipped',
            )];
        }

        if (!is_file($context->appPath . '/composer.lock')) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_INFO,
                'no composer.lock in the app path; composer audit skipped',
            )];
        }

        $result = $this->process->run(
            [$composer, 'audit', '--format=json', '--locked'],
            $context->appPath,
            $context->timeout,
        );

        $decoded = json_decode($result->stdout, true);
        if (!is_array($decoded)) {
            $firstLine = $result->firstStderrLine();
            return [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'composer audit could not run: ' . ($firstLine !== '' ? $firstLine : 'no error output'),
                'composer.lock',
            )];
        }

        $threshold = $this->threshold($context->policy);
        $ignored = $this->ignoredAdvisories($context);

        $findings = [];

        $advisories = $decoded['advisories'] ?? [];
        if (is_array($advisories)) {
            foreach ($advisories as $package => $entries) {
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $severity = $entry['severity'] ?? null;
                    $findings[] = new Finding(
                        $this->id(),
                        $this->advisoryStatus($entry, $severity, $threshold, $ignored),
                        sprintf(
                            '%s %s (%s)',
                            (string) $package,
                            $this->advisoryId($entry),
                            is_string($severity) ? $severity : 'unknown',
                        ),
                        'composer.lock',
                    );
                }
            }
        }

        $abandoned = $decoded['abandoned'] ?? [];
        if (is_array($abandoned)) {
            foreach ($abandoned as $package => $replacement) {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_WARNING,
                    is_string($replacement) && $replacement !== ''
                        ? sprintf('%s is abandoned (use %s instead)', (string) $package, $replacement)
                        : sprintf('%s is abandoned', (string) $package),
                    'composer.lock',
                );
            }
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no advisories found',
                'composer.lock',
            )];
        }

        return $findings;
    }

    /**
     * The policy's audit_severity_threshold, validated.
     *
     * @throws ToolError if the rule is missing or not a known severity
     */
    private function threshold(PolicySource $policy): string
    {
        $threshold = $policy->rules()['audit_severity_threshold'] ?? null;
        if (!is_string($threshold) || !in_array($threshold, self::SEVERITIES, true)) {
            throw new ToolError(sprintf(
                'Policy rules.audit_severity_threshold must be one of %s; got %s.',
                implode(', ', self::SEVERITIES),
                var_export($threshold, true),
            ));
        }

        return $threshold;
    }

    /**
     * The status of an advisory: a warning when it is in the app's
     * audit.ignore list, otherwise a fail when its severity is at or above
     * the threshold and a warning below it.
     *
     * @param array<string, mixed> $entry
     * @param list<string>         $ignored
     */
    private function advisoryStatus(array $entry, mixed $severity, string $threshold, array $ignored): string
    {
        if ($this->isIgnored($entry, $ignored)) {
            return Finding::STATUS_WARNING;
        }

        return $this->isAtOrAboveThreshold($severity, $threshold)
            ? Finding::STATUS_FAIL
            : Finding::STATUS_WARNING;
    }

    /**
     * True when the advisory's CVE or advisory id is in the app's
     * audit.ignore list.
     *
     * @param array<string, mixed> $entry
     * @param list<string>         $ignored
     */
    private function isIgnored(array $entry, array $ignored): bool
    {
        if ($ignored === []) {
            return false;
        }

        $advisory = $entry['advisory'] ?? null;
        if (!is_array($advisory)) {
            return false;
        }

        foreach (['cve', 'advisory_id'] as $key) {
            $value = $advisory[$key] ?? null;
            if (is_string($value) && $value !== '' && in_array($value, $ignored, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The advisory ids the app ignores via audit.ignore in its composer.json.
     *
     * @return list<string>
     */
    private function ignoredAdvisories(CheckContext $context): array
    {
        $file = $context->appPath . '/composer.json';
        $raw = file_get_contents($file);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        $ignore = $data['audit']['ignore'] ?? [];
        if (!is_array($ignore)) {
            return [];
        }

        return array_values(array_filter(
            $ignore,
            static fn(mixed $id): bool => is_string($id) && $id !== '',
        ));
    }

    /**
     * Severity order is low < medium < high < critical. A null (or unknown)
     * severity counts as at or above the threshold: fail-safe.
     */
    private function isAtOrAboveThreshold(mixed $severity, string $threshold): bool
    {
        if (!is_string($severity) || !in_array($severity, self::SEVERITIES, true)) {
            return true;
        }

        return array_search($severity, self::SEVERITIES, true) >= array_search($threshold, self::SEVERITIES, true);
    }

    /**
     * The CVE of the advisory, or its advisory id, or "unknown".
     *
     * @param array<string, mixed> $entry
     */
    private function advisoryId(array $entry): string
    {
        $advisory = $entry['advisory'] ?? null;
        if (is_array($advisory)) {
            if (isset($advisory['cve']) && is_string($advisory['cve']) && $advisory['cve'] !== '') {
                return $advisory['cve'];
            }
            if (isset($advisory['advisory_id']) && is_string($advisory['advisory_id']) && $advisory['advisory_id'] !== '') {
                return $advisory['advisory_id'];
            }
        }

        return 'unknown';
    }

    /**
     * Finds the composer executable on PATH, or returns null.
     */
    private static function findComposer(): ?string
    {
        $pathEnv = getenv('PATH');
        if ($pathEnv === false || $pathEnv === '') {
            $pathEnv = getenv('Path');
        }
        if ($pathEnv === false || $pathEnv === '') {
            return null;
        }

        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $candidates = $isWindows
            ? ['composer.exe', 'composer.phar', 'composer.bat', 'composer.cmd', 'composer']
            : ['composer', 'composer.phar'];

        foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
            if ($dir === '') {
                continue;
            }
            foreach ($candidates as $name) {
                $full = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($full) && ($isWindows || is_executable($full))) {
                    return $full;
                }
            }
        }

        return null;
    }
}
