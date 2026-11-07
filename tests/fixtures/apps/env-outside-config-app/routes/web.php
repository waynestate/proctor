<?php

use function App\Support\env as appEnv;

$debug = \env('APP_DEBUG', false);
$own = appEnv('NOT_LARAVEL');

// The closure this stands in for runs before config is loaded.
// @phpstan-ignore larastan.noEnvCallsOutsideOfConfig (reviewed: runs before config)
$proxies = env('TRUSTED_PROXIES');
