<?php

namespace Acme\Oracle;

class Driver
{
    /** Reads arrive through TELLER's named queries; the driver never opens Oracle itself. */
    public function connect(object $teller): object
    {
        return $teller;
    }
}
