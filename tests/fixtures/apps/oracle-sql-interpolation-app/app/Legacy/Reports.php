<?php

namespace Fixture\Legacy;

use Illuminate\Support\Facades\DB;

class Reports
{
    /** Flagged: an interpolated string reaches Oracle through oci_parse. */
    public function person(object $conn, int $id): mixed
    {
        return oci_parse($conn, "SELECT NAME FROM PEOPLE_V WHERE ID = $id");
    }

    /** Flagged: a concatenation with a PHP value reaches Oracle through ociparse. */
    public function roster(object $conn, string $term): mixed
    {
        return ociparse($conn, 'SELECT NAME FROM PEOPLE_V WHERE TERM = ' . $term);
    }

    /** Not flagged: the SQL is a literal, a bound placeholder, a variable,
     * a literal-only concatenation, a nowdoc, a sprintf result or a method
     * call named like the OCI function. */
    public function safeReads(object $conn, int $id, string $term, string $sql): array
    {
        $literal = oci_parse($conn, 'SELECT NAME FROM PEOPLE_V WHERE ACTIVE = 1');
        $bound = oci_parse($conn, 'SELECT NAME FROM PEOPLE_V WHERE ID = :id');
        $byVariable = ociparse($conn, $sql);
        $pieces = oci_parse($conn, 'SELECT NAME FROM ' . 'PEOPLE_V');
        $nowdoc = oci_parse($conn, <<<SQL
            SELECT NAME FROM PEOPLE_V WHERE TERM = 'X'
            SQL);
        $formatted = oci_parse($conn, sprintf('SELECT NAME FROM PEOPLE_V WHERE ID = %d', $id));
        $method = $conn->oci_parse("SELECT NAME FROM PEOPLE_V WHERE TERM = $term");
        return [$literal, $bound, $byVariable, $pieces, $nowdoc, $formatted, $method];
    }

    /** Flagged: on a yajra app the DB facade sends an interpolated string to Oracle. */
    public function peopleByTerm(string $term): array
    {
        return DB::select("SELECT NAME FROM PEOPLE_V WHERE TERM = {$term}");
    }

    /** Not flagged: the facade takes a literal, a variable or a query builder chain. */
    public function facadeSafe(string $sql): array
    {
        $literal = DB::select('SELECT NAME FROM PEOPLE_V WHERE ACTIVE = 1');
        $byVariable = DB::select($sql);
        $builder = DB::table('people_v')->where('active', 1)->get();
        return [$literal, $byVariable, $builder];
    }
}
