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
 * The proctor seats every script next to its twin before the exam starts.
 */
final class ScriptPairCheck implements CheckInterface
{
    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

    public function id(): string
    {
        return 'script-pair';
    }

    public function run(CheckContext $context): array
    {
        $files = $this->scripts($context->appPath);

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
                $findings[] = $this->missingTwin($relativePath, $twin, $rule);
            }
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'Every developer script in scripts/ ships as a .ps1 and .sh pair.',
            )];
        }

        return $findings;
    }

    /**
     * Every .sh or .ps1 file (case-sensitive) at any depth under the
     * top-level scripts/ directory, as app-relative paths with forward
     * slashes, sorted. vendor/, node_modules/ and .git/ are skipped at any
     * depth; nothing outside scripts/ is read.
     *
     * @return list<string>
     */
    private function scripts(string $appPath): array
    {
        $appPath = rtrim($appPath, '/\\');
        $inner = new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS);
        $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current): bool {
            return !($current->isDir() && in_array($current->getBasename(), self::SKIP_DIRS, true));
        });
        $iterator = new RecursiveIteratorIterator($outer, RecursiveIteratorIterator::LEAVES_ONLY);

        $files = [];
        foreach ($iterator as $file) {
            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($appPath) + 1));
            $segments = explode('/', $relativePath);
            if ($segments[0] !== 'scripts' || 1 === count($segments)) {
                continue;
            }
            $basename = $file->getBasename();
            if (str_ends_with($basename, '.sh') || str_ends_with($basename, '.ps1')) {
                $files[] = $relativePath;
            }
        }

        sort($files);

        return $files;
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
