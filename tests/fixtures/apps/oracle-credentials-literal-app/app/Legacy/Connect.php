<?php

namespace Fixture\Legacy;

class Connect
{
    /** Flagged: the password is written into the call. */
    public function reports(object $dsn): mixed
    {
        return oci_connect('report_ro', 'not-a-real-password', $dsn);
    }

    /** Not flagged: the password comes from the environment or a variable,
     * is empty, or the call is a method named like the OCI function. */
    public function safeReads(object $dsn, string $password, object $x): array
    {
        $fromEnv = oci_connect('report_ro', getenv('TELLER_PASSWORD'), $dsn);
        $byVariable = oci_connect('report_ro', $password, $dsn);
        $empty = oci_connect('report_ro', '', $dsn);
        $method = $x->oci_connect('a', 'b');
        return [$fromEnv, $byVariable, $empty, $method];
    }

    /** Flagged: the connect string carries the password. */
    public function dsn(): string
    {
        return 'report_ro/not-a-real-password@db.example.invalid:1521/REPORTS';
    }

    /** Not flagged: a plain path is not a connect string. */
    public function readme(): string
    {
        return 'docs/readme.md';
    }
}
