<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Update;

/**
 * Fetches the Packagist p2 metadata for one package.
 *
 * This is the seam the update notice uses instead of the network: tests
 * inject a stub, production uses PackagistFetcher.
 */
interface LatestReleaseFetcher
{
    /**
     * The package entries of the p2 metadata, or null when the metadata
     * could not be fetched, read or decoded.
     *
     * @return list<array<string, mixed>>|null
     */
    public function fetch(): ?array;
}
