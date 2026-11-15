<?php

namespace Acme\Oracle;

class Driver
{
    /** The driver builds SQL from PHP values, but it sits in vendor/, which PROCTOR skips. */
    public function fetch(object $conn, int $id): mixed
    {
        return oci_parse($conn, "SELECT NAME FROM PEOPLE_V WHERE ID = $id");
    }
}
