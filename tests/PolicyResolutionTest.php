<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use Waynestate\Proctor\Policy\PolicyResolver;

/**
 * The resolution order of the CREED policy a run checks against:
 * --policy, PROCTOR_POLICY, the app's vendor copy, the bundled copy.
 */
final class PolicyResolutionTest extends ProctorTestCase
{
    /**
     * A temporary app directory with the given files (relative path =>
     * content). The caller removes it with removeDir().
     *
     * @param array<string, string> $files
     */
    private function tempApp(array $files = []): string
    {
        $dir = sys_get_temp_dir() . '/proctor-policy-test-' . uniqid('', true);
        self::assertTrue(mkdir($dir, 0777, true));
        foreach ($files as $relative => $content) {
            $path = $dir . '/' . $relative;
            $parent = dirname($path);
            if (!is_dir($parent)) {
                self::assertTrue(mkdir($parent, 0777, true));
            }
            self::assertNotFalse(file_put_contents($path, $content));
        }

        return $dir;
    }

    /**
     * Removes a temporary tree created by tempApp().
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * A full CREED policy (the fixture, which every check understands) with
     * the given refreshed_on date, or without one when null.
     */
    private function policyWith(?string $refreshedOn): string
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/policy.json'), true);
        self::assertIsArray($data);
        if ($refreshedOn === null) {
            unset($data['refreshed_on']);
        } else {
            $data['refreshed_on'] = $refreshedOn;
        }

        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function testBundledPolicyIsUsedWhenTheAppHasNoVendorCreed(): void
    {
        $app = $this->tempApp(['composer.json' => '{"require":{"php":"^8.3"}}']);
        try {
            $result = $this->runProctorResolved($app, 'json');
            $data = json_decode($result['stdout'], true);
            self::assertIsArray($data);
            self::assertSame('bundled', $data['policy']['origin']);
            self::assertStringEndsWith('src/Creed/policy.json', $data['policy']['path']);
            self::assertNull($data['policy']['refreshed_on']);
            self::assertNull($data['policy']['age_days']);
        } finally {
            $this->removeDir($app);
        }
    }

    public function testBundledPolicyLineInTextReports(): void
    {
        $app = $this->tempApp(['composer.json' => '{"require":{"php":"^8.3"}}']);
        try {
            $result = $this->runProctorResolved($app, 'text');
            self::assertMatchesRegularExpression(
                '/^Policy: bundled \(.*\), no refresh date$/m',
                $result['stdout'],
            );
        } finally {
            $this->removeDir($app);
        }
    }

    public function testVendorCopyIsUsedWhenBundledHasNoRefreshDate(): void
    {
        $app = $this->tempApp([
            'composer.json' => '{"require":{"php":"^8.3"}}',
            'vendor/waynestate/creed/policy/policy.json' => $this->policyWith('2026-10-25'),
        ]);
        try {
            $result = $this->runProctorResolved($app, 'json');
            $data = json_decode($result['stdout'], true);
            self::assertIsArray($data);
            self::assertSame('vendor', $data['policy']['origin']);
            self::assertStringEndsWith('vendor/waynestate/creed/policy/policy.json', $data['policy']['path']);
            self::assertSame('2026-10-25', $data['policy']['refreshed_on']);
            self::assertSame(2, $data['policy']['age_days']);
        } finally {
            $this->removeDir($app);
        }
    }

    public function testVendorFreshnessComparison(): void
    {
        // Same or newer wins...
        self::assertTrue(PolicyResolver::vendorAtLeastAsFresh('2026-10-01', '2026-10-01'));
        self::assertTrue(PolicyResolver::vendorAtLeastAsFresh('2026-10-02', '2026-10-01'));
        // ...a bundled copy without a date never loses...
        self::assertTrue(PolicyResolver::vendorAtLeastAsFresh(null, null));
        self::assertTrue(PolicyResolver::vendorAtLeastAsFresh('2026-10-02', null));
        // ...and an older or undated vendor copy does.
        self::assertFalse(PolicyResolver::vendorAtLeastAsFresh('2026-09-01', '2026-10-01'));
        self::assertFalse(PolicyResolver::vendorAtLeastAsFresh(null, '2026-10-01'));
    }

    public function testUnparseableVendorCopyFallsBackToBundledWithANote(): void
    {
        $app = $this->tempApp([
            'composer.json' => '{"require":{"php":"^8.3"}}',
            'vendor/waynestate/creed/policy/policy.json' => '{ "schema_version": ',
        ]);
        try {
            $result = $this->runProctorResolved($app, 'json');
            $data = json_decode($result['stdout'], true);
            self::assertIsArray($data);
            self::assertSame('bundled', $data['policy']['origin']);
            self::assertStringContainsString('Skipping vendor policy', $result['stderr']);
        } finally {
            $this->removeDir($app);
        }
    }

