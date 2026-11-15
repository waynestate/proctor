<?php

namespace Fixture\Legacy;

use Illuminate\Support\Facades\DB;

class Reports
{
    /** Every read binds its values; the SQL text is always a literal. */
    public function person(object $conn, int $id): mixed
    {
        return oci_parse($conn, 'SELECT NAME FROM PEOPLE_V WHERE ID = :id');
    }

    public function peopleByTerm(string $term): array
    {
        return DB::select('SELECT NAME FROM PEOPLE_V WHERE TERM = :term', ['term' => $term]);
    }
}
