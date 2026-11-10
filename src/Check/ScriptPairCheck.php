<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Warns, never fails, when a developer script ships without its twin: a
 * .sh without its .ps1 (proctor/script-pair/missing-ps1), or a .ps1 without
 * its .sh (proctor/script-pair/missing-sh). CREED's every-os rule asks every
 * developer script to ship as a .ps1 and .sh pair that do the same thing, or
 * as one PHP command.
 *
 * Scanned: files at any depth under the top-level scripts/ directory only
 * (not deploy/, bin/ or anything else), whose name ends in .sh or .ps1
 * (case-sensitive). Skipped at any depth: vendor/, node_modules/ and .git/.
 * Pairing is by the same path minus the extension, in the same folder:
 * scripts/a11y.sh pairs with scripts/a11y.ps1. One warning per unpaired
 * script, on line 1.
 *
 * Two exemptions earn an info note instead of the warning, visible but not
 * counted: a script listed in the files object of the app's .squire.json is
 * a Squire kit file that runs on the deploy host, not a developer machine
 * (proctor/script-pair/squire-owned), and a script that carries
 * proctor-ignore script-pair: <reason> in one of its first 10 lines, such as
 * one that runs only inside a Linux CI container, is single-OS by its own
 * word (proctor/script-pair/single-os). Paired scripts are never read.
 *
 * The proctor seats every script next to its twin before the exam starts.
 */
final class ScriptPairCheck implements CheckInterface
{
    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

    /** The marker that declares a script single-OS; its reason follows. */
    private const MARKER = 'proctor-ignore script-pair:';

    /** How many leading lines the single-OS marker is honoured in. */
    private const MARKER_LINES = 10;

    public function id(): string
    {
        return 'script-pair';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        $files = $this->scripts($context->appPath);
        $squireFiles = $this->squireFiles($appPath);

        $findings = [];
        foreach ($files as $relativePath) {
            if (str_ends_with($relativePath, '.sh')) {
                $twin = substr($relativePath, 0, -3) . '.ps1';
                $rule = 'missing-ps1';
            } else {
                $twin = substr($relativePath, 0, -4) . '.sh';
                $rule = 'missing-sh';
            }
            if (!in_array($twin, $files, true)) {
                if (isset($squireFiles[$relativePath])) {
                    $findings[] = $this->squireOwnedNote($relativePath, $twin);
                } elseif (($marker = $this->singleOsMarker($appPath, $relativePath)) !== null) {
                    $findings[] = $this->singleOsNote($relativePath, $twin, $marker[0], $marker[1]);
                } else {
                    $findings[] = $this->missingTwin($relativePath, $twin, $rule);
                }
            }
        }

        // Exempted scripts are notes, not problems: they still earn the pass.
        $problems = array_filter(
            $findings,
            static fn(Finding $finding): bool => Finding::STATUS_INFO !== $finding->status,
        );
        if ($problems === []) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'Every developer script in scripts/ ships as a .ps1 and .sh pair.',
            );
        }

        return $findings;
    }

    /**
     * Every .sh or .ps1 file (case-sensitive) at any depth under the
     * top-level scripts/ directory, as app-relative paths with forward
     * slashes, sorted. vendor/, node_modules/ and .git/ are skipped at any
     * depth; the walk starts at scripts/, so nothing outside it is read.
     *
     * @return list<string>
     */
    private function scripts(string $appPath): array
    {
        $appPath = rtrim($appPath, '/\\');
        $root = $appPath . DIRECTORY_SEPARATOR . 'scripts';
        if (!is_dir($root)) {
            return [];
        }
        $inner = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current): bool {
            return !($current->isDir() && in_array($current->getBasename(), self::SKIP_DIRS, true));
        });
        $iterator = new RecursiveIteratorIterator($outer, RecursiveIteratorIterator::LEAVES_ONLY);

        $files = [];
        foreach ($iterator as $file) {
            $relativePath = 'scripts/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $basename = $file->getBasename();
            if (str_ends_with($basename, '.sh') || str_ends_with($basename, '.ps1')) {
                $files[] = $relativePath;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The app-relative paths Squire owns: the keys of the files object of
     * the app root's .squire.json, read once per run. A missing, unreadable
     * or invalid manifest is the same as no manifest.
     *
     * @return array<string, true>
     */
    private function squireFiles(string $appPath): array
    {
        $raw = @file_get_contents($appPath . '/.squire.json');
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || !is_array($data['files'] ?? null)) {
            return [];
        }

        return array_fill_keys(array_keys($data['files']), true);
    }

    /**
     * The single-OS marker in the script's first 10 lines: [line, reason]
     * for the first line that carries the marker with a reason, or null when
     * none does. The marker may sit anywhere on the line; only unpaired
     * scripts are ever read.
     *
     * @return array{0: int, 1: string}|null
     */
    private function singleOsMarker(string $appPath, string $relativePath): ?array
    {
        $lines = @file($appPath . '/' . $relativePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }
        foreach (array_slice($lines, 0, self::MARKER_LINES) as $index => $line) {
            $line = (string) $line;
            $position = strpos($line, self::MARKER);
            if ($position === false) {
                continue;
            }
            $reason = trim(substr($line, $position + strlen(self::MARKER)));
            if ($reason !== '') {
                return [$index + 1, $reason];
            }
        }

        return null;
    }

    /**
     * An info note for a script Squire owns: it runs on the deploy host, not
     * a developer machine, so it needs no twin.
     */
    private function squireOwnedNote(string $relativePath, string $twin): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_INFO,
            sprintf('%s is a Squire kit file (.squire.json) that runs on the deploy host, not a developer machine, so it needs no %s twin', $relativePath, $twin),
            $relativePath,
            1,
            null,
            'squire-owned',
        );
    }

    /**
     * An info note for a script that marked itself single-OS in its first 10
     * lines, so it needs no twin.
     */
    private function singleOsNote(string $relativePath, string $twin, int $line, string $reason): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_INFO,
            sprintf('%s is marked single-OS (%s), so it needs no %s twin', $relativePath, $reason, $twin),
            $relativePath,
            $line,
            null,
            'single-os',
        );
    }

    /**
     * A warning finding for one developer script whose twin is missing.
     */
    private function missingTwin(string $relativePath, string $twin, string $rule): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf('%s has no %s twin; a developer script ships as a .ps1 and .sh pair, or as one PHP command (CREED every-os)', $relativePath, $twin),
            $relativePath,
            1,
            null,
            $rule,
        );
    }
}
