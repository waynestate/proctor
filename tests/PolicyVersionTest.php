<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use Waynestate\Proctor\Policy\JsonFilePolicySource;
use Waynestate\Proctor\Policy\State;

/**
 * An older reader (the current code) must still read a newer policy file: a
 * newer schema_version and keys the reader does not know are accepted and
 * ignored, so the check runs normally.
 */
final class PolicyVersionTest extends ProctorTestCase
{
    /**
     * The path of the newer-policy fixture.
     */
    private function newerPolicy(): string
    {
        return __DIR__ . '/fixtures/policy-newer.json';
    }

    public function testOlderReaderAcceptsNewerSchemaVersionAndUnknownKeys(): void
    {
        // The current reader must not refuse the newer schema_version (2) or
        // the keys it does not know (the mysql component, platform_components).
        $policy = new JsonFilePolicySource($this->newerPolicy());

        // A known component/version still resolves to the right state.
        self::assertSame(
            State::ACTIVE,
            $policy->stateOf('php', '8.4', new DateTimeImmutable(self::TODAY)),
        );
    }

    public function testCheckRunsNormallyWithNewerPolicy(): void
    {
        $result = $this->runProctor($this->app('pass-app'), 'text', $this->newerPolicy());

        // The check runs to completion (no policy error) and passes.
        self::assertSame(0, $result['exit']);
        self::assertSame([], $this->failCheckIds($result['stdout']));
        self::assertMatchesRegularExpression('/^PASS php-floor: /m', $result['stdout']);
        self::assertStringNotContainsString('schema_version', $result['stderr']);
    }
}
