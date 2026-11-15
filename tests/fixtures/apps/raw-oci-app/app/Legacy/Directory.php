<?php

namespace Fixture\Legacy;

class Directory
{
    public function people(): array
    {
        // The oci_parse call below is the old way; TELLER's named queries replace it.
        $conn = oci_connect('app_ro', 'not-a-password', '//db.example.invalid/XE');
        $legacy = ocilogon('app_ro', 'not-a-password', '//db.example.invalid/XE');
        $stmt = oci_parse($conn, 'SELECT * FROM DIRECTORY WHERE ACTIVE = 1');
        $note = 'the legacy reader still calls oci_parse by hand';
        fwrite(STDOUT, $note);
        $again = $conn->oci_parse('SELECT 1 FROM DUAL');
        return [$conn, $legacy, $stmt, $again];
    }

    public function modern(): \PDO
    {
        return new \PDO('oci:dbname=//db.example.invalid/XE', 'app_ro', 'not-a-password');
    }
}
