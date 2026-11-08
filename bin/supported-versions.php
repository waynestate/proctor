<?php

declare(strict_types=1);

/*
 * supported-versions.php: writes the supported-versions table on the front
 * page from the bundled CREED policy (src/Creed/policy.json), so the dates a
 * visitor reads can never drift from the policy the checks grade against.
 *
 * The table lives between the two marker lines in site/index.html:
 *   <!-- supported-versions:start -->
 *   <!-- supported-versions:end -->
 * This script rewrites exactly that region; the markers stay.
 *
 * Usage:
 *   php bin/supported-versions.php           rewrite the region when it changed
 *   php bin/supported-versions.php --check   write nothing; exit 1 when stale
 *
 * Exit codes: 0 current or updated, 1 the region is stale, 2 the tool could
 * not run (missing markers, missing or invalid policy). "Today" is the
 * policy's own refreshed_on date, never the clock, so the output is
 * deterministic. Plain PHP 8.3, no Composer packages, so it runs on Windows,
 * macOS and Linux the same way.
 */

final class SupportedVersionsError extends RuntimeException {}

/** The components the table shows, in order, with their row labels. Inertia
 *  is left out: its versions carry no dates to grade. */
const SUPPORTED_VERSIONS_COMPONENTS = [
    'php' => 'PHP',
    'laravel' => 'Laravel',
    'node' => 'Node.js',
    'mysql' => 'MySQL',
    'vue' => 'Vue',
];

const SUPPORTED_VERSIONS_START = '<!-- supported-versions:start -->';
const SUPPORTED_VERSIONS_END = '<!-- supported-versions:end -->';

/** Escape text for the generated HTML. */
function sv_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return array{0: non-empty-string, 1: string} the status label and mark class */
function sv_status(string $activeUntil, string $securityUntil, string $refreshedOn): array
{
    if ($activeUntil !== '' && $activeUntil >= $refreshedOn) {
        return ['Supported', 'pass'];
    }
    if ($securityUntil !== '' && $securityUntil >= $refreshedOn) {
        return ['Security fixes only', 'warn'];
    }

    return ['End of life', 'fail'];
}

/**
 * Build the fragment between the marker lines, one line per element.
 *
 * @param array<string, mixed> $policy the decoded policy
 * @return list<string>
 */
function sv_fragment(array $policy): array
{
    $refreshedOn = $policy['refreshed_on'];
    $components = $policy['components'];
    $rows = [];

    foreach (SUPPORTED_VERSIONS_COMPONENTS as $key => $label) {
        if (! isset($components[$key]['versions']) || ! is_array($components[$key]['versions'])) {
            continue;
        }
        foreach ($components[$key]['versions'] as $version => $info) {
            if (! is_array($info)) {
                continue;
            }
            $active = isset($info['active_until']) && is_string($info['active_until']) ? $info['active_until'] : '';
            $security = isset($info['security_until']) && is_string($info['security_until']) ? $info['security_until'] : '';
            // A version without any dates cannot be graded (that is why inertia
            // is left out of the table), so it gets no row.
            if ($active === '' && $security === '') {
                continue;
            }
            [$status, $mark] = sv_status($active, $security, $refreshedOn);
            $rows[] = sprintf(
                '%s<tr><th scope="row">%s</th><td>%s</td><td>%s</td><td>%s</td><td><span class="mark mark--%s">%s</span></td></tr>',
                str_repeat(' ', 12),
                sv_escape($label),
                sv_escape((string) $version),
                sv_escape($active),
                sv_escape($security),
                $mark,
                sv_escape($status),
            );
        }
    }

    return [
        '  <section class="sheet-sec" id="versions" aria-labelledby="versions-title">',
        '    <div class="wrap">',
        '      <header class="sec-head">',
        '        <p class="sec-head__part">Set texts</p>',
        '        <h2 id="versions-title">The versions PROCTOR marks against</h2>',
        sprintf(
            '        <p>From the policy bundled with this release (refreshed %s). PROCTOR fails a version past end of life and warns on one that only gets security fixes.</p>',
            sv_escape($refreshedOn),
        ),
        '      </header>',
        '      <div class="versions-wrap" tabindex="0" role="region" aria-labelledby="versions-title">',
        '        <table class="versions">',
        '          <thead>',
        '            <tr><th scope="col">Component</th><th scope="col">Version</th><th scope="col">Active support until</th><th scope="col">Security fixes until</th><th scope="col">Status</th></tr>',
        '          </thead>',
        '          <tbody>',
        ...$rows,
        '          </tbody>',
        '        </table>',
        '      </div>',
        '    </div>',
        '  </section>',
    ];
}

