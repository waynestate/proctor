<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Advisor;

use Composer\InstalledVersions;
use Waynestate\Advisor\Exception\ToolInputError;
use Waynestate\Advisor\Module\Module;
use Waynestate\Advisor\Module\ModuleContext;
use Waynestate\Advisor\Module\ToolDefinition;
use Waynestate\Proctor\Check\CheckRunner;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\OverrideApproverCheck;
use Waynestate\Proctor\Check\OverridesCheck;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Override\OverrideFile;
use Waynestate\Proctor\Policy\PolicyResolver;
use Waynestate\Proctor\Policy\ResolvedPolicy;
use Waynestate\Proctor\ToolError;

/**
 * PROCTOR's ADVISOR module: two read-only tools that answer from PROCTOR's
 * own classes, in-process, about an app inside the project root.
 *
 *  - proctor_rules: every registered check, its rule id, whether the deploy
 *    gate runs it, and where its docs live.
 *  - proctor_check: PROCTOR's file checks on an app folder, run with
 *    CheckRunner the way `proctor check` runs them with its defaults (the
 *    bundled CREED policy, today's date, the app's policy-overrides.json).
 *
 * The checks that start a subprocess (composer audit, composer --version, and
 * every check that asks git which files are committed) and the two that read
 * every committed env file, `.env` included (app-key, vite-secrets), are left
 * out and listed under `skipped`, so an agent knows exactly what it did not
 * see. No subprocess, no network, nothing written, and `.env` is never read.
 *
 * Four checks that do run read env files other than `.env`: mysql-eol and
 * mysql-minimum (`.env.squire.example`, `.env.example`), app-debug
 * (`.env.production`, `.env.squire.example`) and csp-unsafe-inline
 * (`.env.example`, `.env.production`, `.env.squire.example`). Here every one
 * of them grades an env file only when git lists it (TrackedEnvFiles reads
 * git's index in PHP, without starting git); a file on disk that git does
 * not list is not opened, and comes back under `skipped_files`. The full run
 * is still `vendor/bin/proctor check`, in CI, where PROCTOR decides.
 *
 * The module only loads inside ADVISOR (a dev dependency PROCTOR suggests
 * and never requires), so its waynestate/advisor imports resolve there.
 * Pencils down: this is a practice paper, the real exam is in CI.
 */
final class ProctorModule implements Module
{
    public const TOOL = 'proctor';

    /** The line every answer ends with. */
    public const VERDICT = 'PROCTOR decides.';

    public const REASON_SUBPROCESS = 'starts a subprocess';

    public const REASON_ENV = 'reads .env';

    /**
     * The registered checks this module leaves out, by id, with the reason.
     * Every other check in CheckSet::all() runs in-process; a test reads
     * each of their classes and fails when one starts to spawn or read the
     * environment, so a new check is sorted here before it ships.
     *
     * composer-audit, composer-version and wayneui-outdated run composer;
     * instant-client-committed, lock-tracked, the WayneUI checks and every
     * check built on RepoFiles (node-eol, env-outside-config,
     * raw-identity-header, robots-txt, served-folder, world-writable) run git,
     * and override-approver runs git blame. app-key and vite-secrets read every
     * committed env file, .env included (and run git too).
     *
     * @var array<string, string>
     */
    public const SKIPPED = [
        'node-eol' => self::REASON_SUBPROCESS,
        'lock-tracked' => self::REASON_SUBPROCESS,
        'composer-audit' => self::REASON_SUBPROCESS,
        'composer-version' => self::REASON_SUBPROCESS,
        'instant-client-committed' => self::REASON_SUBPROCESS,
        'env-outside-config' => self::REASON_SUBPROCESS,
        'raw-identity-header' => self::REASON_SUBPROCESS,
        'app-key' => self::REASON_ENV,
        'robots-txt' => self::REASON_SUBPROCESS,
        'vite-secrets' => self::REASON_ENV,
        'served-folder' => self::REASON_SUBPROCESS,
        'world-writable' => self::REASON_SUBPROCESS,
        'wayneui-forked-views' => self::REASON_SUBPROCESS,
        'wayneui-outdated' => self::REASON_SUBPROCESS,
        'override-approver' => self::REASON_SUBPROCESS,
    ];

