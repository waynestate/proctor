<?php

namespace App\Support;

function prepare(string $path): void
{
    mkdir($path, 0777, true);
    chmod($path, 0755);
    chmod($path, 0777);
}
