<?php

namespace Fixture\Legacy;

class Directory
{
    /** People arrive through TELLER's named queries; the app never opens Oracle itself. */
    public function people(object $teller): array
    {
        return $teller->directoryPeople();
    }
}
