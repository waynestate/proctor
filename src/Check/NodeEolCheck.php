<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Composer\Semver\VersionParser;
use Waynestate\Proctor\Code\RepoFiles;
use Waynestate\Proctor\Creed\State;

/**
 * Grades every Node version the repository names against the policy.
 *
 * A pinned version is one the build actually runs: `.nvmrc`, `.node-version`,
 * package.json `volta.node`, an official `node` image in `.gitlab-ci.yml`
 * (`image:` or `name:`), and an official `node` image in a Dockerfile `FROM`
 * line. Each one is graded on its own through the CREED state table: active
 * passes, security-only warns with the days left, eol fails, and past the
 * grace window it fails as eol_grace_expired, which no override can soften.
 *
 * package.json `engines.node` is a declaration, not what runs, so its lowest
 * allowed major is graded the same way but never fails: an eol floor warns
 * (`proctor/node-eol/engines`). An odd major is never an LTS line and never a
 * target in CREED's versions chapter, so it warns (`proctor/node-eol/odd-major`)
 * wherever it is named.
 *
 * Silent when the policy has no node component, when the repository names no
 * Node version, and for names the reader cannot grade: aliases such as
 * `lts/*` or `node`, image tags such as `lts-alpine`, and ranges that do not
 * parse. The reader does not guess.
 *
 * The proctor checks which calculator is on the desk, not only the one the
 * syllabus allows.
 */
final class NodeEolCheck implements CheckInterface
{
    /** Files whose first meaningful line is a Node version. */
    private const VERSION_FILES = ['.nvmrc', '.node-version'];

    /**
     * An official node image reference: an optional registry host (with a
     * dot or a port) or a CI variable, an optional library/ segment, then
     * node with an optional tag and digest. Another org's image named node
     * (someorg/node) carries its own versions and is not graded.
     */
    private const IMAGE_PATTERN = '(?:(?:\$\{?\w+\}?|[\w-]+(?:\.[\w-]+)+(?::\d+)?|[\w-]+:\d+)/)?(?:library/)?node(?::([\w.-]+))?(?:@sha256:[0-9a-f]+)?';

    public function id(): string
    {
        return 'node-eol';
    }

    public function run(CheckContext $context): array
    {
        if (!$context->policy->hasComponent('node')) {
            return [];
        }

        $findings = [];
        foreach ($this->pinnedVersions($context) as [$path, $line, $version]) {
            $findings[] = $this->gradePinned($path, $line, $version, $context);
        }
        $engines = $this->enginesFinding($context);
        if ($engines !== null) {
            $findings[] = $engines;
        }

        return array_values(array_filter($findings));
    }

    /**
     * Every pinned Node version: [path, line, version], in file order.
     *
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function pinnedVersions(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        $pinned = [];

        foreach (self::VERSION_FILES as $file) {
            $lines = @file($appPath . '/' . $file, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $index => $raw) {
                $value = trim((string) $raw);
                if ($value === '' || str_starts_with($value, '#')) {
                    continue;
                }
                $version = $this->plainVersion($value);
                if ($version !== null) {
                    $pinned[] = [$file, $index + 1, $version];
                }
                break;
            }
        }

        $package = $this->packageJson($appPath);
        $volta = $package['volta']['node'] ?? null;
        if (is_string($volta)) {
            $version = $this->plainVersion($volta);
            if ($version !== null) {
                $pinned[] = ['package.json', $this->lineOf($appPath . '/package.json', '"node"'), $version];
            }
        }

        $ci = @file($appPath . '/.gitlab-ci.yml', FILE_IGNORE_NEW_LINES);
        if ($ci !== false) {
            $pattern = '~^\s*(?:-\s*)?(?:image|name)\s*:\s*["\']?' . self::IMAGE_PATTERN . '["\']?\s*(?:#.*)?$~';
            foreach ($ci as $index => $raw) {
                if (preg_match($pattern, (string) $raw, $m) === 1) {
                    $version = $this->tagVersion($m[1] ?? '');
                    if ($version !== null) {
                        $pinned[] = ['.gitlab-ci.yml', $index + 1, $version];
                    }
                }
            }
        }

        $from = '~^\s*FROM\s+(?:--platform=\S+\s+)?' . self::IMAGE_PATTERN . '(?:\s+AS\s+\S+)?\s*$~i';
        foreach (RepoFiles::list($appPath, $context->timeout)->files as $path) {
            $base = basename($path);
            if (!in_array($base, ['Dockerfile', 'Containerfile'], true)
                && !str_starts_with($base, 'Dockerfile.')
                && !str_ends_with($base, '.Dockerfile')) {
                continue;
            }
            if (str_ends_with($base, '.dockerignore')) {
                continue;
            }
            $lines = @file($appPath . '/' . $path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $index => $raw) {
                if (preg_match($from, (string) $raw, $m) === 1) {
                    $version = $this->tagVersion($m[1] ?? '');
                    if ($version !== null) {
                        $pinned[] = [$path, $index + 1, $version];
                    }
                }
            }
        }

        return $pinned;
    }

    /**
     * The finding for one pinned version, or null when the reader cannot
     * grade it.
     */
    private function gradePinned(string $path, int $line, string $version, CheckContext $context): ?Finding
    {
        $major = (int) explode('.', $version)[0];
        $subject = sprintf('node %s (%s)', $version, $path);
        if ($major % 2 === 1) {
            return new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                $subject . ' is an odd major: odd majors are never LTS and are not a target; use an even LTS line',
                $path,
                $line,
                null,
                'odd-major',
            );
        }