    public const DOCS = 'https://proctor.apps.wayne.edu/docs/';

    /**
     * The guide page that documents each check, under an h2 named after its
     * id. A check missing here is documented on the App checks page.
     *
     * @var array<string, string>
     */
    private const GUIDES = [
        'php-floor' => 'check-php',
        'unpatched' => 'check-php',
        'laravel-eol' => 'check-php',
        'laravel-php' => 'check-php',
        'mysql56' => 'check-php',
        'policy-age' => 'check-php',
        'unbounded-constraints' => 'check-composer',
        'dev-only-packages' => 'check-composer',
        'composer-config' => 'check-composer',
        'composer-cooldown' => 'check-composer',
        'lock-committed' => 'check-composer',
        'lock-tracked' => 'check-composer',
        'composer-audit' => 'check-composer',
        'composer-version' => 'check-composer',
        'repo-url-credentials' => 'check-composer',
        'ci-pipelines' => 'check-ci',
        'preflight-pinned' => 'check-ci',
        'preflight-gates' => 'check-ci',
        'portability' => 'check-ci',
        'destructive-deploy' => 'check-ci',
        'instant-client-committed' => 'check-ci',
        'wayneui-forked-views' => 'check-wayneui',
        'wayneui-outdated' => 'check-wayneui',
        'app-debug' => 'check-app',
        'display-errors' => 'check-app',
        'directory-listing' => 'check-app',
        'csp-unsafe-inline' => 'check-app',
        'robots-txt' => 'check-app',
        'vite-secrets' => 'check-app',
        'mysql-minimum' => 'check-app',
        'entrypoint-migrate' => 'check-app',
    ];

    /** The bundled CREED policy, the copy PolicyResolver falls back to. */
    private const BUNDLED_POLICY = __DIR__ . '/../Creed/policy.json';

    private const PACKAGE = 'waynestate/proctor';

    public function __construct(private readonly ModuleContext $context) {}

    public function id(): string
    {
        return 'proctor';
    }

    /** @return list<ToolDefinition> */
    public function tools(): array
    {
        return [
            self::rulesTool(),
            self::checkTool($this->context),
        ];
    }

    /** @return list<\Waynestate\Advisor\Module\ResourceDefinition> */
    public function resources(): array
    {
        return [];
    }

    /** @return list<\Waynestate\Advisor\Module\PromptDefinition> */
    public function prompts(): array
    {
        return [];
    }

    /**
     * The docs URL for a check: its guide page, anchored on its id. The
     * overrides check, and the override-approver check that reads the same
     * file, have a page of their own.
     */
    public static function docsUrl(string $checkId): string
    {
        if ($checkId === OverridesCheck::ID || $checkId === OverrideApproverCheck::ID) {
            return self::DOCS . 'guides/overrides.html';
        }

        return self::DOCS . 'guides/' . (self::GUIDES[$checkId] ?? 'check-app') . '.html#' . $checkId;
    }

