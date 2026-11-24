<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use InvalidArgumentException;
use Waynestate\Proctor\Creed\State;

/**
 * Grades the PHP version named by the deploy image tag in parity.json.
 *
 * The tag `citadel/php-apache:<X.Y>r<n>` names the PHP the app will run on,
 * and a stale image serves an unsupported PHP even when composer.json is
 * fine, so the line is graded against the policy as of today: active passes,
 * security-only warns, eol and eol_grace_expired warn, and a line the policy
 * does not list warns too. The check never fails: it reports, and is not in
 * the deploy gate. A missing or unparseable parity.json, or an image that is
 * not a php-apache tag, says nothing here: other checks own those verdicts.
 */
final class ImagePhpCheck implements CheckInterface
{
    public function id(): string
    {
        return 'image-php';
    }

    public function run(CheckContext $context): array
    {
        $parity = $this->readParity($context->appPath . '/parity.json');
        if ($parity === null) {
            return [];
        }

        $image = $parity['image'] ?? null;
        if (!is_string($image)) {
            return [];
        }

        if (preg_match('~(?:^|/)php-apache:(\d+\.\d+)r\d+$~', $image, $matches) !== 1) {
            return [];
        }

        $line = $matches[1];
        // The tag is the part after the last colon; the match above guarantees one.
        $tag = substr($image, strrpos($image, ':') + 1);

        try {
            $state = $context->policy->stateOf('php', $line, $context->today);
        } catch (InvalidArgumentException) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf('deploy image php-apache:%s: the policy does not list php %s, so its support cannot be graded', $tag, $line),
                'parity.json',
                1,
            )];
        }

        return match ($state) {
            State::Active => [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                sprintf('deploy image php-apache:%s runs php %s, which is active', $tag, $line),
                'parity.json',
                1,
                $state,
            )],
            State::Security => [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf('deploy image php-apache:%s runs php %s, which is in security-only support; move the image to a newer php-apache tag', $tag, $line),
                'parity.json',
                1,
                $state,
            )],
            default => [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf('deploy image php-apache:%s runs php %s, which is %s; move the image to a supported php-apache tag', $tag, $line, $state->value),
                'parity.json',
                1,
                $state,
            )],
        };
    }

    /**
     * The app's parity.json, decoded, or null when it is absent, unreadable
     * or does not parse to an object; those cases say nothing here.
     *
     * @return array<string, mixed>|null
     */
    private function readParity(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
