<?php

function env(string $name): string
{
    return (string) getenv($name);
}

echo env('HOME');
