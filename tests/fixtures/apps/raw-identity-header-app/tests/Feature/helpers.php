<?php

// A fixture that reads an identity header from inside tests/. PROCTOR skips
// the tests/ directory, so this read must never surface as a finding.

namespace Fixture\Tests\Feature;

function probeUser(): string
{
    return $_SERVER['REMOTE_USER'];
}