/**
 * Load and validate the bundled policy.
 *
 * @return array{refreshed_on: string, components: array<string, mixed>}
 */
function sv_load_policy(string $path): array
{
    if (! is_file($path)) {
        throw new SupportedVersionsError("policy file src/Creed/policy.json is missing ({$path})");
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new SupportedVersionsError("policy file src/Creed/policy.json could not be read");
    }
    $policy = json_decode($raw, true);
    if (! is_array($policy)) {
        throw new SupportedVersionsError('policy file src/Creed/policy.json is not valid JSON');
    }
    if (! isset($policy['refreshed_on']) || ! is_string($policy['refreshed_on']) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $policy['refreshed_on']) !== 1) {
        throw new SupportedVersionsError('policy file src/Creed/policy.json has no usable refreshed_on date');
    }
    if (! isset($policy['components']) || ! is_array($policy['components'])) {
        throw new SupportedVersionsError('policy file src/Creed/policy.json has no components');
    }

    return [
        'refreshed_on' => $policy['refreshed_on'],
        'components' => $policy['components'],
    ];
}

/**
 * Split the page into the lines before the start marker, the region between
 * the markers and the lines from the end marker on.
 *
 * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3: string}
 */
function sv_split_page(string $html): array
{
    $eol = str_contains($html, "\r\n") ? "\r\n" : "\n";
    $lines = array_map(static fn (string $line): string => rtrim($line, "\r"), explode("\n", $html));
    $start = null;
    $end = null;
    foreach ($lines as $i => $line) {
        $trimmed = trim($line);
        if ($trimmed === SUPPORTED_VERSIONS_START && $start === null) {
            $start = $i;
        } elseif ($trimmed === SUPPORTED_VERSIONS_END && $end === null) {
            $end = $i;
        }
    }
    if ($start === null || $end === null || $end <= $start) {
        throw new SupportedVersionsError('site/index.html is missing the supported-versions marker lines');
    }

    return [
        array_slice($lines, 0, $start + 1),
        array_slice($lines, $start + 1, $end - $start - 1),
        array_slice($lines, $end),
        $eol,
    ];
}

/** @param list<string> $argv */
function sv_main(array $argv): int
{
    $check = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check') {
            $check = true;
        } else {
            fwrite(STDOUT, "supported-versions: unknown argument {$arg}; use no argument or --check\n");

            return 2;
        }
    }

    try {
        $root = str_replace('\\', '/', dirname(__DIR__));
        $policy = sv_load_policy($root.'/src/Creed/policy.json');
        $fragment = sv_fragment($policy);

        $pagePath = $root.'/site/index.html';
        if (! is_file($pagePath)) {
            throw new SupportedVersionsError('site/index.html is missing');
        }
        $pageRaw = file_get_contents($pagePath);
        if ($pageRaw === false) {
            throw new SupportedVersionsError('site/index.html could not be read');
        }
        [$before, $current, $after, $eol] = sv_split_page($pageRaw);

        if ($current === $fragment) {
            if ($check) {
                fwrite(STDOUT, "supported-versions: current\n");

                return 0;
            }
            fwrite(STDOUT, "supported-versions: site/index.html already current\n");

            return 0;
        }
        if ($check) {
            fwrite(STDOUT, "supported-versions: site/index.html is stale; run composer site:versions\n");

            return 1;
        }
        if (file_put_contents($pagePath, implode($eol, [...$before, ...$fragment, ...$after])) === false) {
            throw new SupportedVersionsError('site/index.html could not be written');
        }
        fwrite(STDOUT, "supported-versions: site/index.html updated\n");

        return 0;
    } catch (SupportedVersionsError $e) {
        fwrite(STDOUT, 'supported-versions: '.$e->getMessage()."\n");

        return 2;
    }
}

exit(sv_main($_SERVER['argv'] ?? []));
