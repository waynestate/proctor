<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\PreflightPinnedCheck;
use Waynestate\Proctor\Creed\Policy;

/**
 * Calls the preflight-pinned check directly, the way bin/proctor builds its
 * context: the fixture policy and a fixed --today.
 */
final class PreflightPinnedCheckTest extends TestCase
{
    /** The fixed date every run uses. */
    private const TODAY = '2026-10-27';

    /** A 40-lowercase-hex commit SHA the pinned fixtures point at. */
    private const PINNED_SHA = '65711c350bd0ddf637f0933482d7bdae2a9b3c3b';

    /**
     * @param string $appPath the app to check
     */
    private function contextFor(string $appPath): CheckContext
    {
        return new CheckContext(
            $appPath,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY),
        );
    }

    /**
     * The (rule id, path, line) triples of the findings, in order.
     *
     * @param list<Finding> $findings
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function triples(array $findings): array
    {
        return array_map(
            static fn(Finding $finding): array => [
                explode(':', $finding->message, 2)[0],
                (string) $finding->path,
                $finding->line,
            ],
            $findings,
        );
    }

    /**
     * A temp app whose only file is a .gitlab-ci.yml with the given content.
     */
    private function appWithCiyml(string $yml): string
    {
        $appPath = sys_get_temp_dir() . '/proctor-preflight-pinned-' . uniqid('', true);
        self::assertTrue(is_dir($appPath) || mkdir($appPath, 0777, true));
        file_put_contents($appPath . '/.gitlab-ci.yml', $yml);

        return $appPath;
    }

    private function removeApp(string $appPath): void
    {
        if (is_file($appPath . '/.gitlab-ci.yml')) {
            unlink($appPath . '/.gitlab-ci.yml');
        }
        rmdir($appPath);
    }

    public function testShaPinPasses(): void
    {
        $appPath = $this->appWithCiyml(<<<'YAML'
include:
  - component: enterprise-apps/preflight/ci/php.yml@65711c350bd0ddf637f0933482d7bdae2a9b3c3b
YAML);

        try {
            $findings = (new PreflightPinnedCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
            self::assertSame('Preflight includes are pinned', $findings[0]->message);
            self::assertNull($findings[0]->path);
        } finally {
            $this->removeApp($appPath);
        }
    }

    /**
     * Full release tags are pins: published tags are never moved.
     *
     * @return array<string, array{0: string}>
     */
    public static function releaseTagProvider(): array
    {
        return [
            'plain tag' => ['0.3.0'],
            'v-prefixed tag' => ['v1.2.3'],
            'multi-digit parts' => ['10.20.30'],
        ];
    }

    #[DataProvider('releaseTagProvider')]
    public function testReleaseTagPasses(string $tag): void
    {
        $appPath = $this->appWithCiyml(<<<YAML
include:
  - component: \$CI_SERVER_FQDN/enterprise-apps/preflight/php-test@{$tag}
    inputs:
      image_prefix: \$CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX/
YAML);

        try {
            $findings = (new PreflightPinnedCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
            self::assertSame('Preflight includes are pinned', $findings[0]->message);
        } finally {
            $this->removeApp($appPath);
        }
    }

    /**
     * Refs that can move, or that are not a full release: each warns.
     *
     * @return array<string, array{0: string}>
     */
    public static function movingRefProvider(): array
    {
        return [
            'main branch' => ['main'],
            'master branch' => ['master'],
            'latest alias' => ['~latest'],
            'major.minor' => ['0.3'],
            'major only' => ['1'],
            'v-prefixed major.minor' => ['v0.3'],
            'pre-release' => ['0.3.0-rc.1'],
            'leading zero' => ['01.2.3'],
            'short sha' => ['65711c35'],
            'uppercase sha' => ['65711C350BD0DDF637F0933482D7BDAE2A9B3C3B'],
        ];
    }

    #[DataProvider('movingRefProvider')]
    public function testMovingRefWarnsUnpinned(string $ref): void
    {
        $appPath = $this->appWithCiyml(<<<YAML
include:
  - component: enterprise-apps/preflight/ci/php.yml@{$ref}
YAML);

        try {
            $findings = (new PreflightPinnedCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
            self::assertSame('proctor/preflight-pinned/unpinned', $findings[0]->ruleId);
            self::assertSame(
                [['preflight-pinned-unpinned', '.gitlab-ci.yml', 2]],
                $this->triples($findings),
            );
            self::assertStringContainsString('enterprise-apps/preflight/ci/php.yml@' . $ref, $findings[0]->message);
            self::assertStringContainsString('pin a release tag such as @0.3.0 or a commit SHA', $findings[0]->message);
        } finally {
            $this->removeApp($appPath);
        }
    }

    public function testNoRefWarnsUnpinned(): void
    {
        $appPath = $this->appWithCiyml(<<<'YAML'
include:
  - component: enterprise-apps/preflight/ci/php.yml
YAML);

        try {
            $findings = (new PreflightPinnedCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
            self::assertSame(
                [['preflight-pinned-unpinned', '.gitlab-ci.yml', 2]],
                $this->triples($findings),
            );
            self::assertStringContainsString('component enterprise-apps/preflight/ci/php.yml is included without a ref', $findings[0]->message);
            self::assertStringContainsString('pin a release tag such as @0.3.0 or a commit SHA', $findings[0]->message);
        } finally {
            $this->removeApp($appPath);
        }
    }

    public function testBranchWarnsUnpinned(): void
    {
        $appPath = $this->appWithCiyml(<<<'YAML'
include:
  - component: enterprise-apps/preflight/ci/php.yml@main
YAML);

        try {
            $findings = (new PreflightPinnedCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
            self::assertSame(
                [['preflight-pinned-unpinned', '.gitlab-ci.yml', 2]],
                $this->triples($findings),
            );
        } finally {
            $this->removeApp($appPath);
        }
    }

    public function testLatestAliasWarnsUnpinned(): void
    {
        $appPath = $this->appWithCiyml(<<<'YAML'
include:
  - component: enterprise-apps/preflight/ci/php.yml@~latest
YAML);

        try {
            $findings = (new PreflightPinnedCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
            self::assertSame(
                [['preflight-pinned-unpinned', '.gitlab-ci.yml', 2]],
                $this->triples($findings),
            );
        } finally {
            $this->removeApp($appPath);
        }
    }

    public function testNoIncludePasses(): void
    {
        $appPath = $this->appWithCiyml(<<<'YAML'
stages:
  - test
lint:
  script:
    - composer validate
YAML);

        try {
            $findings = (new PreflightPinnedCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
            self::assertSame('no Preflight components included', $findings[0]->message);
        } finally {
            $this->removeApp($appPath);
        }
    }

    public function testNonPreflightComponentIsIgnored(): void
    {
        $appPath = $this->appWithCiyml(<<<'YAML'
include:
  - component: enterprise-apps/wayne-ui/ci/php.yml@65711c350bd0ddf637f0933482d7bdae2a9b3c3b
YAML);

        try {
            $findings = (new PreflightPinnedCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
            self::assertSame('no Preflight components included', $findings[0]->message);
        } finally {
            $this->removeApp($appPath);
        }
    }

    public function testScriptOverrideWarns(): void
    {
        $appPath = $this->appWithCiyml(<<<'YAML'
include:
  - component: enterprise-apps/preflight/ci/php.yml@65711c350bd0ddf637f0933482d7bdae2a9b3c3b
php-test:
  script:
    - vendor/bin/phpunit
YAML);

        try {
            $findings = (new PreflightPinnedCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
            self::assertSame(
                [['preflight-pinned-script-override', '.gitlab-ci.yml', 3]],
                $this->triples($findings),
            );
        } finally {
            $this->removeApp($appPath);
        }
    }

    public function testRulesOnlyOverridePasses(): void
    {
        $appPath = $this->appWithCiyml(<<<'YAML'
include:
  - component: enterprise-apps/preflight/ci/php.yml@65711c350bd0ddf637f0933482d7bdae2a9b3c3b
php-test:
  rules:
    - if: $CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH
YAML);

        try {
            $findings = (new PreflightPinnedCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
            self::assertSame('Preflight includes are pinned', $findings[0]->message);
        } finally {
            $this->removeApp($appPath);
        }
    }

    public function testTwoIncludesOnePinnedGivesExactlyOneWarning(): void
    {
        $appPath = $this->appWithCiyml(<<<'YAML'
include:
  - component: enterprise-apps/preflight/ci/php.yml@65711c350bd0ddf637f0933482d7bdae2a9b3c3b
  - component: enterprise-apps/preflight/ci/lint.yml@main
YAML);

        try {
            $findings = (new PreflightPinnedCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
            self::assertSame(
                [['preflight-pinned-unpinned', '.gitlab-ci.yml', 3]],
                $this->triples($findings),
            );
            self::assertStringContainsString('enterprise-apps/preflight/ci/lint.yml@main', $findings[0]->message);
        } finally {
            $this->removeApp($appPath);
        }
    }

    public function testParseErrorPassesQuietly(): void
    {
        // An unclosed flow sequence is invalid YAML, so the pipeline could
        // not run at all; that is ci-pipelines' finding, and here there is
        // simply no Preflight component to pin, so pass quietly.
        $appPath = $this->appWithCiyml("include: [enterprise-apps/preflight\n");

        try {
            $findings = (new PreflightPinnedCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
            self::assertSame('no Preflight components included', $findings[0]->message);
        } finally {
            $this->removeApp($appPath);
        }
    }

    public function testPreflightJobWithScriptButNoIncludeDoesNotWarn(): void
    {
        $appPath = $this->appWithCiyml(<<<'YAML'
php-test:
  script:
    - vendor/bin/phpunit
YAML);

        try {
            $findings = (new PreflightPinnedCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
            self::assertSame('no Preflight components included', $findings[0]->message);
        } finally {
            $this->removeApp($appPath);
        }
    }
}
