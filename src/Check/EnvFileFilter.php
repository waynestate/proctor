<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

/**
 * Decides whether a check may read one of the app's env files.
 *
 * `proctor check` and `proctor gate` pass none, so every check reads its env
 * files from disk as it always has. ADVISOR's in-process `proctor_check`
 * passes one that allows only the files git lists, so a developer's local
 * `.env.production` is never opened there. No peeking at a paper that was
 * never handed in.
 */
interface EnvFileFilter
{
    /**
     * Whether the check may read the env file.
     *
     * @param string $checkId      the id of the check that asks
     * @param string $relativePath the file, relative to the app path, with forward slashes
     */
    public function allows(string $checkId, string $relativePath): bool;
}
