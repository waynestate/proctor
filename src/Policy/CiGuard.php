<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Policy;

/**
 * Refuses --policy and --today in CI unless a trusted job allows them.
 *
 * In CI the policy and "today" must come from the stack, not from the app
 * being checked: a pipeline that could pass its own policy file or date in
 * its own .gitlab-ci.yml could grade itself. A trusted job (a protected or
 * group-level CI variable, never the app's .gitlab-ci.yml) sets
 * PROCTOR_ALLOW_OVERRIDES to exactly 1 to allow the options. The
 * PROCTOR_POLICY environment variable stays allowed in CI; it is how a
 * trusted job names a policy.
 */
final class CiGuard
{
    /** The environment variables whose value marks a run as happening in CI. */
    private const CI_VARS = ['CI', 'GITLAB_CI', 'GITHUB_ACTIONS'];

    /** The variable a trusted job sets to exactly 1 to allow the options. */
    private const ALLOW_OVERRIDES = 'PROCTOR_ALLOW_OVERRIDES';

    /**
     * The refusal message when the run must be refused, or null when it is
     * allowed.
     *
     * Refused when the run is in CI, one of the options was given (--policy
     * with a non-empty value, or --today at all), and PROCTOR_ALLOW_OVERRIDES
     * is not exactly 1. Reads the environment itself.
     *
     * @param string|null $policyOption the --policy value, or null when the option was not given
     * @param string|null $todayOption  the --today value, or null when the option was not given
     */
    public static function refusal(?string $policyOption, ?string $todayOption): ?string
    {
        if (!self::inCi()) {
            return null;
        }

        $policyGiven = $policyOption !== null && $policyOption !== '';
        $todayGiven = $todayOption !== null;
        if (!$policyGiven && !$todayGiven) {
            return null;
        }

        if (getenv(self::ALLOW_OVERRIDES) === '1') {
            return null;
        }

        $both = $policyGiven && $todayGiven;

        return sprintf(
            'Refused: %s %s not accepted in CI, because the app could pick its own policy or date. A trusted job (a protected or group-level CI variable, not the app\'s .gitlab-ci.yml) can set PROCTOR_ALLOW_OVERRIDES=1 to allow them.',
            $both ? '--policy and --today' : ($policyGiven ? '--policy' : '--today'),
            $both ? 'are' : 'is',
        );
    }

    /**
     * Whether the run happens in CI: any of CI, GITLAB_CI or GITHUB_ACTIONS
     * is set to a non-empty value other than 0 or false (case-insensitive).
     */
    private static function inCi(): bool
    {
        foreach (self::CI_VARS as $name) {
            $value = getenv($name);
            if (!is_string($value) || $value === '') {
                continue;
            }
            $lower = strtolower($value);
            if ($lower === '0' || $lower === 'false') {
                continue;
            }

            return true;
        }

        return false;
    }
}