    public function testEnvVarWinsOverAVendorCopy(): void
    {
        $app = $this->tempApp([
            'composer.json' => '{"require":{"php":"^8.3"}}',
            'env-policy.json' => $this->policyWith('2026-10-01'),
            'vendor/waynestate/creed/policy/policy.json' => $this->policyWith('2026-10-25'),
        ]);
        try {
            $result = $this->runProctorResolved($app, 'json', null, $app . '/env-policy.json');
            $data = json_decode($result['stdout'], true);
            self::assertIsArray($data);
            self::assertSame('env', $data['policy']['origin']);
            self::assertSame('2026-10-01', $data['policy']['refreshed_on']);
        } finally {
            $this->removeDir($app);
        }
    }

    public function testPolicyOptionWinsOverEverything(): void
    {
        $app = $this->tempApp([
            'composer.json' => '{"require":{"php":"^8.3"}}',
            'opt-policy.json' => $this->policyWith('2026-09-15'),
            'env-policy.json' => $this->policyWith('2026-10-01'),
            'vendor/waynestate/creed/policy/policy.json' => $this->policyWith('2026-10-25'),
        ]);
        try {
            $result = $this->runProctorResolved($app, 'json', $app . '/opt-policy.json', $app . '/env-policy.json');
            $data = json_decode($result['stdout'], true);
            self::assertIsArray($data);
            self::assertSame('option', $data['policy']['origin']);
            self::assertSame('2026-09-15', $data['policy']['refreshed_on']);
        } finally {
            $this->removeDir($app);
        }
    }

    public function testPolicyAgeWarnsWhenThePolicyIsSixtyOneDaysOld(): void
    {
        $app = $this->tempApp([
            'composer.json' => '{"require":{"php":"^8.3"}}',
            'stale-policy.json' => $this->policyWith('2026-08-27'),
        ]);
        try {
            $result = $this->runProctorResolved($app, 'text', $app . '/stale-policy.json');
            self::assertMatchesRegularExpression(
                '/^WARN policy-age: policy is 61 days old \(refreshed 2026-08-27\)$/m',
                $result['stdout'],
            );
            // The warning never fails the run on its own.
            self::assertNotSame(2, $result['exit']);
        } finally {
            $this->removeDir($app);
        }
    }

    public function testPolicyAgeIsSilentAtExactlySixtyDays(): void
    {
        $app = $this->tempApp([
            'composer.json' => '{"require":{"php":"^8.3"}}',
            'fresh-policy.json' => $this->policyWith('2026-08-28'),
        ]);
        try {
            $result = $this->runProctorResolved($app, 'text', $app . '/fresh-policy.json');
            self::assertDoesNotMatchRegularExpression('/policy-age/', $result['stdout']);
            self::assertMatchesRegularExpression(
                '/^Policy: option \(.*\), refreshed 2026-08-28, 60 days ago$/m',
                $result['stdout'],
            );
        } finally {
            $this->removeDir($app);
        }
    }

    public function testNonBundledPolicyWithoutARefreshDateWarns(): void
    {
        $app = $this->tempApp([
            'composer.json' => '{"require":{"php":"^8.3"}}',
            'undated-policy.json' => $this->policyWith(null),
        ]);
        try {
            $result = $this->runProctorResolved($app, 'text', $app . '/undated-policy.json');
            self::assertMatchesRegularExpression(
                '/^WARN policy-age: policy has no refreshed_on date$/m',
                $result['stdout'],
            );
        } finally {
            $this->removeDir($app);
        }
    }

    public function testMissingExplicitPolicyStillExitsTwo(): void
    {
        $app = $this->tempApp(['composer.json' => '{"require":{"php":"^8.3"}}']);
        try {
            $result = $this->runProctorResolved($app, 'text', '/nonexistent/proctor-policy.json');
            self::assertSame(2, $result['exit']);
            self::assertStringContainsString('Policy file not found', $result['stderr']);
        } finally {
            $this->removeDir($app);
        }
    }

    public function testMissingEnvPolicyExitsTwo(): void
    {
        $app = $this->tempApp(['composer.json' => '{"require":{"php":"^8.3"}}']);
        try {
            $result = $this->runProctorResolved($app, 'text', null, '/nonexistent/proctor-policy.json');
            self::assertSame(2, $result['exit']);
            self::assertStringContainsString('Policy file not found', $result['stderr']);
        } finally {
            $this->removeDir($app);
        }
    }
}
