<?php

namespace Acme\Oracle;

class Driver
{
    public function fetch(object $conn, int $id): mixed
    {
        return oci_parse($conn, "SELECT NAME FROM PEOPLE_V WHERE ID = $id");
    }
}
