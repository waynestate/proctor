<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Composer\Semver\Semver;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\ToolError;

/**
 * Runs "composer audit --format=json --locked --no-plugins --no-interaction"
 * in the app path, with a minimal environment of its own, and reports the
 * advisories and abandoned packages it finds.
 *
 * Composer exits non-zero (1, 2 or 3) when it reports findings; that is
 * expected, so the decision is made from stdout, never from the exit code
 * alone. Each advisory finding names the package, the locked version, the
 * advisory's CVE (or advisory id), its title and its severity. An advisory
 * on a package that composer.lock lists only in packages-dev is a warning
 * at any severity, with the message ending in " [dev dependency]"; a name
 * in both lists counts as runtime, and a name the lock does not list at all
 * is graded as runtime too. An advisory the app ignores in its
 * composer.json (config.policy.advisories.ignore-id,
 * config.policy.advisories.ignore or the legacy config.audit.ignore) is a
 * warning, not a fail, and so is anything composer has already moved to its
 * ignored-advisories section. A detailed ignore rule only counts when it
 * applies to audit (its on-audit flag is not false) and, for a package rule
 * carrying a constraint, when the locked version satisfies it. Abandoned
 * packages are warnings. When the composer executable or composer.lock is
 * missing, the check fails (rule `proctor/composer-audit/skipped`) with a
 * message that starts with `not audited:` and names the fix, installing
 * Composer or committing the lock, or recording a dated override, because a
 * green run that audited nothing is worse than a red one. When the audit
 * runs but its stdout is not parseable JSON, the check fails (rule
 * `proctor/composer-audit/could-not-run`) with a message that starts with
 * `not audited:` and carries the first stderr line. Since Composer 2.10 the
 * audit exits 1 when anything failed it (malware, a custom policy,
 * abandoned packages, missing packages), so a non-zero exit
 * with no advisory, ignored-advisory or abandoned finding in the JSON is
 * itself a fail (rule `proctor/composer-audit/exit-code`), and only an
 * exit code of 0 with an empty result passes. A warning (rule
 * `proctor/composer-audit/ignore-entries`) also reports the number of
 * ignore entries the app keeps in its own composer.json, so they move to
 * the dated override file. Each entry that no active composer-audit
 * override backs is a fail of its own (rule
 * `proctor/composer-audit/unbacked-ignore`), because an ignore without a
 * dated approval never expires.
 */
final class ComposerAuditCheck implements CheckInterface
{
    public const ID = 'composer-audit';

