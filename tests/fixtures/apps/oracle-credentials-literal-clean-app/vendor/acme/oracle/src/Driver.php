<?php

namespace Acme\Oracle;

class Driver
{
    public function open(object $dsn): mixed
    {
        return oci_connect('report_ro', 'not-a-real-password', $dsn);
    }
}
