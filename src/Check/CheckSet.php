<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\Policy\ResolvedPolicy;

/**
 * The registered checks, in output order, and the smaller set the deploy gate
 * runs.
 *
 * `proctor check` runs every check here. `proctor gate` runs only the GATE
 * ids: the deploy-time rule set of CREED's deploy-gate chapter (ADR-0006),
 * which must stay small, offline-tolerant and fast. Everything heavier stays
 * in CI. A new check is added once, below, and both commands see it: the
 * overrides guard reads its ids from all(), so an override for a check the
 * gate skips is still a known check.
 */
final class CheckSet
{
    /**
     * The checks the deploy gate runs, by id: the PHP floor and runtime, the
     * framework line and its PHP range, the lock file, known advisories, and
     * the overrides themselves (an expired override always blocks).
     * policy-age rides along so a stale policy is visible in the deploy log.
     */
    public const GATE = [
        'php-floor',
        'unpatched',
        'laravel-eol',
        'laravel-php',
        'lock-committed',
        'composer-audit',
        'overrides',
        'policy-age',
    ];

    private function __construct() {}

    /**
     * Every registered check, in output order.
     *
     * @param ComposerVersionCheck|null $composerVersion the composer-version check to register, so the caller can
     *                                                   read the version it found; a fresh one when null
     *
     * @return list<CheckInterface>
     */
    public static function all(ResolvedPolicy $resolved, ?ComposerVersionCheck $composerVersion = null): array
    {
        return [
            new PhpFloorCheck(),
            new UnpatchedCheck(),
            new LaravelEolCheck(),
            new LaravelPhpCheck(),
            new NodeEolCheck(),
            new MysqlEolCheck(),
            new UnboundedConstraintsCheck(),
            new ComposerConfigCheck(),
            new LockCommittedCheck(),
            new PortabilityCheck(),
            new Mysql56Check(),
            new DestructiveDeployCheck(),
            new CrlfDeployScriptCheck(),
            new CaseClashCheck(),
            new CiPipelinesCheck(),
            new PreflightPinnedCheck(),
            new ComposerAuditCheck(),
            $composerVersion ?? new ComposerVersionCheck(policy: $resolved->policy),
            new RepoUrlCredentialsCheck(),
            new InstantClientCommittedCheck(),
            new EnvOutsideConfigCheck(),
            new RawIdentityHeaderCheck(),
            new AppKeyCheck(),
            new ServedFolderCheck(),
            new WorldWritableCheck(),
            new WayneUiForkedViewsCheck(),
            new WayneUiOutdatedCheck(),
            new OverridesCheck(),
            new PolicyAgeCheck($resolved),
        ];
    }

    /**
     * The checks the deploy gate runs, in the same output order as all().
     *
     * @return list<CheckInterface>
     */
    public static function gate(ResolvedPolicy $resolved): array
    {
        return array_values(array_filter(
            self::all($resolved),
            static fn(CheckInterface $check): bool => in_array($check->id(), self::GATE, true),
        ));
    }

    /**
     * The ids of the given checks, in order.
     *
     * @param list<CheckInterface> $checks
     *
     * @return list<string>
     */
    public static function ids(array $checks): array
    {
        return array_map(static fn(CheckInterface $check): string => $check->id(), $checks);
    }
}
