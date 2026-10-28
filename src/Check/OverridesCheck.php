<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\Override\OverrideFile;

/**
 * Reports expired overrides.
 *
 * When the app has no policy-overrides.json, the check produces no findings
 * at all: it passes and prints nothing. Every override, active or expired,
 * is printed as an OVERRIDE line by the CLI in addition to these findings.
 */
final class OverridesCheck implements CheckInterface
{
    public const ID = 'overrides';

    public function id(): string
    {
        return self::ID;
    }

    public function run(CheckContext $context): array
    {
        $findings = [];
        foreach ($context->overrides as $override) {
            if ($override->isExpired($context->today)) {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_FAIL,
                    sprintf(
                        'override for "%s" expired on %s',
                        $override->check,
                        $override->displayDate(),
                    ),
                    OverrideFile::FILENAME,
                );
            }
        }

        return $findings;
    }
}
