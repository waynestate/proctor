<?php

namespace Fixture\Legacy;

class Connect
{
    /** Every password comes from the environment or a variable. */
    public function reports(string $password, object $dsn): mixed
    {
        return oci_connect('report_ro', $password, $dsn);
    }

    public function fromEnv(object $dsn): mixed
    {
        return oci_connect('report_ro', getenv('TELLER_PASSWORD'), $dsn);
    }

    /** Not flagged: the easy-connect form carries no password. */
    public function dsn(): string
    {
        return '//db.example.invalid:1521/REPORTS';
    }
}
