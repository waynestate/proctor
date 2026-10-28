<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check --format=codequality on unbounded-app and asserts
 * the shape of the GitLab Code Quality JSON.
 */
final class CodeQualityShapeTest extends ProctorTestCase
{
    public function testCodeQualityOutputIsAListOfWellFormedFindings(): void
    {
        $result = $this->runProctor($this->app('unbounded-app'), 'codequality');

        self::assertSame(1, $result['exit']);

        $items = json_decode($result['stdout'], true);
        self::assertIsArray($items, 'codequality output must be valid JSON');
        self::assertIsList($items, 'the top level must be a list');
        self::assertNotEmpty($items);

        // The checks that did not pass in the same run (text format).
        $text = $this->runProctor($this->app('unbounded-app'), 'text');
        $nonPass = $this->nonPassCheckIds($text['stdout']);

        foreach ($items as $item) {
            self::assertSame(
                ['description', 'check_name', 'fingerprint', 'severity', 'location'],
                array_keys($item),
            );
            self::assertContains(
                $item['severity'],
                ['info', 'minor', 'major', 'critical', 'blocker'],
            );
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $item['fingerprint']);
            self::assertIsString($item['location']['path']);
            self::assertStringStartsNotWith('./', $item['location']['path']);
            self::assertIsInt($item['location']['lines']['begin']);
            self::assertGreaterThanOrEqual(1, $item['location']['lines']['begin']);
            // Only non-pass findings are reported.
            self::assertContains($item['check_name'], $nonPass);
        }
    }

    public function testToolErrorInCodeQualityFormatListsTheErrorAsOneIssue(): void
    {
        $result = $this->runProctor('/nonexistent/proctor-test-path', 'codequality');

        self::assertSame(2, $result['exit']);

        $items = json_decode($result['stdout'], true);
        self::assertIsArray($items, 'codequality output must be valid JSON');
        self::assertIsList($items, 'the top level must be a list');
        self::assertCount(1, $items, 'a tool error must produce exactly one issue');

        $item = $items[0];
        self::assertSame(
            ['description', 'check_name', 'fingerprint', 'severity', 'location'],
            array_keys($item),
        );
        self::assertSame('proctor', $item['check_name']);
        self::assertSame('blocker', $item['severity']);
        self::assertStringContainsString('does not exist', $item['description']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $item['fingerprint']);
        self::assertIsString($item['location']['path']);
        self::assertStringStartsNotWith('./', $item['location']['path']);
        self::assertIsInt($item['location']['lines']['begin']);
        self::assertGreaterThanOrEqual(1, $item['location']['lines']['begin']);
    }
}
