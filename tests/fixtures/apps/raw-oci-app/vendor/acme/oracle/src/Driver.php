<?php

namespace Acme\Oracle;

class Driver
{
    public function connect(): mixed
    {
        return oci_connect('app_ro', 'not-a-password', '//db.example.invalid/XE');
    }
}
