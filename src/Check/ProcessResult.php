<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

/**
 * The outcome of running an external process.
 *
 * Value object: immutable, no behavior.
 */
final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {}

    /**
     * The first line of stderr, trimmed, or '' when stderr is empty.
     */
    public function firstStderrLine(): string
    {
        $lines = preg_split('/\R/', $this->stderr) ?: [];

        return trim($lines[0] ?? '');
    }
}
