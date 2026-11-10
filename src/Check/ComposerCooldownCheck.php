<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\ToolError;

/**
 * Checks composer.json for a Composer release cooldown (CREED practice 40).
 *
 * Without config.policy.cooldown.period, Composer installs a newly published
 * version the moment it is released, before any bug report or rollback has
 * had time to land. Sourdough's shapes set the period and exempt the stack's
 * own packages; this check tells an app that has not done so, and warns when
 * a period is set but block is false, which turns the cooldown off again.
 * When the CREED policy names a period in tools.composer.cooldown.period,
 * the advice carries it and its ignore list, and the check also warns when
 * the app's period is shorter than the policy's.
 *
 * Every finding is a warning or a pass, never a fail, so an app that passes
 * today keeps passing while the owner sees the missing switch.
 */
final class ComposerCooldownCheck implements CheckInterface
{
    public function __construct(private readonly ?Policy $policy = null) {}

    public function id(): string
    {
        return 'composer-cooldown';
    }

    public function run(CheckContext $context): array
    {
        $file = $context->appPath . '/composer.json';
        if (!is_file($file)) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no composer.json, so no config to check',
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

        $config = $data['config'] ?? [];
        if (!is_array($config)) {
            $config = [];
        }

        $policy = $config['policy'] ?? null;
        $cooldown = is_array($policy) ? ($policy['cooldown'] ?? null) : null;
        if (!is_array($cooldown)) {
            $cooldown = [];
        }

        $policyPeriod = $this->policy?->toolCooldownPeriod('composer');
        $ignore = $this->policy?->toolCooldownIgnore('composer') ?? [];
        if ($ignore === []) {
            $ignore = ['waynestate/*'];
        }

        $period = $cooldown['period'] ?? null;
        if ($period === null || $period === '') {
            return [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf(
                    'composer.json sets no release cooldown; add "config": {"policy": {"cooldown": {"period": "%s", "ignore": %s}}} so Composer waits before it installs a newly published version (CREED practice 40)',
                    $policyPeriod ?? '3 days',
                    json_encode($ignore, JSON_UNESCAPED_SLASHES),
                ),
                'composer.json',
                1,
                null,
                'missing',
            )];
        }

        if (($cooldown['block'] ?? null) === false) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'composer.json sets a release cooldown but turns blocking off (policy.cooldown.block is false), so Composer installs new versions anyway; remove "block": false',
                'composer.json',
                1,
                null,
                'not-blocking',
            )];
        }

        if ($policyPeriod !== null && is_string($period)) {
            $appHours = $this->periodHours($period);
            $policyHours = $this->periodHours($policyPeriod);
            if ($appHours !== null && $policyHours !== null && $appHours < $policyHours) {
                return [new Finding(
                    $this->id(),
                    Finding::STATUS_WARNING,
                    sprintf(
                        'composer.json sets a release cooldown of %s, shorter than the %s the CREED policy names; raise config.policy.cooldown.period to at least %s (CREED practice 40)',
                        $period,
                        $policyPeriod,
                        $policyPeriod,
                    ),
                    'composer.json',
                    1,
                    null,
                    'too-short',
                )];
            }
        }

        return [new Finding(
            $this->id(),
            Finding::STATUS_PASS,
            sprintf('composer.json sets a release cooldown of %s', $period),
            'composer.json',
        )];
    }

    /**
     * A period such as "3 days" in whole hours: hours x 1, days x 24,
     * weeks x 168. Null when the period does not name a positive count and
     * one of the units hour, day or week.
     */
    private function periodHours(string $period): ?int
    {
        if (preg_match('/^([1-9][0-9]*)\s*(hour|hours|day|days|week|weeks)$/i', trim($period), $matches) !== 1) {
            return null;
        }

        $n = (int) $matches[1];
        $unit = strtolower($matches[2]);
        if (str_starts_with($unit, 'h')) {
            return $n;
        }

        if (str_starts_with($unit, 'd')) {
            return $n * 24;
        }

        return $n * 168;
    }
}
