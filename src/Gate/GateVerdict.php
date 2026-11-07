<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Gate;

use DateTimeImmutable;
use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\Policy\ResolvedPolicy;

/**
 * The result of one `proctor gate` run, ready for the GateWriter.
 *
 * Go when nothing is blocked; no-go otherwise. Value object.
 */
final class GateVerdict
{
    public const GO = 'go';

    public const NO_GO = 'no-go';

    /**
     * @param string             $path      the --path value, as given
     * @param list<string>       $checkIds  the checks the gate ran, in order
     * @param list<GateDecision> $decisions one per kept finding, in check order
     * @param list<Override>     $overrides the app's policy-overrides.json entries
     */
    public function __construct(
        public readonly string $path,
        public readonly DateTimeImmutable $today,
        public readonly ResolvedPolicy $resolvedPolicy,
        public readonly array $checkIds,
        public readonly ?Baseline $baseline,
        public readonly array $decisions,
        public readonly array $overrides,
    ) {}

    public function verdict(): string
    {
        return 0 === $this->count(GateDecision::BLOCKED) ? self::GO : self::NO_GO;
    }

    public function exitCode(): int
    {
        return self::GO === $this->verdict() ? Gate::EXIT_GO : Gate::EXIT_NO_GO;
    }

    /**
     * The number of decisions with the given outcome.
     */
    public function count(string $outcome): int
    {
        $count = 0;
        foreach ($this->decisions as $decision) {
            if ($decision->outcome === $outcome) {
                $count++;
            }
        }

        return $count;
    }
}
