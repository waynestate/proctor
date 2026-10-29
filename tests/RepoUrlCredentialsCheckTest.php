<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\RepoUrlCredentialsCheck;
use Waynestate\Proctor\Creed\Policy;

/**
 * Calls the repo-url-credentials check directly on temp-dir apps, the way
 * bin/proctor builds its context: the fixture policy and a fixed --today.
 */
final class RepoUrlCredentialsCheckTest extends TestCase
{
    /** The fixed date every run uses. */
    private const TODAY = '2026-10-27';

    public function testCleanAppGivesOnePassFinding(): void
    {
        $appPath = $this->makeApp([
            'composer.json' => <<<'JSON'
{
    "name": "acme/clean",
    "require": {
        "php": "^8.3"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/acme/lib.git"
        }
    ]
}
JSON,
            'composer.lock' => <<<'JSON'
{
    "packages": [
        {
            "name": "acme/lib",
            "source": {
                "url": "https://github.com/acme/lib.git",
                "type": "git",
                "reference": "deadbeef"
            },
            "dist": {
                "url": "https://api.github.com/repos/acme/lib/tarball/deadbeef",
                "type": "zip"
            }
        }
    ],
    "packages-dev": []
}
JSON,
        ]);

        $findings = $this->runIn($appPath);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no credentials found in repository URLs', $findings[0]->message);
    }

    public function testComposerJsonVcsUrlWithDeployTokenWarns(): void
    {
        $appPath = $this->makeApp([
            'composer.json' => <<<'JSON'
{
    "name": "acme/token-app",
    "require": {
        "php": "^8.3"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://gitlab+deploy-token-12:SuperSecret123@gitlab.example.com/group/lib.git"
        }
    ]
}
JSON,
        ]);

        $findings = $this->runIn($appPath);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('composer.json', $findings[0]->path);
        self::assertStringContainsString('gitlab.example.com', $findings[0]->message);
        self::assertStringContainsString('contains a credential', $findings[0]->message);
    }

    public function testComposerLockDistUrlWithDeployTokenWarns(): void
    {
        $appPath = $this->makeApp([
            'composer.json' => <<<'JSON'
{
    "name": "acme/lock-app",
    "require": {
        "php": "^8.3"
    }
}
JSON,
            'composer.lock' => <<<'JSON'
{
    "packages": [
        {
            "name": "acme/lib",
            "dist": {
                "url": "https://gitlab+deploy-token-12:TopSecret456@gitlab.example.com/group/lib.git",
                "type": "zip"
            }
        }
    ],
    "packages-dev": []
}
JSON,
        ]);

        $findings = $this->runIn($appPath);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('composer.lock', $findings[0]->path);
        self::assertStringContainsString('acme/lib', $findings[0]->message);
        self::assertStringContainsString('gitlab.example.com', $findings[0]->message);
    }

    public function testQueryParameterPrivateTokenWarns(): void
    {
        $appPath = $this->makeApp([
            'composer.json' => <<<'JSON'
{
    "name": "acme/query-app",
    "require": {
        "php": "^8.3"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://api.example.com/libs/lib.git?private_token=Qq7SecretValue"
        }
    ]
}
JSON,
        ]);

        $findings = $this->runIn($appPath);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('composer.json', $findings[0]->path);
        self::assertStringContainsString('private_token', $findings[0]->message);
        self::assertStringContainsString('api.example.com', $findings[0]->message);
    }

    public function testBareTokenUserInObjectFormRepositoryWarns(): void
    {
        // The object form of repositories (name to entry map) is scanned too.
        $appPath = $this->makeApp([
            'composer.json' => <<<'JSON'
{
    "name": "acme/bare-token-app",
    "require": {
        "php": "^8.3"
    },
    "repositories": {
        "private": {
            "type": "vcs",
            "url": "https://abcdef1234567890abcdef1234@gitlab.example.com/group/lib.git"
        }
    }
}
JSON,
        ]);

        $findings = $this->runIn($appPath);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('composer.json', $findings[0]->path);
        self::assertStringContainsString('bare token', $findings[0]->message);
        self::assertStringContainsString('gitlab.example.com', $findings[0]->message);
    }

    public function testNpmrcLiteralAuthTokenWarnsWithLineNumber(): void
    {
        $appPath = $this->makeApp([
            'composer.json' => <<<'JSON'
{
    "name": "acme/npm-app",
    "require": {
        "php": "^8.3"
    }
}
JSON,
            '.npmrc' => "registry=https://registry.npmjs.org/\n\n//registry.corp.example/:_authToken=NpmSecret789\n",
        ]);

        $findings = $this->runIn($appPath);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('.npmrc', $findings[0]->path);
        self::assertSame(3, $findings[0]->line);
        self::assertStringContainsString('_authToken', $findings[0]->message);
    }

    public function testNpmrcVariableAuthTokenPasses(): void
    {
        $appPath = $this->makeApp([
            'composer.json' => <<<'JSON'
{
    "name": "acme/npm-var-app",
    "require": {
        "php": "^8.3"
    }
}
JSON,
            '.npmrc' => "//registry.corp.example/:_authToken=\${NPM_TOKEN}\n//registry.corp.example/:_password=\${NPM_PASSWORD}\n",
        ]);

        $findings = $this->runIn($appPath);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testSshUrlsAreNotFlagged(): void
    {
        $appPath = $this->makeApp([
            'composer.json' => <<<'JSON'
{
    "name": "acme/ssh-app",
    "require": {
        "php": "^8.3"
    },
    "repositories": [
        {
            "type": "git",
            "url": "git@gitlab.example.com:group/lib.git"
        },
        {
            "type": "git",
            "url": "ssh://git@gitlab.example.com/group/lib.git"
        }
    ]
}
JSON,
        ]);

        $findings = $this->runIn($appPath);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testSecretNeverAppearsInAnyFindingMessage(): void
    {
        $secrets = [
            'SuperSecret123',
            'TopSecret456',
            'NpmSecret789',
        ];
        $appPath = $this->makeApp([
            'composer.json' => <<<'JSON'
{
    "name": "acme/leaky-app",
    "require": {
        "php": "^8.3"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://gitlab+deploy-token-12:SuperSecret123@gitlab.example.com/group/lib.git"
        }
    ]
}
JSON,
            'composer.lock' => <<<'JSON'
{
    "packages": [
        {
            "name": "acme/lib",
            "dist": {
                "url": "https://gitlab+deploy-token-12:TopSecret456@gitlab.example.com/group/lib.git",
                "type": "zip"
            }
        }
    ],
    "packages-dev": []
}
JSON,
            '.npmrc' => "//registry.corp.example/:_authToken=NpmSecret789\n",
        ]);

        $findings = $this->runIn($appPath);

        self::assertCount(3, $findings);
        foreach ($findings as $finding) {
            self::assertSame(Finding::STATUS_WARNING, $finding->status);
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString(
                    $secret,
                    $finding->message,
                    sprintf('secret "%s" must not appear in "%s"', $secret, $finding->message),
                );
            }
            self::assertStringContainsString('***', $finding->message);
        }
    }

    /**
     * Creates a temp-dir app with the given files (flat names) and returns
     * its path.
     *
     * @param array<string, string> $files
     */
    private function makeApp(array $files): string
    {
        $appPath = sys_get_temp_dir() . '/proctor-repo-url-credentials-' . uniqid('', true);
        self::assertTrue(mkdir($appPath, 0777, true), 'could not create temp app dir');
        foreach ($files as $name => $content) {
            self::assertTrue(file_put_contents($appPath . '/' . $name, $content) !== false);
        }

        return $appPath;
    }

    /**
     * Runs the check on the app and removes the temp dir afterwards.
     *
     * @return list<Finding>
     */
    private function runIn(string $appPath): array
    {
        try {
            return (new RepoUrlCredentialsCheck())->run(
                new CheckContext(
                    $appPath,
                    Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
                    new DateTimeImmutable(self::TODAY),
                ),
            );
        } finally {
            foreach (glob($appPath . '/composer.*') ?: [] as $file) {
                unlink($file);
            }
            if (is_file($appPath . '/.npmrc')) {
                unlink($appPath . '/.npmrc');
            }
            rmdir($appPath);
        }
    }
}
