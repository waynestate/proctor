<?php

// A fixture that builds SQL from a PHP value inside tests/. PROCTOR skips
// the tests/ directory, so this call must never surface as a finding. The
// file is not named *Test.php on purpose: PROCTOR's own PHPUnit walks tests/
// too, and this file is not sitting that exam.

namespace Fixture\Tests\Feature;

function sampleReport(object $conn, string $term): mixed
{
    return oci_parse($conn, "SELECT NAME FROM PEOPLE_V WHERE TERM = $term");
}