        $state = $context->policy->tryStateOf('node', $version, $context->today);
        if ($state === null) {
            return null;
        }
        $status = match ($state) {
            State::Active => Finding::STATUS_PASS,
            State::Security => Finding::STATUS_WARNING,
            default => Finding::STATUS_FAIL,
        };

        return new Finding(
            $this->id(),
            $status,
            $subject . ' ' . $this->describe($version, $state, $context),
            $path,
            $line,
            $state,
        );
    }

    /**
     * The engines.node finding: the lowest major the range allows, graded
     * like a pinned version but capped at a warning. Null when package.json
     * has no engines.node or the range does not parse.
     */
    private function enginesFinding(CheckContext $context): ?Finding
    {
        $appPath = rtrim($context->appPath, '/\\');
        $range = $this->packageJson($appPath)['engines']['node'] ?? null;
        if (!is_string($range) || trim($range) === '') {
            return null;
        }

        try {
            $lower = (new VersionParser())->parseConstraints(trim($range))->getLowerBound();
        } catch (\UnexpectedValueException) {
            return null;
        }
        $major = $lower->isZero() ? 0 : (int) explode('.', $lower->getVersion())[0];
        $line = $this->lineOf($appPath . '/package.json', '"engines"');
        $subject = sprintf('engines.node "%s" (package.json) allows node %d', trim($range), $major);

        if ($major % 2 === 1) {
            return new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                $subject . ', an odd major: odd majors are never LTS and are not a target; start the range at an even LTS line',
                'package.json',
                $line,
                null,
                'odd-major',
            );
        }

        $state = $context->policy->tryStateOf('node', (string) $major, $context->today);
        if ($state === null) {
            return null;
        }

        return new Finding(
            $this->id(),
            $state === State::Active ? Finding::STATUS_PASS : Finding::STATUS_WARNING,
            $subject . ', which ' . $this->describe((string) $major, $state, $context),
            'package.json',
            $line,
            null,
            'engines',
        );
    }

    /**
     * "is active until DATE", "is in security-only support until DATE (N
     * days left)", "is eol (security support ended DATE)", or the same
     * without a date when the version has no line.
     */
    private function describe(string $version, State $state, CheckContext $context): string
    {
        $line = $context->policy->resolveVersion('node', $version);
        if ($state === State::Active) {
            $until = $line === null ? null : $context->policy->activeUntil('node', $line);

            return $until === null ? 'is active' : 'is active until ' . $until->format('Y-m-d');
        }

        $until = $line === null ? null : $context->policy->securityUntil('node', $line);
        if ($state === State::Security) {
            if ($until === null) {
                return 'is in security-only support';
            }
            $days = (int) $context->today->diff($until)->format('%a');
            $left = match (true) {
                $days === 0 => 'ends today',
                $days === 1 => '1 day left',
                default => sprintf('%d days left', $days),
            };

            return sprintf('is in security-only support until %s (%s)', $until->format('Y-m-d'), $left);
        }

        if ($until === null) {
            $reason = $context->policy->belowOldestLineReason('node', $version);

            return sprintf('is %s', $state->value) . ($reason === null ? '' : ' (' . $reason . ')');
        }

        return sprintf('is %s (security support ended %s)', $state->value, $until->format('Y-m-d'));
    }

    /**
     * A plain version (22, 22.11, v22.11.0) without its leading v, or null
     * for an alias or anything else.
     */
    private function plainVersion(string $value): ?string
    {
        $value = preg_replace('/^v/i', '', trim($value)) ?? '';

        return preg_match('/^\d+(\.\d+){0,2}$/', $value) === 1 ? $value : null;
    }

    /**
     * The version in an image tag (22, 22.11.0-alpine, 22-bookworm-slim), or
     * null for a tag without one (lts, current, iron, latest, none).
     */
    private function tagVersion(string $tag): ?string
    {
        return preg_match('/^(\d+(?:\.\d+){0,2})(?:-|$)/', $tag, $m) === 1 ? $m[1] : null;
    }

    /**
     * package.json as an array, or an empty array when it is absent or not
     * valid JSON (the reader does not guess at a broken file).
     *
     * @return array<mixed>
     */
    private function packageJson(string $appPath): array
    {
        $raw = @file_get_contents($appPath . '/package.json');
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * The 1-based line of the first occurrence of $needle in the file, or 1.
     */
    private function lineOf(string $file, string $needle): int
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return 1;
        }
        foreach ($lines as $index => $line) {
            if (str_contains((string) $line, $needle)) {
                return $index + 1;
            }
        }

        return 1;
    }
}