    private static function rulesTool(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'proctor_rules',
            title: 'List the rules PROCTOR checks',
            description: 'Lists every check PROCTOR registers, in the order a report prints them, from PROCTOR\'s own CheckSet: each check id, its rule id (proctor/<id>; a named rule within a check reads proctor/<id>/<rule>), whether the deploy gate (proctor gate) runs it, and the docs page that explains it. Takes no arguments. No subprocess, no network, nothing written, and it never reads .env or any app file; there is no path to give, so nothing outside the project is touched. PROCTOR decides.',
            inputSchema: ['type' => 'object', 'additionalProperties' => false],
            handler: static function (): array {
                $version = self::version();
                $rules = [];
                foreach (CheckSet::ids(CheckSet::all(self::bundledPolicy())) as $id) {
                    $rules[] = [
                        'id' => $id,
                        'rule_id' => 'proctor/' . $id,
                        'in_gate' => in_array($id, CheckSet::GATE, true),
                        'docs' => self::docsUrl($id),
                    ];
                }

                return [
                    'tool' => self::TOOL,
                    'ran' => 'Listed the registered checks in-process with CheckSet::all() and CheckSet::GATE (no subprocess, no network, nothing written, no .env read).',
                    'proctor_version' => $version,
                    'rules' => $rules,
                    'source' => [
                        'code' => 'waynestate/proctor ' . $version . ': src/Check/CheckSet.php (all, GATE)',
                        'docs' => self::DOCS . 'guides/checks-overview.html',
                        'rule_ids' => self::DOCS . 'reference/rule-ids.html',
                        'deploy_gate' => self::DOCS . 'guides/deploy-gate.html',
                    ],
                    'notice' => 'Reference data from PROCTOR ' . $version . '. A check may report several named rules under its rule id; the rule ids page lists them all. ' . self::VERDICT,
                ];
            },
            group: 'proctor',
            readOnly: true,
            idempotent: true,
            openWorld: false,
        );
    }

    private static function checkTool(ModuleContext $context): ToolDefinition
    {
        return new ToolDefinition(
            name: 'proctor_check',
            title: 'Run PROCTOR\'s file checks on an app folder',
            description: 'Runs PROCTOR\'s checks on the app folder in-process with PROCTOR\'s own CheckRunner, the way proctor check runs them by default: the CREED policy PROCTOR bundles, today\'s date and the app\'s policy-overrides.json. Returns each finding (rule id, status, message, file, line), the counts per status and a pass or fail verdict. The checks that start a subprocess (composer audit, composer --version, the ones that ask git which files are committed) and the two that read committed .env files (app-key, vite-secrets) are left out and listed under skipped; run proctor check for the full set. Four checks that run read env files other than .env: mysql-eol and mysql-minimum (.env.squire.example, .env.example), app-debug (.env.production, .env.squire.example) and csp-unsafe-inline (.env.example, .env.production, .env.squire.example). All four grade an env file only when git lists it (git\'s index is read in PHP); one git does not list is not opened and is listed under skipped_files. No subprocess, no network, nothing written, and it never reads .env. The path must be inside the project. PROCTOR decides.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'maxLength' => 1024, 'description' => 'App folder, inside the project root. Default: the root itself.'],
                ],
                'additionalProperties' => false,
            ],
            handler: static function (array $args) use ($context): array {
                $app = self::appFolder($context, $args);
                $relative = $context->guard->relative($app);
                $version = self::version();
                $today = $context->clock->today();
                $resolved = self::bundledPolicy();

                $all = CheckSet::all($resolved);
                $checks = [];
                $skipped = [];
                foreach ($all as $check) {
                    $reason = self::SKIPPED[$check->id()] ?? null;
                    if ($reason === null) {
                        $checks[] = $check;
                    } else {
                        $skipped[] = ['id' => $check->id(), 'reason' => $reason];
                    }
                }

                $envFiles = new TrackedEnvFiles($app);
                try {
                    $overrides = OverrideFile::load($app);
                    OverrideFile::assertKnownChecks($overrides, CheckSet::ids($all));
                    $findings = (new CheckRunner($app, $resolved->policy, $today, $checks, $overrides, envFiles: $envFiles))->run();
                } catch (ToolError $e) {
                    throw new ToolInputError(self::hideRoot($context, $e->getMessage()));
                }

                $out = [];
                $counts = [
                    Finding::STATUS_PASS => 0,
                    Finding::STATUS_INFO => 0,
                    Finding::STATUS_WARNING => 0,
                    Finding::STATUS_FAIL => 0,
                ];
                foreach ($findings as $finding) {
                    if (self::unusedForSkippedCheck($finding)) {
                        continue;
                    }
                    $out[] = [
                        'rule_id' => $finding->ruleId,
                        'status' => $finding->status,
                        'message' => self::hideRoot($context, $finding->message),
                        'file' => $finding->path,
                        'line' => $finding->path === null ? null : $finding->line,
                    ];
                    $counts[$finding->status]++;
                }

                return [
                    'tool' => self::TOOL,
                    'ran' => sprintf(
                        'Ran %d of PROCTOR %s\'s %d checks in-process with CheckRunner on %s, against the bundled CREED policy, for %s (no subprocess, no network, nothing written, no .env read, other env files only when git lists them).',
                        count($checks),
                        $version,
                        count($all),
                        $relative,
                        $today->format('Y-m-d'),
                    ),
                    'app' => $relative,
                    'proctor_version' => $version,
                    'findings' => $out,
                    'counts' => $counts,
                    'verdict' => $counts[Finding::STATUS_FAIL] > 0 ? 'fail' : 'pass',
                    'skipped' => $skipped,
                    'skipped_files' => $envFiles->refused(),
                    'source' => [
                        'code' => 'waynestate/proctor ' . $version . ': src/Check/CheckRunner.php and src/Check/CheckSet.php',
                        'policy' => 'waynestate/proctor ' . $version . ': src/Creed/policy.json (bundled)',
                        'policy_refreshed_on' => $resolved->refreshedOn,
                        'date' => $today->format('Y-m-d'),
                        'overrides' => $overrides === [] ? null : ($relative === '.' ? '' : $relative . '/') . OverrideFile::FILENAME,
                        'docs' => self::DOCS . 'guides/checks-overview.html',
                    ],
                    'notice' => 'Reference data from PROCTOR ' . $version . ': a partial run, the skipped checks did not run, so a pass here is not a pass in CI. Run vendor/bin/proctor check for every check. ' . self::VERDICT,
                ];
            },
            group: 'proctor',
            readOnly: true,
            idempotent: true,
            openWorld: false,
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function appFolder(ModuleContext $context, array $args): string
    {
        $path = $args['path'] ?? '.';
        $app = $context->guard->resolve(is_string($path) ? $path : '');
        if (!is_dir($app)) {
            throw new ToolInputError('The path must be a directory that holds the app.');
        }

        return $app;
    }

    /**
     * The bundled CREED policy as PolicyResolver would resolve it with no
     * --policy, no PROCTOR_POLICY and no vendor copy. It is built directly so
     * the module never reads the environment.
     */
    private static function bundledPolicy(): ResolvedPolicy
    {
        $path = realpath(self::BUNDLED_POLICY);
        $path = $path === false ? self::BUNDLED_POLICY : $path;
        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $refreshed = is_array($data) && is_string($data['refreshed_on'] ?? null)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['refreshed_on']) === 1 ? $data['refreshed_on'] : null;

        return new ResolvedPolicy(Policy::bundled(), PolicyResolver::ORIGIN_BUNDLED, $path, $refreshed);
    }

    /**
     * An "unused override" warning for a check this module skipped: the check
     * did not run, so its override had nothing to match. Not news.
     */
    private static function unusedForSkippedCheck(Finding $finding): bool
    {
        if ($finding->ruleId !== 'proctor/' . OverridesCheck::ID . '/unused') {
            return false;
        }
        foreach (array_keys(self::SKIPPED) as $id) {
            if (str_starts_with($finding->message, sprintf('override for "%s"', $id))) {
                return true;
            }
        }

        return false;
    }

    /** Messages may quote the app path; the answer never shows the host layout. */
    private static function hideRoot(ModuleContext $context, string $message): string
    {
        $root = $context->guard->root();

        return str_replace([$root . DIRECTORY_SEPARATOR, $root . '/', $root], ['', '', '.'], $message);
    }

    /** The installed PROCTOR version as tagged, or "dev" when unknown. */
    private static function version(): string
    {
        try {
            $pretty = InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (\Throwable) {
            return 'dev';
        }
        if ($pretty === null || $pretty === '' || str_contains($pretty, 'no-version-set')) {
            return 'dev';
        }

        return preg_replace('/^v/i', '', $pretty) ?? $pretty;
    }
}
