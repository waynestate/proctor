<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\ToolError;

/**
 * Checks composer.json for a Composer release cooldown (CREED practice 40).
 *
 * Without config.policy.cooldown.period, Composer installs a newly published
 * version the moment it is released, before any bug report or rollback has
 * had time to land. Sourdough's shapes set the period and exempt the stack's
 * own packages; this check tells an app that has not done so, and warns when
 * a period is set but block is false, which turns the cooldown off again.
 *
 * Every finding is a warning or a pass, never a fail, so an app that passes
 * today keeps passing while the owner sees the missing switch.
 */
final class ComposerCooldownCheck implements CheckInterface
{
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

        $period = $cooldown['period'] ?? null;
        if ($period === null || $period === '') {
            return [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                'composer.json sets no release cooldown; add "config": {"policy": {"cooldown": {"period": "3 days", "ignore": ["waynestate/*"]}}} so Composer waits before it installs a newly published version (CREED practice 40)',
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

        return [new Finding(
            $this->id(),
            Finding::STATUS_PASS,
            sprintf('composer.json sets a release cooldown of %s', $period),
            'composer.json',
        )];
    }
}
