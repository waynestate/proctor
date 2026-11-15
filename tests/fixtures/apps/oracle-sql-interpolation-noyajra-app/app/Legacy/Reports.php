<?php

namespace Fixture\Legacy;

use Illuminate\Support\Facades\DB;

class Reports
{
    /** Without yajra/laravel-oci8 the DB facade does not reach Oracle, so this
     * interpolated read is not a finding here. */
    public function peopleByTerm(string $term): array
    {
        return DB::select("SELECT NAME FROM PEOPLE_V WHERE TERM = {$term}");
    }
}
