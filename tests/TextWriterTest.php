<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Override\Override;
use Waynestate\Proctor\Policy\ResolvedPolicy;
use Waynestate\Proctor\Report\Report;
use Waynestate\Proctor\Report\TextWriter;

/**
 * Tests the TextWriter against app-supplied strings that carry terminal
 * control characters: an escape sequence or a carriage return in a package
 * name, an override reason or an approver must not reach the log.
 */
final class TextWriterTest extends TestCase
{
    public function testAppSuppliedStringsLoseTheirControlCharacters(): void
    {
        $findings = [
            new Finding(
                'composer-audit',
                Finding::STATUS_FAIL,
                "vendor/\e[31mred\e[0m: CVE-2026-0001 crafted title\r (high)",
                'composer.lock',
            ),
            new Finding(
                'unbounded-constraints',
                Finding::STATUS_WARNING,
                'vendor/café constraint "dev-master" is unbounded',
                'composer.json',
            ),
        ];
        $overrides = [
            new Override(
                'lock-committed',
                "risk accepted\r",
                new DateTimeImmutable('2026-01-01'),
                "t\x7Fster\u{85}",
            ),
        ];

        $output = (new TextWriter())->write($this->report($findings, $overrides));

        // No byte below 0x20 other than newline and tab, and no DEL.
        $bad = [];
        preg_match_all('/[\x00-\x08\x0B\x0C\x0D-\x1F\x7F]/', $output, $bad);
        self::assertSame([], $bad[0], 'terminal control characters in the text output');

        // The replacement is visible, and the surrounding text survives,
        // including a valid UTF-8 multibyte character.
        self::assertStringContainsString(
            'FAIL composer-audit: vendor/?[31mred?[0m: CVE-2026-0001 crafted title? (high)',
            $output,
        );
        self::assertStringContainsString(
            'WARN unbounded-constraints: vendor/café constraint "dev-master" is unbounded',
            $output,
        );
        self::assertStringContainsString(
            'OVERRIDE lock-committed: risk accepted? (expires 2026-01-01, approved by t?ster?)',
            $output,
        );
    }

    /**
     * A failing report around the given findings and overrides.
     *
     * @param list<Finding>  $findings
     * @param list<Override> $overrides
     */
    private function report(array $findings, array $overrides): Report
    {
        return new Report(
            '/app',
            new DateTimeImmutable('2026-10-27'),
            $findings,
            $overrides,
            Report::EXIT_FAIL,
            new ResolvedPolicy(
                Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
                'option',
                __DIR__ . '/fixtures/policy.json',
                '2026-10-20',
            ),
        );
    }
}
