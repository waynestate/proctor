<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Waynestate\Proctor\Policy\PolicyException;
use Waynestate\Proctor\Policy\State;
use Waynestate\Proctor\ToolError;

/**
 * Checks that the lowest PHP version allowed by the require.php constraint
 * of composer.json is still supported by the policy.
 *
 * active -> pass, security -> warning, eol and eol_grace_expired -> fail.
 */
final class PhpFloorCheck implements CheckInterface
{
    public function id(): string
    {
        return 'php-floor';
    }

    public function run(CheckContext $context): array
    {
        $composer = $this->readComposerJson($context->appPath);

        $constraint = $composer['require']['php'] ?? null;
        if (!is_string($constraint) || trim($constraint) === '') {
            return [new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                'composer.json has no require.php constraint',
                'composer.json',
            )];
        }

        $floor = $this->lowestVersion($constraint);
        if ($floor === null) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                sprintf('php constraint "%s" allows no stable version', $constraint),
                'composer.json',
            )];
        }

        try {
            $state = $context->policy->stateOf('php', $floor, $context->today);
        } catch (PolicyException $e) {
            return [new Finding($this->id(), Finding::STATUS_FAIL, $e->getMessage(), 'composer.json')];
        }

        return match ($state) {
            State::ACTIVE => [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                sprintf('php floor %s is active', $floor),
                'composer.json',
                1,
                $state,
            )],
            State::SECURITY => [new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                $this->securityOnlyMessage($floor, $context),
                'composer.json',
                1,
                $state,
            )],
            default => [new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                sprintf('php floor %s is %s', $floor, $state),
                'composer.json',
                1,
                $state,
            )],
        };
    }

    /**
     * Reads and decodes the app's composer.json.
     *
     * The CheckRunner has already validated the file, so this only throws if
     * the file changed between the two reads.
     *
     * @return array<string, mixed>
     *
     * @throws ToolError
     */
    private function readComposerJson(string $appPath): array
    {
        $file = $appPath . '/composer.json';
        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            throw new ToolError(sprintf('composer.json in "%s" is not valid JSON.', $appPath));
        }

        return $data;
    }

    /**
     * The lowest version the constraint allows, reduced to major.minor
     * (e.g. "^8.4" -> "8.4", "*" -> "0.0").
     *
     * Returns null when the constraint allows no stable (numeric) version.
     *
     * @throws ToolError if the constraint string is not a valid version constraint
     */
    private function lowestVersion(string $constraint): ?string
    {
        try {
            $parsed = (new VersionParser())->parseConstraints($constraint);
            $intervals = Intervals::get($parsed);
        } catch (\UnexpectedValueException $e) {
            throw new ToolError(sprintf(
                'php constraint "%s" is not a valid version constraint: %s',
                $constraint,
                $e->getMessage(),
            ));
        }

        $numeric = $intervals['numeric'];
        if ($numeric === []) {
            return null;
        }

        // Intervals are sorted ascending; the start of the first one is the
        // lowest bound. Reduce it to major.minor.
        $version = $numeric[0]->getStart()->getVersion();

        return preg_replace('/^(\d+\.\d+).*$/', '$1', $version) ?? $version;
    }

    /**
     * The security-only warning with the end date and the days left.
     *
     * The days are whole days from the context's today to security_until,
     * the same midnight-to-midnight comparison PolicySource uses for its
     * state: today == security_until is the last day ("ends today"), the
     * day before has 1 day left.
     */
    private function securityOnlyMessage(string $floor, CheckContext $context): string
    {
        $until = $context->policy->securityUntilOf('php', $floor);
        if ($until === null) {
            return sprintf('php floor %s is in security-only support', $floor);
        }

        $days = (int) $context->today->diff($until)->format('%a');
        $left = match (true) {
            $days === 0 => 'ends today',
            $days === 1 => '1 day left',
            default => sprintf('%d days left', $days),
        };

        return sprintf(
            'php floor %s is in security-only support until %s (%s)',
            $floor,
            $until->format('Y-m-d'),
            $left,
        );
    }
}
