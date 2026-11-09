<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Advisor;

use Waynestate\Proctor\Check\EnvFileFilter;
use Waynestate\Proctor\Code\GitIndex;

/**
 * The env file rule for ADVISOR's in-process run: a check reads an env file
 * only when git lists it, the rule app-key and vite-secrets follow in a full
 * run. Git's index is read in PHP (GitIndex), so nothing is started.
 *
 * Every file it turns away is kept, with the check that asked and the
 * reason, so the answer can say what was not read. A developer's own
 * `.env.production` stays in the bag, unopened; the proctor only notes that
 * it is there.
 */
final class TrackedEnvFiles implements EnvFileFilter
{
    /** The file is on disk, but git's index does not list it. */
    public const REASON_UNTRACKED = 'not tracked by git';

    /** There is no git index to read (no repository, or a shape GitIndex does not know). */
    public const REASON_NO_INDEX = 'no git index to read';

    /** @var array<string, true>|null|false false until the index is read */
    private array|false|null $tracked = false;

    /** @var list<array{id: string, file: string, reason: string}> */
    private array $refused = [];

    public function __construct(private readonly string $appPath) {}

    public function allows(string $checkId, string $relativePath): bool
    {
        if ($this->tracked === false) {
            $this->tracked = GitIndex::trackedUnder($this->appPath);
        }
        if ($this->tracked !== null && isset($this->tracked[$relativePath])) {
            return true;
        }

        $this->refused[] = [
            'id' => $checkId,
            'file' => $relativePath,
            'reason' => $this->tracked === null ? self::REASON_NO_INDEX : self::REASON_UNTRACKED,
        ];

        return false;
    }

    /**
     * The env files a check asked for and did not read, in the order asked.
     *
     * @return list<array{id: string, file: string, reason: string}>
     */
    public function refused(): array
    {
        return $this->refused;
    }
}
