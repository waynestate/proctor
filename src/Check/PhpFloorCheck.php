<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Intervals;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use InvalidArgumentException;
use Waynestate\Proctor\Creed\State;
use Waynestate\Proctor\ToolError;

/**
 * Checks that the lowest PHP version allowed by the require.php constraint
 * of composer.json is still supported by the policy.
 *
 * active -> pass, security -> warning, eol and eol_grace_expired -> fail.
 *
 * With --php-runtime it also grades the PHP that will serve the app, since a
 * declared ^8.4 proves nothing about the image it lands on. A runtime below
 * the constraint fails (Composer's platform check stops that app on boot);
 * one above its upper bound warns. A runtime inside the constraint gets the
 * floor's table, except that end of life only warns: a runtime can only be
 * end of life there when the floor is too, and the floor already fails, so
 * a baselined gate does not grow a second blocker for the same exam paper.
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
        if ($composer === null) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_FAIL,
                'no composer.json: the app declares no PHP version',
                'composer.json',
            )];
        }

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
        } catch (InvalidArgumentException $e) {
            return [new Finding($this->id(), Finding::STATUS_FAIL, $e->getMessage(), 'composer.json')];
        }

        $findings = match ($state) {
            State::Active => [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                sprintf('php floor %s is active', $floor),
                'composer.json',
                1,
                $state,
            )],
            State::Security => [new Finding(
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
                sprintf('php floor %s is %s', $floor, $state->value),
                'composer.json',
                1,
                $state,
            )],
        };

        if ($context->phpRuntime !== null) {
            $findings[] = $this->runtimeFinding($context, $context->phpRuntime, $constraint);
        }

        return $findings;
    }

    /**
     * The verdict on the PHP that will serve the app (--php-runtime), read
     * against the declared constraint first and the policy second.
     *
     * Rule `runtime-mismatch` when the runtime falls outside require.php
     * (fail below it, warning above it); rule `runtime` otherwise: active
     * passes, security-only, end of life and a line the policy does not list
     * warn.
     */
    private function runtimeFinding(CheckContext $context, string $runtime, string $constraint): Finding
    {
        $parser = new VersionParser();
        // The constraint already parsed in lowestVersion(), so this cannot throw.
        $parsed = $parser->parseConstraints($constraint);

        if (!Semver::satisfies($runtime, $constraint)) {
            $exact = new Constraint('==', $parser->normalize($runtime));
            $numeric = Intervals::get($parsed)['numeric'];
            $below = $numeric !== [] && !$numeric[0]->getStart()->matches($exact);

            return new Finding(
                $this->id(),
                $below ? Finding::STATUS_FAIL : Finding::STATUS_WARNING,
                $below
                    ? sprintf(
                        'php runtime %s is below require.php "%s": Composer\'s platform check stops the app on boot; serve it on a PHP the constraint allows',
                        $runtime,
                        $constraint,
                    )
                    : sprintf(
                        'php runtime %s is above require.php "%s": the app was never tested there; widen the constraint or serve it on a PHP it allows',
                        $runtime,
                        $constraint,
                    ),
                'composer.json',
                1,
                null,
                'runtime-mismatch',
            );
        }

        $line = preg_replace('/^(\d+\.\d+).*$/', '$1', $runtime) ?? $runtime;
        $state = $context->policy->tryStateOf('php', $line, $context->today);

        return match ($state) {
            null => new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf('php runtime %s: the policy does not list php %s, so its support cannot be graded', $runtime, $line),
                null,
                1,
                null,
                'runtime',
            ),
            State::Active => new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                sprintf('php runtime %s is active', $runtime),
                null,
                1,
                $state,
                'runtime',
            ),
            State::Security => new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                str_replace(
                    'php floor ' . $line,
                    'php runtime ' . $runtime,
                    $this->securityOnlyMessage($line, $context),
                ),
                null,
                1,
                $state,
                'runtime',
            ),
            default => new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf('php runtime %s is %s; move the app to a supported PHP', $runtime, $state->value),
                null,
                1,
                $state,
                'runtime',
            ),
        };
    }

    /**
     * Reads and decodes the app's composer.json, or null when the app has no
     * composer.json at all (a raw PHP app gets a verdict, not a tool error).
     *
     * The CheckRunner has already validated the file when it is present, so
     * this only throws if the file changed between the two reads.
     *
     * @return array<string, mixed>|null
     *
     * @throws ToolError
     */
    private function readComposerJson(string $appPath): ?array
    {
        $file = $appPath . '/composer.json';
        if (!is_file($file)) {
            return null;
        }
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
     * the same midnight-to-midnight comparison the policy reader uses for
     * its state: today == security_until is the last day ("ends today"), the
     * day before has 1 day left.
     */
    private function securityOnlyMessage(string $floor, CheckContext $context): string
    {
        $until = $context->policy->securityUntil('php', $floor);
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
