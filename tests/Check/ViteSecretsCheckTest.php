<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\ViteSecretsCheck;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Policy\PolicyResolver;

/**
 * Tests the ViteSecretsCheck on a temp app dir, so both rules, their lines
 * and messages, the name list and the local-only skip are verified without
 * touching the fixture apps. The temp dir is not a git repository, so the
 * files on disk are the list; the CI markers are cleared for each test and
 * set only where a test says it runs in CI.
 */
final class ViteSecretsCheckTest extends TestCase
{
    private const CI_MARKERS = ['CI', 'GITLAB_CI', 'GITHUB_ACTIONS'];

    private const ENV_TAIL = 'looks like a secret, and Vite exposes every VITE_ variable to the browser bundle; keep the secret on the PHP side under a name without the VITE_ prefix, or rename the variable if its value is public by design';

    private const SOURCE_TAIL = 'reads a secret-looking variable into the browser bundle, where every visitor can read it; call a PHP route that holds the secret instead';

    private string $appPath = '';

    /** @var array<string, string|false> */
    private array $savedMarkers = [];

    protected function setUp(): void
    {
        foreach (self::CI_MARKERS as $marker) {
            $this->savedMarkers[$marker] = getenv($marker);
            putenv($marker);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedMarkers as $marker => $value) {
            putenv($value === false ? $marker : $marker . '=' . $value);
        }
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
            $this->appPath = '';
        }
    }

    public function testSecretLookingNameInEnvExampleWarnsWithFileAndLine(): void
    {
        $this->writeFile('.env.example', "APP_NAME=Acme\nVITE_APP_NAME=\"\${APP_NAME}\"\nVITE_API_KEY=sk-not-a-real-value\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/vite-secrets/env-file', $findings[0]->ruleId);
        self::assertSame('.env.example', $findings[0]->path);
        self::assertSame(3, $findings[0]->line);
        self::assertSame('VITE_API_KEY in .env.example:3 ' . self::ENV_TAIL, $findings[0]->message);
        self::assertStringNotContainsString('sk-not-a-real-value', $findings[0]->message, 'the value is never printed');
    }

    public function testEveryExampleFileIsReadAtAnyDepthAndExportIsAllowed(): void
    {
        $this->writeFile('.env.squire.example', "export VITE_PUSHER_APP_SECRET=x\n");
        $this->writeFile('deploy/.env.squire.example', "# a comment\nVITE_DB_PASSWORD = x\n");
        $this->writeFile('.env.production.dist', "VITE_STRIPE_PRIVATE_KEY=x\n");

        self::assertSame([
            ['.env.production.dist', 1, 'VITE_STRIPE_PRIVATE_KEY in .env.production.dist:1 ' . self::ENV_TAIL],
            ['.env.squire.example', 1, 'VITE_PUSHER_APP_SECRET in .env.squire.example:1 ' . self::ENV_TAIL],
            ['deploy/.env.squire.example', 2, 'VITE_DB_PASSWORD in deploy/.env.squire.example:2 ' . self::ENV_TAIL],
        ], $this->summary($this->runCheck()));
    }

    public function testARealEnvFileIsNotOpenedOutsideCi(): void
    {
        $this->writeFile('.env', "VITE_SECRET=x\n");
        $this->writeFile('.env.local', "VITE_GITHUB_TOKEN=x\n");
        $this->writeFile('vite.config.js', "export default {};\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/vite-secrets', $findings[0]->ruleId);
        self::assertSame('no secret-looking VITE_ variable is committed or read by the front-end code', $findings[0]->message);
    }

    public function testARealEnvFileIsReadInCi(): void
    {
        putenv('CI=true');
        $this->writeFile('.env', "VITE_SECRET=x\n");
        $this->writeFile('.env.local', "VITE_GITHUB_TOKEN=x\n");

        self::assertSame([
            ['.env', 1, 'VITE_SECRET in .env:1 ' . self::ENV_TAIL],
            ['.env.local', 1, 'VITE_GITHUB_TOKEN in .env.local:1 ' . self::ENV_TAIL],
        ], $this->summary($this->runCheck()));
    }

    public function testFrontEndCodeThatReadsOneWarnsOncePerNamePerLine(): void
    {
        $this->writeFile('resources/js/app.ts', implode("\n", [
            "const name = import.meta.env.VITE_APP_NAME;",
            "const key = import.meta.env.VITE_MAPS_API_KEY ?? import.meta.env.VITE_MAPS_API_KEY;",
            "// import.meta.env.VITE_SECRET is not read here",
            " * import.meta.env.VITE_SECRET in a doc block",
            "fetch(url, { headers: { Authorization: import.meta.env['VITE_AUTH_TOKEN'] } });",
            '',
        ]));
        $this->writeFile('src/components/Login.vue', "<script setup>\nconst pw = import.meta.env.VITE_ADMIN_PASSWORD\n</script>\n");

        $findings = $this->runCheck();

        self::assertSame([
            ['resources/js/app.ts', 2, 'import.meta.env.VITE_MAPS_API_KEY in resources/js/app.ts:2 ' . self::SOURCE_TAIL],
            ['resources/js/app.ts', 5, 'import.meta.env.VITE_AUTH_TOKEN in resources/js/app.ts:5 ' . self::SOURCE_TAIL],
            ['src/components/Login.vue', 2, 'import.meta.env.VITE_ADMIN_PASSWORD in src/components/Login.vue:2 ' . self::SOURCE_TAIL],
        ], $this->summary($findings));
        foreach ($findings as $finding) {
            self::assertSame('proctor/vite-secrets/source', $finding->ruleId);
            self::assertSame(Finding::STATUS_WARNING, $finding->status);
        }
    }

    public function testCodeOutsideTheFrontEndFoldersAndOtherFileTypesAreNotRead(): void
    {
        $line = "const key = import.meta.env.VITE_API_KEY;\n";
        $this->writeFile('src/Service.php', "<?php // {$line}");
        $this->writeFile('resources/js/notes.md', $line);
        $this->writeFile('tests/js/app.spec.js', $line);
        $this->writeFile('resources/js/node_modules/pkg/index.js', $line);

        self::assertSame([], $this->runCheck());
    }

    public function testAViteAppWithNothingToFlagPasses(): void
    {
        $this->writeFile('package.json', '{"devDependencies": {"vite": "^7.0.0", "laravel-vite-plugin": "^2.0.0"}}');
        $this->writeFile('.env.example', "VITE_APP_NAME=\"\${APP_NAME}\"\nVITE_REVERB_APP_KEY=\"\${REVERB_APP_KEY}\"\n");
        $this->writeFile('resources/js/echo.js', "key: import.meta.env.VITE_REVERB_APP_KEY,\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testAnAppWithoutViteAndNothingToFlagHasNoFinding(): void
    {
        $this->writeFile('composer.json', '{"require": {"php": "^8.3"}}');
        $this->writeFile('.env.example', "APP_KEY=\nDB_PASSWORD=\n");

        self::assertSame([], $this->runCheck());
    }

    #[DataProvider('names')]
    public function testSecretLookingNames(string $name, bool $expected): void
    {
        self::assertSame($expected, ViteSecretsCheck::secretLooking($name));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function names(): iterable
    {
        foreach ([
            'VITE_API_KEY', 'VITE_SECRET', 'VITE_CLIENT_SECRET', 'VITE_GITHUB_TOKEN', 'VITE_ACCESS_TOKENS',
            'VITE_DB_PASSWORD', 'VITE_SMTP_PASSWD', 'VITE_GPG_PASSPHRASE', 'VITE_AWS_CREDENTIALS',
            'VITE_STRIPE_PRIVATE_KEY', 'VITE_PRIVATEKEY', 'VITE_AWS_ACCESS_KEY', 'VITE_JWT_SIGNING_KEY',
            'VITE_ENCRYPTION_KEY', 'VITE_APIKEY', 'VITE_APP_KEY', 'VITE_PUSHER_APP_SECRET', 'VITE_Api_Key',
        ] as $name) {
            yield $name => [$name, true];
        }
        foreach ([
            'VITE_APP_NAME', 'VITE_APP_URL', 'VITE_PUSHER_APP_KEY', 'VITE_REVERB_APP_KEY', 'VITE_RECAPTCHA_SITE_KEY',
            'VITE_PUSHER_APP_CLUSTER', 'VITE_KEYBOARD_LAYOUT', 'VITE_TOKENIZER_URL', 'VITE_SECRETARY_EMAIL', 'API_KEY',
        ] as $name) {
            yield $name => [$name, false];
        }
    }

    public function testRegisteredAndNotInTheDeployGate(): void
    {
        $ids = CheckSet::ids(CheckSet::all(PolicyResolver::resolve(null, dirname(__DIR__, 2))));

        self::assertContains('vite-secrets', $ids);
        self::assertNotContains('vite-secrets', CheckSet::GATE);
    }

    /**
     * Each finding as [path, line, message], in output order.
     *
     * @param list<Finding> $findings
     *
     * @return list<array{0: string|null, 1: int, 2: string}>
     */
    private function summary(array $findings): array
    {
        return array_map(
            static fn(Finding $finding): array => [$finding->path, $finding->line, $finding->message],
            $findings,
        );
    }

    private function writeFile(string $relative, string $content): void
    {
        $this->appDir();
        $absolute = $this->appPath . '/' . $relative;
        $parent = dirname($absolute);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($absolute, $content);
    }

    /**
     * The temp app dir, created on first use and remembered for cleanup.
     */
    private function appDir(): string
    {
        if ($this->appPath === '') {
            $this->appPath = sys_get_temp_dir() . '/proctor-vitesecrets-' . uniqid('', true);
            self::assertTrue(is_dir($this->appPath) || mkdir($this->appPath, 0777, true));
        }

        return $this->appPath;
    }

    /**
     * Runs the check on the temp app and returns its findings.
     *
     * @return list<Finding>
     */
    private function runCheck(): array
    {
        $context = new CheckContext(
            $this->appDir(),
            Policy::fromFile(__DIR__ . '/../fixtures/policy.json'),
            new DateTimeImmutable('2026-11-08'),
        );

        return (new ViteSecretsCheck())->run($context);
    }

    /**
     * Removes a file or directory tree.
     */
    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
