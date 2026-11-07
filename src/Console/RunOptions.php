<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Console;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Proctor\Policy\CiGuard;
use Waynestate\Proctor\Policy\PolicyResolver;
use Waynestate\Proctor\Policy\ResolvedPolicy;
use Waynestate\Proctor\ToolError;

/**
 * The options `proctor check` and `proctor gate` share, read and validated
 * once: --path, --policy, --today, --php-runtime and --timeout.
 *
 * Every problem is a ToolError with the message the CLI prints before it
 * exits 2. The order matches what `check` always did: the CI guard first,
 * then the policy, then the date, the timeout and the PHP runtime. Two
 * commands, one seating plan.
 */
final class RunOptions
{
    private function __construct(
        public readonly string $appPath,
        public readonly ResolvedPolicy $resolved,
        public readonly DateTimeImmutable $today,
        public readonly int $timeout,
        public readonly ?string $phpRuntime,
    ) {}

    /**
     * @param OutputInterface $errorOutput where policy-resolution notes go (stderr)
     *
     * @throws ToolError naming the option that is wrong
     */
    public static function fromInput(InputInterface $input, OutputInterface $errorOutput): self
    {
        $appPath = (string) $input->getOption('path');
        $policyOption = self::stringOrNull($input->getOption('policy'));
        $todayOption = self::stringOrNull($input->getOption('today'));

        // In CI the policy and the date must come from the stack, not from the
        // app being checked, so the options are refused unless a trusted job
        // allows them (PROCTOR_ALLOW_OVERRIDES=1).
        $refusal = CiGuard::refusal($policyOption, $todayOption);
        if (null !== $refusal) {
            throw new ToolError($refusal);
        }

        try {
            $resolved = PolicyResolver::resolve(
                $policyOption,
                $appPath,
                static function (string $note) use ($errorOutput): void {
                    $errorOutput->writeln($note, OutputInterface::OUTPUT_RAW);
                },
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            throw new ToolError($e->getMessage(), 0, $e);
        }

        if (null === $todayOption) {
            $today = new DateTimeImmutable('today');
        } else {
            $today = DateTimeImmutable::createFromFormat('!Y-m-d', $todayOption);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $todayOption) || false === $today) {
                throw new ToolError(sprintf(
                    'Invalid --today value "%s". Expected format: YYYY-MM-DD.',
                    $todayOption,
                ));
            }
        }

        $timeoutOption = (string) $input->getOption('timeout');
        if (!preg_match('/^\d+$/', $timeoutOption) || (int) $timeoutOption < 1) {
            throw new ToolError(sprintf(
                'Invalid --timeout value "%s". Expected a positive integer number of seconds.',
                $timeoutOption,
            ));
        }

        $phpRuntimeOption = self::stringOrNull($input->getOption('php-runtime'));
        $phpRuntime = null;
        if (null !== $phpRuntimeOption && '' !== $phpRuntimeOption) {
            if (!preg_match('/^\d+\.\d+\.\d+$/', $phpRuntimeOption)) {
                throw new ToolError(sprintf(
                    'Invalid --php-runtime value "%s". Expected format: X.Y.Z (digits and dots only).',
                    $phpRuntimeOption,
                ));
            }
            $phpRuntime = $phpRuntimeOption;
        }

        return new self($appPath, $resolved, $today, (int) $timeoutOption, $phpRuntime);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