    private const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public function __construct(
        private readonly ProcessRunnerInterface $process = new ProcessRunner(),
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function run(CheckContext $context): array
    {
        $composer = self::findComposer();
        if ($composer === null) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                'not audited: composer executable not found on PATH; install Composer or record an override',
                null,
                1,
                null,
                'skipped',
            )];
        }

        if (!is_file($context->appPath . '/composer.lock')) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                'not audited: no composer.lock in the app path; commit the lock or record an override',
                null,
                1,
                null,
                'skipped',
            )];
        }

        $result = $this->process->run(
            [$composer, 'audit', '--format=json', '--locked', '--no-plugins', '--no-interaction'],
            $context->appPath,
            $context->timeout,
            self::auditEnvironment(),
        );

        $decoded = json_decode($result->stdout, true);
        if (!is_array($decoded)) {
            $firstLine = $result->firstStderrLine();
            return [new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                'not audited: composer audit could not run: ' . ($firstLine !== '' ? $firstLine : 'no error output'),
                'composer.lock',
                1,
                null,
                'could-not-run',
            )];
        }

        $threshold = $this->threshold($context->policy);
        $ignored = $this->ignoredAdvisories($context);
        $ignoreEntry = $this->ignoreEntryFinding($context);
        $lockInfo = $this->lockInfo($context);
        $versions = $lockInfo['versions'];
        $devOnly = $lockInfo['devOnly'];

        $findings = [];

        // The ignored-advisories section holds advisories composer has
        // already suppressed through the same config; they stay visible as
        // warnings instead of vanishing from the report.
        foreach (['advisories' => false, 'ignored-advisories' => true] as $section => $alreadyIgnored) {
            $packages = $decoded[$section] ?? [];
            if (!is_array($packages)) {
                continue;
            }
            foreach ($packages as $package => $entries) {
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $severity = $entry['severity'] ?? null;
                    $packageName = (string) $package;
                    $isDevOnly = isset($devOnly[$packageName]);
                    $findings[] = new Finding(
                        $this->id(),
                        $this->advisoryStatus(
                            $packageName,
                            $versions[$packageName] ?? null,
                            $entry,
                            $severity,
                            $threshold,
                            $ignored,
                            $alreadyIgnored,
                            $isDevOnly,
                        ),
                        $this->advisoryMessage($packageName, $versions[$packageName] ?? null, $entry, $severity, $isDevOnly),
                        'composer.lock',
                        1,
                        null,
                        'advisory',
                        [$this->advisoryData($packageName, $entry)],
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
                    1,
                    null,
                    'abandoned',
                );
            }
        }

        if ($findings === []) {
            if ($result->exitCode !== 0) {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_FAIL,
                    sprintf(
                        'composer audit exited %d but listed nothing PROCTOR can read (a malware or policy block, or an error); run composer audit yourself',
                        $result->exitCode,
                    ),
                    'composer.lock',
                    1,
                    null,
                    'exit-code',
                );
            } else {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_PASS,
                    'no advisories found',
                    'composer.lock',
                );
            }
        }

        if ($ignoreEntry !== null) {
            $findings[] = $ignoreEntry;
        }

        foreach ($this->unbackedIgnoreFindings($context) as $finding) {
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * The policy's audit_severity_threshold, validated.
     *
     * @throws ToolError if the rule is missing or not a known severity
     */
    private function threshold(Policy $policy): string
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
     * The status of an advisory: a warning when the package is dev-only,
     * when the app ignores it, or when composer has already moved it to the
     * ignored section, otherwise a fail when its severity is at or above
     * the threshold and a warning below it.
     *
     * @param array<string, mixed> $entry
     * @param array{ids: list<string>, packages: list<string>, constrained: list<array{package: string, constraint: string}>} $ignored
     */
    private function advisoryStatus(
        string $package,
        ?string $version,
        array $entry,
        mixed $severity,
        string $threshold,
        array $ignored,
        bool $alreadyIgnored,
        bool $devOnly,
    ): string {
        if ($devOnly || $alreadyIgnored || $this->isIgnored($package, $version, $entry, $ignored)) {
            return Finding::STATUS_WARNING;
        }

        return $this->isAtOrAboveThreshold($severity, $threshold)
            ? Finding::STATUS_FAIL
            : Finding::STATUS_WARNING;
    }

    /**
     * True when the app's composer.json ignores the advisory's package or
     * any of its identifiers. A constrained package entry only counts when
     * the locked version satisfies the constraint.
     *
     * @param array<string, mixed> $entry
     * @param array{ids: list<string>, packages: list<string>, constrained: list<array{package: string, constraint: string}>} $ignored
     */
    private function isIgnored(string $package, ?string $version, array $entry, array $ignored): bool
    {
        foreach ($ignored['packages'] as $pattern) {
            if ($this->packageMatches($pattern, $package)) {
                return true;
            }
        }

        foreach ($ignored['constrained'] as $candidate) {
            if ($this->packageMatches($candidate['package'], $package) && $this->constraintMatches($version, $candidate['constraint'])) {
                return true;
            }
        }

        foreach ($this->advisoryIdentifiers($entry) as $identifier) {
            if (in_array($identifier, $ignored['ids'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a package ignore pattern from composer's config matches the
     * package, matched with the same rule a scoped override uses (see
     * Override::packagePatternMatches).
     */
    private function packageMatches(string $pattern, string $package): bool
    {
        return Override::packagePatternMatches($pattern, $package);
    }

    /**
     * Every identifier the app can use to ignore the advisory: its CVE,
     * its advisory id and the remote ids of its sources.
     *
     * @param array<string, mixed> $entry
     *
     * @return list<string>
     */
    private function advisoryIdentifiers(array $entry): array
    {
        $identifiers = [];

        foreach (['cve', 'advisoryId'] as $key) {
            $value = $entry[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $identifiers[] = $value;
            }
        }

        $sources = $entry['sources'] ?? [];
        if (is_array($sources)) {
            foreach ($sources as $source) {
                if (!is_array($source)) {
                    continue;
                }
                $remoteId = $source['remoteId'] ?? null;
                if (is_string($remoteId) && $remoteId !== '') {
                    $identifiers[] = $remoteId;
                }
            }
        }

        return array_values(array_unique($identifiers));
    }

    /**
     * The identifier the finding shows: the CVE when there is one, then the
     * advisory id, then a GHSA source id, then "unknown".
     *
     * @param array<string, mixed> $entry
     */
    private function advisoryId(array $entry): string
    {
        foreach (['cve', 'advisoryId'] as $key) {
            $value = $entry[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $sources = $entry['sources'] ?? [];
        if (is_array($sources)) {
            foreach ($sources as $source) {
                if (!is_array($source)) {
                    continue;
                }
                $remoteId = $source['remoteId'] ?? null;
                if (is_string($remoteId) && str_starts_with($remoteId, 'GHSA-')) {
                    return $remoteId;
                }
            }
        }

        return 'unknown';
    }

    /**
     * The finding's text: "<package> <version>: <id> <title> (<severity>)",
     * naming the locked version when composer.lock records one. A dev-only
     * advisory ends with " [dev dependency]" after the severity in
     * parentheses.
     *
     * @param array<string, mixed> $entry
     */
    private function advisoryMessage(string $package, ?string $version, array $entry, mixed $severity, bool $devOnly = false): string
    {
        $head = $version !== null && $version !== ''
            ? $package . ' ' . ltrim($version, 'v')
            : $package;

        $title = $entry['title'] ?? null;

        $suffix = $devOnly ? ' [dev dependency]' : '';

        return sprintf(
            '%s: %s %s (%s)%s',
            $head,
            $this->advisoryId($entry),
            is_string($title) && $title !== '' ? $title : 'no title',
            is_string($severity) && $severity !== '' ? $severity : 'unknown',
            $suffix,
        );
    }

    /**
     * The machine-readable form of the advisory for the JSON report, with
     * the audited package name so a scoped override can match it.
     *
     * @param array<string, mixed> $entry
     *
     * @return array{package: string, id: string, cve: string|null, title: string, link: string|null, severity: string|null}
     */
    private function advisoryData(string $package, array $entry): array
    {
        $cve = $entry['cve'] ?? null;
        $title = $entry['title'] ?? null;
        $link = $entry['link'] ?? null;
        $severity = $entry['severity'] ?? null;

        return [
            'package' => $package,
            'id' => $this->advisoryId($entry),
            'cve' => is_string($cve) && $cve !== '' ? $cve : null,
            'title' => is_string($title) ? $title : '',
            'link' => is_string($link) ? $link : null,
            'severity' => is_string($severity) ? $severity : null,
        ];
    }

    /**
     * What composer.lock records: the pinned versions keyed by package name
     * and the set of names that appear only in packages-dev. A name in both
     * lists counts as runtime, so it stays out of the dev-only set. The lock
     * is read once here and not again per advisory.
     *
     * @return array{versions: array<string, string>, devOnly: array<string, true>}
     */
    private function lockInfo(CheckContext $context): array
    {
        $file = $context->appPath . '/composer.lock';
        $raw = file_get_contents($file);
        if ($raw === false) {
            return ['versions' => [], 'devOnly' => []];
        }

        $lock = json_decode($raw, true);
        if (!is_array($lock)) {
            return ['versions' => [], 'devOnly' => []];
        }

        $versions = [];
        $runtimeNames = [];
        $devNames = [];

        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $lock[$section] ?? [];
            if (!is_array($packages)) {
                continue;
            }
            $isDev = $section === 'packages-dev';
            foreach ($packages as $package) {
                if (!is_array($package)) {
                    continue;
                }
                $name = $package['name'] ?? null;
                $version = $package['version'] ?? null;
                if (is_string($name) && $name !== '' && is_string($version) && $version !== '') {
                    $versions[$name] = $version;
                }
                if (is_string($name) && $name !== '') {
                    if ($isDev) {
                        $devNames[$name] = true;
                    } else {
                        $runtimeNames[$name] = true;
                    }
                }
            }
        }

        $devOnly = [];
        foreach ($devNames as $name => $present) {
            if (!isset($runtimeNames[$name])) {
                $devOnly[$name] = true;
            }
        }

        return ['versions' => $versions, 'devOnly' => $devOnly];
    }

    /**
     * What the app's composer.json tells composer audit to ignore, split
     * into advisory ids, plain package names and constrained package
     * entries.
     *
     * Composer 2.6 and later read config.policy.advisories (ignore-id for
     * advisory ids, ignore for package names); older releases read the
     * legacy config.audit.ignore, whose entries are ids or
     * "vendor/package" strings. Both shapes are honoured here so a
     * finding's status matches what composer itself suppresses.
     *
     * @return array{ids: list<string>, packages: list<string>, constrained: list<array{package: string, constraint: string}>}
     */
    private function ignoredAdvisories(CheckContext $context): array
    {
        $file = $context->appPath . '/composer.json';
        $raw = file_get_contents($file);
        if ($raw === false) {
            return ['ids' => [], 'packages' => [], 'constrained' => []];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ids' => [], 'packages' => [], 'constrained' => []];
        }

        $config = $data['config'] ?? null;
        if (!is_array($config)) {
            return ['ids' => [], 'packages' => [], 'constrained' => []];
        }

        $policy = $config['policy']['advisories'] ?? null;
        if (is_array($policy)) {
            $packages = $this->policyIgnorePackages($policy['ignore'] ?? null);

            return [
                'ids' => $this->policyIgnoreIds($policy['ignore-id'] ?? null),
                'packages' => $packages['packages'],
                'constrained' => $packages['constrained'],
            ];
        }

        $ids = [];
        $packages = [];
        foreach (self::ignoreEntries($config['audit']['ignore'] ?? null) as $entry) {
            if (str_contains($entry, '/')) {
                $packages[] = $entry;
            } else {
                $ids[] = $entry;
            }
        }

        return ['ids' => $ids, 'packages' => $packages, 'constrained' => []];
    }

    /**
     * The structured ignore entries of the app's composer.json, with the
     * same sources and gating as the ignore-entries count: one entry per
     * distinct named element, carrying the advisory id, the package, or
     * neither (an ignore-severity level), plus the label the report shows.
     *
     * config.policy.advisories.ignore-id names advisory ids,
     * config.policy.advisories.ignore and config.policy.abandoned.ignore
     * name packages, and config.policy.advisories.ignore-severity names
     * severity levels. The legacy fallbacks apply while their matching
     * config.policy block is absent: config.audit.ignore (ids or
     * "vendor/package" strings, split on the slash the way composer does),
     * config.audit.ignore-severity and config.audit.ignore-abandoned.
     *
     * @return list<array{id: string|null, package: string|null, label: string}>
     */
    public static function composerJsonIgnoreEntries(string $appPath): array
    {
        $composerJson = $appPath . '/composer.json';
        if (!is_file($composerJson)) {
            return [];
        }

        $raw = file_get_contents($composerJson);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        $config = $data['config'] ?? null;
        if (!is_array($config)) {
            return [];
        }

        $entries = [];

        $advisories = $config['policy']['advisories'] ?? null;
        $abandoned = $config['policy']['abandoned'] ?? null;
        if (is_array($advisories)) {
            foreach (self::ignoreEntries($advisories['ignore-id'] ?? null) as $name) {
                $entries[] = ['id' => $name, 'package' => null, 'label' => $name];
            }
            foreach (self::ignoreEntries($advisories['ignore'] ?? null) as $name) {
                $entries[] = ['id' => null, 'package' => $name, 'label' => $name];
            }
            foreach (self::ignoreEntries($advisories['ignore-severity'] ?? null) as $name) {
                $entries[] = ['id' => null, 'package' => null, 'label' => $name];
            }
        }

        if (is_array($abandoned)) {
            foreach (self::ignoreEntries($abandoned['ignore'] ?? null) as $name) {
                $entries[] = ['id' => null, 'package' => $name, 'label' => $name];
            }
        }

        $audit = $config['audit'] ?? null;
        if (is_array($audit)) {
            // Composer ignores all audit.* keys of a family once the
            // matching config.policy block exists, so each fallback only
            // applies while its policy block is absent.
            foreach (self::ignoreEntries($audit['ignore'] ?? null) as $name) {
                if (str_contains($name, '/')) {
                    $entries[] = ['id' => null, 'package' => $name, 'label' => $name];
                } else {
                    $entries[] = ['id' => $name, 'package' => null, 'label' => $name];
                }
            }
            if (!is_array($advisories)) {
                foreach (self::ignoreEntries($audit['ignore-severity'] ?? null) as $name) {
                    $entries[] = ['id' => null, 'package' => null, 'label' => $name];
                }
            }
            if (!is_array($abandoned)) {
                foreach (self::ignoreEntries($audit['ignore-abandoned'] ?? null) as $name) {
                    $entries[] = ['id' => null, 'package' => $name, 'label' => $name];
                }
            }
        }

        return $entries;
    }

    /**
     * A warning for the ignore entries the app keeps in its own
     * composer.json: config.policy.advisories.ignore-id,
     * config.policy.advisories.ignore, config.policy.advisories.ignore-severity,
     * config.policy.abandoned.ignore, or the legacy fallbacks
     * config.audit.ignore, config.audit.ignore-severity and
     * config.audit.ignore-abandoned. Composer ignores all audit.* keys of a
     * family once the matching config.policy block exists, so the
     * config.audit.ignore-severity fallback only counts when
     * config.policy.advisories is not an array, and
     * config.audit.ignore-abandoned only counts when
     * config.policy.abandoned is not an array. The entries have no expiry
     * and are invisible to the override file. The message counts the
     * entries and names none of them. Null when the file is unreadable,
     * not an array, or carries no ignore entries.
     */
    private function ignoreEntryFinding(CheckContext $context): ?Finding
    {
        $file = $context->appPath . '/composer.json';
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $config = $data['config'] ?? null;
        if (!is_array($config)) {
            return null;
        }

        $total = 0;

        $advisories = $config['policy']['advisories'] ?? null;
        $abandoned = $config['policy']['abandoned'] ?? null;
        if (is_array($advisories)) {
            foreach (['ignore-id', 'ignore', 'ignore-severity'] as $key) {
                $setting = $advisories[$key] ?? null;
                if (is_array($setting)) {
                    $total += count($setting);
                }
            }
        }

        if (is_array($abandoned)) {
            $setting = $abandoned['ignore'] ?? null;
            if (is_array($setting)) {
                $total += count($setting);
            }
        }

        $audit = $config['audit'] ?? null;
        if (is_array($audit)) {
            // Composer ignores all audit.* keys of a family once the
            // matching config.policy block exists, so each fallback only
            // counts while its policy block is absent.
            $fallbacks = ['ignore'];
            if (!is_array($advisories)) {
                $fallbacks[] = 'ignore-severity';
            }
            if (!is_array($abandoned)) {
                $fallbacks[] = 'ignore-abandoned';
            }
            foreach ($fallbacks as $key) {
                $setting = $audit[$key] ?? null;
                if (is_array($setting)) {
                    $total += count($setting);
                }
            }
        }

        if ($total === 0) {
            return null;
        }

        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                'composer.json silences audit results with %d ignore %s; move them to policy-overrides.json so they expire',
                $total,
                $total === 1 ? 'entry' : 'entries',
            ),
            'composer.json',
            1,
            null,
            'ignore-entries',
        );
    }

    /**
     * One fail per ignore entry no active composer-audit override backs
     * (rule `proctor/composer-audit/unbacked-ignore`). An entry is backed
     * by an override whose scope names it: the advisory field equals the
     * entry's advisory id case-insensitively, the package field matches the
     * entry's package, or the override carries no scope at all. An entry
     * that names neither (an ignore-severity level) is backed by an
     * unscoped override only, and an expired override backs nothing. The
     * advisory downgrade and the ignore-entries count warning are
     * untouched.
     *
     * @return list<Finding>
     */
    private function unbackedIgnoreFindings(CheckContext $context): array
    {
        $entries = self::composerJsonIgnoreEntries($context->appPath);
        if ($entries === []) {
            return [];
        }

        $backing = [];
        foreach ($context->overrides as $override) {
            if ($override->check === self::ID && !$override->isExpired($context->today)) {
                $backing[] = $override;
            }
        }

        $findings = [];
        foreach ($entries as $entry) {
            $backed = false;
            foreach ($backing as $override) {
                if ($override->backsIgnoreEntry($entry['id'], $entry['package'])) {
                    $backed = true;
                    break;
                }
            }
            if (!$backed) {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_FAIL,
                    sprintf(
                        'ignore entry "%s" in composer.json has no matching override; record it in policy-overrides.json with a reason and an expiry',
                        $entry['label'],
                    ),
                    'composer.json',
                    1,
                    null,
                    'unbacked-ignore',
                );
            }
        }

        return $findings;
    }

    /*
     * The "policy-ignore-id" definition, verbatim from res/composer-schema.json
     * in composer 2.10.3:
     *
     * {
     *     "description": "Advisory IDs (CVE, GHSA, PKSA, ...) with optional reason and per-rule scoping (on-block/on-audit).",
     *     "anyOf": [
     *         {
     *             "type": "array",
     *             "description": "Simple list of advisory IDs to ignore.",
     *             "items": {
     *                 "type": "string"
     *             }
     *         },
     *         {
     *             "type": "object",
     *             "description": "Map of advisory IDs to reason strings or detailed rule objects.",
     *             "additionalProperties": {
     *                 "anyOf": [
     *                     {
     *                         "type": "string",
     *                         "description": "Reason for ignoring this advisory ID."
     *                     },
     *                     {
     *                         "type": "null",
     *                         "description": "Ignore without explanation."
     *                     },
     *                     {
     *                         "type": "object",
     *                         "description": "Detailed ignore rule with optional scoping.",
     *                         "properties": {
     *                             "reason": {
     *                                 "type": "string",
     *                                 "description": "Explanation for ignoring this advisory ID."
     *                             },
     *                             "on-block": {
     *                                 "type": "boolean",
     *                                 "description": "If false, does not ignore during version blocking (only during audit)."
     *                             },
     *                             "on-audit": {
     *                                 "type": "boolean",
     *                                 "description": "If false, does not ignore during audit reporting (only during blocking)."
     *                             }
     *                         }
     *                     }
     *                 ]
     *             }
     *         }
     *     ]
     * }
     */
    /**
     * The advisory ids config.policy.advisories.ignore-id tells composer
     * audit to ignore, in either of composer's two shapes: a list of ids or
     * a map of id to reason. A detailed rule whose on-audit flag is false
     * does not apply to audit, so it stays out of this list.
     *
     * @return list<string>
     */
    private function policyIgnoreIds(mixed $setting): array
    {
        $ids = [];
        foreach ($this->ignorePairs($setting) as $pair) {
            $id = $pair['key'];
            if ($id === null) {
                $value = $pair['value'];
                $id = is_string($value) ? $value : (is_array($value) ? ($value['id'] ?? null) : null);
            }
            if (!is_string($id) || $id === '' || !$this->appliesToAudit($pair['value'])) {
                continue;
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /*
     * The "policy-ignore" definition, verbatim from res/composer-schema.json
     * in composer 2.10.3:
     *
     * {
     *     "description": "Package name patterns with optional version constraints and per-rule scoping (on-block/on-audit).",
     *     "anyOf": [
     *         {
     *             "type": "array",
     *             "description": "Simple list of package names or patterns to ignore.",
     *             "items": {
     *                 "type": "string"
     *             }
     *         },
     *         {
     *             "type": "object",
     *             "description": "Map of package names/patterns to reason strings or detailed rule objects.",
     *             "additionalProperties": {
     *                 "anyOf": [
     *                     {
     *                         "type": "string",
     *                         "description": "Reason for ignoring this package."
     *                     },
     *                     {
     *                         "type": "null",
     *                         "description": "Ignore without explanation."
     *                     },
     *                     {
     *                         "type": "object",
     *                         "description": "Detailed ignore rule with optional constraint and scoping.",
     *                         "properties": {
     *                             "constraint": {
     *                                 "type": "string",
     *                                 "description": "Version constraint to limit the ignore to specific versions."
     *                             },
     *                             "reason": {
     *                                 "type": "string",
     *                                 "description": "Explanation for ignoring this package."
     *                             },
     *                             "on-block": {
     *                                 "type": "boolean",
     *                                 "description": "If false, does not ignore during version blocking (only during audit)."
     *                             },
     *                             "on-audit": {
     *                                 "type": "boolean",
     *                                 "description": "If false, does not ignore during audit reporting (only during blocking)."
     *                             }
     *                         }
     *                     },
     *                     {
     *                         "type": "array",
     *                         "description": "Multiple ignore rules for the same package.",
     *                         "items": {
     *                             "type": "object",
     *                             "properties": {
     *                                 "constraint": {
     *                                     "type": "string"
     *                                 },
     *                                 "reason": {
     *                                     "type": "string"
     *                                 },
     *                                 "on-block": {
     *                                     "type": "boolean"
     *                                 },
     *                                 "on-audit": {
     *                                     "type": "boolean"
     *                                 }
     *                             }
     *                         }
     *                     }
     *                 ]
     *             }
     *         }
     *     ]
     * }
     */
    /**
     * The packages config.policy.advisories.ignore tells composer audit to
     * ignore, split into plain entries and entries limited by a version
     * constraint. A detailed rule applies to audit only when its on-audit
     * flag is not false, and a rule carrying a constraint only counts when
     * the locked version satisfies it. One entry may hold several rules; a
     * single matching rule is enough to ignore the package.
     *
     * @return array{packages: list<string>, constrained: list<array{package: string, constraint: string}>}
     */
    private function policyIgnorePackages(mixed $setting): array
    {
        $packages = [];
        $constrained = [];

        foreach ($this->ignorePairs($setting) as $pair) {
            $package = $pair['key'];
            if ($package === null && is_string($pair['value']) && $pair['value'] !== '') {
                $package = $pair['value'];
            }
            if (!is_string($package) || $package === '') {
                continue;
            }

            foreach ($this->ignoreRules($pair['value']) as $rule) {
                if (!$this->appliesToAudit($rule)) {
                    continue;
                }
                $constraint = is_array($rule) ? ($rule['constraint'] ?? null) : null;
                if (is_string($constraint) && $constraint !== '') {
                    $constrained[] = ['package' => $package, 'constraint' => $constraint];
                } else {
                    $packages[] = $package;
                }
            }
        }

        return [
            'packages' => array_values(array_unique($packages)),
            'constrained' => $constrained,
        ];
    }

    /**
     * The pairs of an ignore setting in either of composer's two shapes: a
     * list (each item carries its own value) or a map (each key names the
     * package or advisory id).
     *
     * @param mixed $setting
     *
     * @return list<array{key: string|null, value: mixed}>
     */
    private function ignorePairs(mixed $setting): array
    {
        if (!is_array($setting) || $setting === []) {
            return [];
        }

        $isMap = false;
        foreach (array_keys($setting) as $key) {
            if (!is_int($key)) {
                $isMap = true;
                break;
            }
        }

        $pairs = [];
        foreach ($setting as $key => $value) {
            $pairs[] = [
                'key' => $isMap && is_string($key) && $key !== '' ? $key : null,
                'value' => $value,
            ];
        }

        return $pairs;
    }

    /**
     * The rules behind one map entry of an ignore setting: a plain reason
     * (one rule with no options), a single detailed rule, or a list of
     * detailed rules.
     *
     * @param mixed $value
     *
     * @return list<mixed>
     */
    private function ignoreRules(mixed $value): array
    {
        if (!is_array($value)) {
            return [$value];
        }

        $isList = true;
        foreach (array_keys($value) as $key) {
            if (!is_int($key)) {
                $isList = false;
                break;
            }
        }

        return $isList ? array_values($value) : [$value];
    }

    /**
     * Whether an ignore rule applies to audit. The schema gives on-audit no
     * default, and its wording disables the rule only for an explicit
     * false, so an absent flag keeps the rule active. Any other value fails
     * safe: the finding is not hidden.
     */
    private function appliesToAudit(mixed $rule): bool
    {
        if (!is_array($rule)) {
            return true;
        }

        $flag = $rule['on-audit'] ?? null;
        if ($flag === null) {
            return true;
        }

        return $flag === true;
    }

    /**
     * Whether the locked version satisfies a rule's constraint, judged with
     * composer/semver. A missing locked version or an unparseable
     * constraint matches nothing: fail safe.
     */
    private function constraintMatches(?string $version, string $constraint): bool
    {
        if ($version === null || $version === '') {
            return false;
        }

        try {
            return Semver::satisfies($version, $constraint);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return false;
        }
    }

    /**
     * The names of an ignore setting in either of composer's two shapes:
     * a list of names (plain strings, or objects carrying an id) or a map
     * keyed by name.
     *
     * @return list<string>
     */
    private static function ignoreEntries(mixed $setting): array
    {
        if (!is_array($setting) || $setting === []) {
            return [];
        }

        $isMap = false;
        foreach (array_keys($setting) as $key) {
            if (!is_int($key)) {
                $isMap = true;
                break;
            }
        }

        $entries = [];
        foreach ($setting as $key => $value) {
            if ($isMap) {
                if (is_string($key) && $key !== '') {
                    $entries[] = $key;
                }
                continue;
            }

            if (is_string($value) && $value !== '') {
                $entries[] = $value;
            } elseif (is_array($value) && isset($value['id']) && is_string($value['id']) && $value['id'] !== '') {
                $entries[] = $value['id'];
            }
        }

        return array_values(array_unique($entries));
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
     * Finds the composer executable on PATH, or returns null.
     *
     * Shared with ComposerVersionCheck, which must find composer the same
     * way.
     */
    public static function findComposer(): ?string
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

    /**
     * The environment the audit runs with: PATH plus the variables a shell
     * and composer need to start (HOME, or APPDATA and USERPROFILE on
     * Windows, COMPOSER_HOME, TEMP/TMP, SystemRoot on Windows), and nothing
     * else. The caller's COMPOSER_* switches (COMPOSER_NO_AUDIT,
     * COMPOSER_AUTH, ...) must not reach the audit. Null when none of the
     * variables exists, so the process inherits the caller's environment
     * instead of starting with an empty one.
     *
     * @return array<string, string>|null
     */
    private static function auditEnvironment(): ?array
    {
        $isWindows = DIRECTORY_SEPARATOR === '\\';

        // PATH first, read the way findComposer() reads it.
        $path = getenv('PATH');
        if ($path === false || $path === '') {
            $path = getenv('Path');
        }

        $keys = $isWindows
            ? ['APPDATA', 'USERPROFILE', 'COMPOSER_HOME', 'TEMP', 'TMP', 'SystemRoot']
            : ['HOME', 'COMPOSER_HOME', 'TEMP', 'TMP'];

        $env = [];
        if ($path !== false && $path !== '') {
            $env['PATH'] = $path;
        }
        foreach ($keys as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                $env[$key] = $value;
            }
        }

        return $env === [] ? null : $env;
    }
}
