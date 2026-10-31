<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Composer\Semver\Semver;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\ToolError;

/**
 * Runs "composer audit --format=json --locked" in the app path and reports
 * the advisories and abandoned packages it finds.
 *
 * Composer exits non-zero (1, 2 or 3) when it reports findings; that is
 * expected, so the decision is made from stdout, never from the exit code
 * alone. Each advisory finding names the package, the locked version, the
 * advisory's CVE (or advisory id), its title and its severity. An advisory
 * the app ignores in its composer.json (config.policy.advisories.ignore-id,
 * config.policy.advisories.ignore or the legacy config.audit.ignore) is a
 * warning, not a fail, and so is anything composer has already moved to its
 * ignored-advisories section. A detailed ignore rule only counts when it
 * applies to audit (its on-audit flag is not false) and, for a package rule
 * carrying a constraint, when the locked version satisfies it. Abandoned
 * packages are warnings. When the composer executable or composer.lock is
 * missing, the check reports one info finding and nothing else.
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
                null,
                1,
                null,
                'skipped',
            )];
        }

        if (!is_file($context->appPath . '/composer.lock')) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_INFO,
                'no composer.lock in the app path; composer audit skipped',
                null,
                1,
                null,
                'skipped',
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
                1,
                null,
                'could-not-run',
            )];
        }

        $threshold = $this->threshold($context->policy);
        $ignored = $this->ignoredAdvisories($context);
        $versions = $this->lockedVersions($context);

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
                    $findings[] = new Finding(
                        $this->id(),
                        $this->advisoryStatus(
                            (string) $package,
                            $versions[(string) $package] ?? null,
                            $entry,
                            $severity,
                            $threshold,
                            $ignored,
                            $alreadyIgnored,
                        ),
                        $this->advisoryMessage((string) $package, $versions[(string) $package] ?? null, $entry, $severity),
                        'composer.lock',
                        1,
                        null,
                        'advisory',
                        [$this->advisoryData($entry)],
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
     * The status of an advisory: a warning when the app ignores it, or when
     * composer has already moved it to the ignored section, otherwise a
     * fail when its severity is at or above the threshold and a warning
     * below it.
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
    ): string {
        if ($alreadyIgnored || $this->isIgnored($package, $version, $entry, $ignored)) {
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
        if (in_array($package, $ignored['packages'], true)) {
            return true;
        }

        foreach ($ignored['constrained'] as $candidate) {
            if ($candidate['package'] === $package && $this->constraintMatches($version, $candidate['constraint'])) {
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
     * naming the locked version when composer.lock records one.
     *
     * @param array<string, mixed> $entry
     */
    private function advisoryMessage(string $package, ?string $version, array $entry, mixed $severity): string
    {
        $head = $version !== null && $version !== ''
            ? $package . ' ' . ltrim($version, 'v')
            : $package;

        $title = $entry['title'] ?? null;

        return sprintf(
            '%s: %s %s (%s)',
            $head,
            $this->advisoryId($entry),
            is_string($title) && $title !== '' ? $title : 'no title',
            is_string($severity) && $severity !== '' ? $severity : 'unknown',
        );
    }

    /**
     * The machine-readable form of the advisory for the JSON report.
     *
     * @param array<string, mixed> $entry
     *
     * @return array{id: string, cve: string|null, title: string, link: string|null, severity: string|null}
     */
    private function advisoryData(array $entry): array
    {
        $cve = $entry['cve'] ?? null;
        $title = $entry['title'] ?? null;
        $link = $entry['link'] ?? null;
        $severity = $entry['severity'] ?? null;

        return [
            'id' => $this->advisoryId($entry),
            'cve' => is_string($cve) && $cve !== '' ? $cve : null,
            'title' => is_string($title) ? $title : '',
            'link' => is_string($link) ? $link : null,
            'severity' => is_string($severity) ? $severity : null,
        ];
    }

    /**
     * The versions composer.lock pins, keyed by package name.
     *
     * @return array<string, string>
     */
    private function lockedVersions(CheckContext $context): array
    {
        $file = $context->appPath . '/composer.lock';
        $raw = file_get_contents($file);
        if ($raw === false) {
            return [];
        }

        $lock = json_decode($raw, true);
        if (!is_array($lock)) {
            return [];
        }

        $versions = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $lock[$section] ?? [];
            if (!is_array($packages)) {
                continue;
            }
            foreach ($packages as $package) {
                if (!is_array($package)) {
                    continue;
                }
                $name = $package['name'] ?? null;
                $version = $package['version'] ?? null;
                if (is_string($name) && $name !== '' && is_string($version) && $version !== '') {
                    $versions[$name] = $version;
                }
            }
        }

        return $versions;
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
        foreach ($this->ignoreEntries($config['audit']['ignore'] ?? null) as $entry) {
            if (str_contains($entry, '/')) {
                $packages[] = $entry;
            } else {
                $ids[] = $entry;
            }
        }

        return ['ids' => $ids, 'packages' => $packages, 'constrained' => []];
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
     * The values of an ignore setting in either of composer's two shapes:
     * a list of ids (plain strings, or objects carrying an id) or a map of
     * id to reason.
     *
     * @return list<string>
     */
    private function ignoreEntries(mixed $setting): array
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
}
