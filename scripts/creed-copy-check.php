<?php

declare(strict_types=1);

/**
 * Verify that the generated CREED reader copy in src/Creed/ still matches a
 * fresh export from the CREED checkout.
 *
 * The CREED checkout is located with the CREED_PATH environment variable
 * (default: the sibling ../creed checkout). The drift detection itself is done
 * by CREED's own bin/export.php in --check mode, so the comparison logic lives
 * in CREED, not here. This script only finds the checkout and runs it, then
 * passes the exit code straight through.
 *
 * Usage:
 *   composer creed-copy:check
 *   CREED_PATH=/path/to/creed composer creed-copy:check
 *
 * Exit codes mirror CREED's export.php:
 *   0  the copy matches a fresh export
 *   1  the copy has drifted (a file differs, is missing, or is extra)
 *   2  CREED_PATH is missing or has no bin/export.php
 */

$repoRoot = dirname(__DIR__);
$defaultCreedPath = dirname($repoRoot) . '/creed';

$creedPath = getenv('CREED_PATH');
if ($creedPath === false || $creedPath === '') {
    $creedPath = $defaultCreedPath;
}

$export = $creedPath . '/bin/export.php';
if (!is_file($export)) {
    fwrite(STDERR, "creed-copy:check: cannot find the CREED export at {$export}\n");
    fwrite(STDERR, "Set CREED_PATH to the CREED checkout (default: {$defaultCreedPath}).\n");
    exit(2);
}

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pipes = [];
$process = proc_open(
    [PHP_BINARY, $export, '--check', '--namespace=Waynestate\Proctor\Creed', '--to=' . $repoRoot . '/src/Creed'],
    $descriptors,
    $pipes,
    $repoRoot,
);
if (!is_resource($process)) {
    fwrite(STDERR, "creed-copy:check: could not start the CREED export\n");
    exit(2);
}
fclose($pipes[0]);
// The export writes either an all-clear (stdout) or a list of problems
// (stderr), never both in volume, so draining one pipe then the other cannot
// deadlock. Stream each straight through to the matching parent handle.
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
if ($stdout !== false && $stdout !== '') {
    fwrite(STDOUT, $stdout);
}
if ($stderr !== false && $stderr !== '') {
    fwrite(STDERR, $stderr);
}
exit($exit);
