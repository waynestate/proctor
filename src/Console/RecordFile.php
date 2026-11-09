<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Console;

use Waynestate\Proctor\ToolError;

/**
 * Writes the JSON a command's --record option asks for: `proctor gate`'s
 * verdict (the next deploy's baseline) and `proctor check`'s report (the
 * file a CI job keeps as an artifact for Rollcall). The file holds exactly
 * what --format=json prints, newline included, and its folder is created.
 * The proctor files a carbon copy of the mark sheet; it does not grade twice.
 */
final class RecordFile
{
    /**
     * @throws ToolError when the file or its folder cannot be written
     */
    public static function write(string $file, string $json): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ToolError(sprintf('Could not create the folder for --record "%s".', $file));
        }
        if (false === @file_put_contents($file, $json . "\n")) {
            throw new ToolError(sprintf('Could not write --record "%s".', $file));
        }
    }
}
