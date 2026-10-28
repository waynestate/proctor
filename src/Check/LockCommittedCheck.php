<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

/**
 * Checks that composer.lock is committed, i.e. present in the app path.
 */
final class LockCommittedCheck implements CheckInterface
{
    public function id(): string
    {
        return 'lock-committed';
    }

    public function run(CheckContext $context): array
    {
        if (is_file($context->appPath . '/composer.lock')) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'composer.lock is present',
                'composer.lock',
            )];
        }

        return [new Finding(
            $this->id(),
            Finding::STATUS_FAIL,
            'composer.lock is missing',
            'composer.lock',
        )];
    }
}
