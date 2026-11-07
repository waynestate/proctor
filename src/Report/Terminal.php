<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Report;

/**
 * Makes text from files the app controls safe to print to a terminal or a
 * deploy log.
 */
final class Terminal
{
    private function __construct() {}

    /**
     * Replaces terminal control characters with a visible "?": every C0
     * control except newline and tab, plus DEL and the C1 block.
     *
     * The pattern is byte-oriented on purpose. ASCII controls never occur
     * inside a UTF-8 multibyte sequence, and the C1 code points encode as
     * 0xC2 0x80 through 0xC2 0x9F, so valid UTF-8 text passes through
     * unchanged.
     */
    public static function clean(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0D-\x1F\x7F]|\xC2[\x80-\x9F]/', '?', $text) ?? $text;
    }
}
