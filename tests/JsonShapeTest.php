<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check --format=json on pass-app and asserts the shape of
 * the JSON report.
 */
final class JsonShapeTest extends ProctorTestCase
{
    public function testJsonOutputHasTheExpectedTopLevelKeys(): void
    {
        $result = $this->runProctor($this->app('pass-app'), 'json');

        self::assertSame(0, $result['exit']);

        $data = json_decode($result['stdout'], true);
        self::assertIsArray($data, 'json output must be valid JSON');
        self::assertSame(
            ['tool', 'version', 'path', 'today', 'result', 'exit', 'findings', 'overrides'],
            array_keys($data),
        );
    }
}
