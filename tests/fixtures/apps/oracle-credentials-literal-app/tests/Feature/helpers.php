<?php

// A fixture that writes an Oracle password into tests/. PROCTOR skips the
// tests/ directory, so this call must never surface as a finding. The file
// is not named *Test.php on purpose: PROCTOR's own PHPUnit walks tests/ too,
// and this file is not sitting that exam.

namespace Fixture\Tests\Feature;

function sampleConnection(object $dsn): mixed
{
    return oci_connect('report_ro', 'not-a-real-password', $dsn);
}
