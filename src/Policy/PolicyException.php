<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Policy;

use RuntimeException;

/**
 * Thrown when a policy file is unreadable or malformed, or when a version
 * is looked up that a valid policy does not contain.
 */
class PolicyException extends RuntimeException
{
}
