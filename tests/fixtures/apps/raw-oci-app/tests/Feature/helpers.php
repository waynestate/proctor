<?php

// A fixture that opens an Oracle connection from inside tests/. PROCTOR skips
// the tests/ directory, so this call must never surface as a finding. The
// file is not named *Test.php on purpose: PROCTOR's own PHPUnit walks tests/
// too, and this file is not sitting that exam.

namespace Fixture\Tests\Feature;

function openConnection(): mixed
{
    return oci_connect('app_ro', 'not-a-password', '//db.example.invalid/XE');
}
