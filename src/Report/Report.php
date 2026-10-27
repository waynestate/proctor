<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Report;

use DateTimeImmutable;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Override\Override;

/**
 * The result of one `proctor check` run, ready for a ReportWriter.
 *
 * Value object: immutable. The findings are already in check order with the
 * overrides applied (fail -> warning, except eol_grace_expired). The
 * overrides list is the app's raw policy-overrides.json entries; a writer
 * computes each entry's "expired" flag from today.
 */
final class Report
{
    public const TOOL = 'proctor';

    public const VERSION = '0.1.0';

    public const EXIT_PASS = 0;

    public const EXIT_FAIL = 1;

    /**
     * @param string         $path      the --path value, as given
     * @param list<Finding>  $findings  the findings in check order
     * @param list<Override> $overrides the app's policy-overrides.json entries
     * @param int            $exitCode  EXIT_PASS or EXIT_FAIL
     */
    public function __construct(
        public readonly string $path,
        public readonly DateTimeImmutable $today,
        public readonly array $findings,
        public readonly array $overrides,
        public readonly int $exitCode,
    ) {
        if (!in_array($this->exitCode, [self::EXIT_PASS, self::EXIT_FAIL], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Report exit code must be %d or %d; got %d.',
                self::EXIT_PASS,
                self::EXIT_FAIL,
                $this->exitCode
            ));
        }
    }

    /**
     * "pass" or "fail", for the report output.
     */
    public function result(): string
    {
        return self::EXIT_FAIL === $this->exitCode ? 'fail' : 'pass';
    }

    /**
     * The number of findings with the given status.
     */
    public function countByStatus(string $status): int
    {
        $count = 0;
        foreach ($this->findings as $finding) {
            if ($finding->status === $status) {
                $count++;
            }
        }

        return $count;
    }
}
