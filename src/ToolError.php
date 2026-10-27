<?php

declare(strict_types=1);

namespace Waynestate\Proctor;

use RuntimeException;

/**
 * A tool error: the run cannot proceed (bad arguments, unreadable or
 * malformed input). The CLI reports the message on stderr and exits 2.
 */
class ToolError extends RuntimeException
{
}
