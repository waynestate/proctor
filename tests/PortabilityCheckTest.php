<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\PortabilityCheck;
use Waynestate\Proctor\Creed\Policy;

/**
 * Calls the portability check directly, the way bin/proctor builds its
 * context: the fixture policy and a fixed --today.
 */
final class PortabilityCheckTest extends TestCase
{
    /** The fixed date every run uses. */
    private const TODAY = '2026-10-27';

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
     * The (code prefix, path, line) triples of the findings, in order.
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

    public function testPortabilityAppGivesExactlyFourWarnings(): void
    {
        $findings = (new PortabilityCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/portability-app'),
        );

        self::assertCount(4, $findings);
        foreach ($findings as $finding) {
            self::assertSame(Finding::STATUS_WARNING, $finding->status);
            self::assertStringNotContainsString('vendor/', (string) $finding->path);
            self::assertNotSame('config/loopback.php', $finding->path);
        }

        // The check sorts by path, then line, so the four warnings come out
        // in this order.
        self::assertSame(
            [
                ['local-session', 'config/session.php', 5],
                ['proxy-address', 'config/trustedproxy.php', 5],
                ['htaccess-php-setting', 'public/.htaccess', 1],
                ['host-path', 'src/Paths.php', 5],
            ],
            $this->triples($findings),
        );
    }

    public function testPassAppGivesExactlyOnePassFinding(): void
    {
        $findings = (new PortabilityCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/pass-app'),
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no hard-coded platform facts found', $findings[0]->message);
    }

    public function testLaravelGeneratedCacheIsSkipped(): void
    {
        $findings = (new PortabilityCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/laravel-cache-app'),
        );

        // The same file sits in src/ and in bootstrap/cache/. Only the src/
        // copy is reported: the artisan file marks the app as Laravel, and
        // bootstrap/cache/ holds the generated output of
        // `php artisan optimize`, not app code.
        self::assertSame(
            [
                ['host-path', 'src/Paths.php', 5],
            ],
            $this->triples($findings),
        );
        foreach ($findings as $finding) {
            self::assertStringNotContainsString('bootstrap/cache/', (string) $finding->path);
        }
    }

    public function testRawPhpAppScansItsOwnBootstrapCache(): void
    {
        $appPath = sys_get_temp_dir() . '/proctor-portability-' . uniqid('', true);
        self::assertTrue(is_dir($appPath) || mkdir($appPath . '/bootstrap/cache', 0777, true));
        file_put_contents($appPath . '/bootstrap/cache/config.php', "<?php\n\n\$log = '/var/log/app.log';\n");

        try {
            $findings = (new PortabilityCheck())->run($this->contextFor($appPath));

            // No artisan file at the root, so this is a raw-PHP app and its
            // own bootstrap/cache/ folder is scanned like any other.
            self::assertSame(
                [
                    ['host-path', 'bootstrap/cache/config.php', 3],
                ],
                $this->triples($findings),
            );
        } finally {
            unlink($appPath . '/bootstrap/cache/config.php');
            rmdir($appPath . '/bootstrap/cache');
            rmdir($appPath . '/bootstrap');
            rmdir($appPath);
        }
    }

    public function testWindowsDrivePathIsAHostPath(): void
    {
        $appPath = sys_get_temp_dir() . '/proctor-portability-' . uniqid('', true);
        self::assertTrue(is_dir($appPath) || mkdir($appPath, 0777, true));
        file_put_contents($appPath . '/win.php', "<?php\n\n\$p = 'C:\\\\data\\\\app';\n");

        try {
            $findings = (new PortabilityCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
            self::assertSame(
                [
                    ['host-path', 'win.php', 3],
                ],
                $this->triples($findings),
            );
        } finally {
            unlink($appPath . '/win.php');
            rmdir($appPath);
        }
    }

    public function testHostPathInCommentIsNotFlagged(): void
    {
        $appPath = sys_get_temp_dir() . '/proctor-portability-' . uniqid('', true);
        self::assertTrue(is_dir($appPath) || mkdir($appPath, 0777, true));
        file_put_contents($appPath . '/comment.php', "<?php\n\n// logs live in /var/log/app.log\n\$p = 'relative/path';\n");

        try {
            $findings = (new PortabilityCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        } finally {
            unlink($appPath . '/comment.php');
            rmdir($appPath);
        }
    }

    public function testProxyAddressInCommentIsNotFlagged(): void
    {
        $appPath = sys_get_temp_dir() . '/proctor-portability-' . uniqid('', true);
        self::assertTrue(is_dir($appPath) || mkdir($appPath, 0777, true));
        file_put_contents($appPath . '/comment.php', "<?php\n\n// trusted_proxies: 172.18.0.1\n\$p = 'relative/path';\n");

        try {
            $findings = (new PortabilityCheck())->run($this->contextFor($appPath));

            self::assertCount(1, $findings);
            self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        } finally {
            unlink($appPath . '/comment.php');
            rmdir($appPath);
        }
    }

    /**
     * Writes the files into a fresh temp app, runs the check, cleans up.
     *
     * @param array<string, string> $files relative path => contents
     *
     * @return list<Finding>
     */
    private function runOnFiles(array $files): array
    {
        $appPath = sys_get_temp_dir() . '/proctor-portability-' . uniqid('', true);
        foreach ($files as $path => $contents) {
            $dir = dirname($appPath . '/' . $path);
            self::assertTrue(is_dir($dir) || mkdir($dir, 0777, true));
            file_put_contents($appPath . '/' . $path, $contents);
        }

        try {
            return (new PortabilityCheck())->run($this->contextFor($appPath));
        } finally {
            foreach (array_keys($files) as $path) {
                unlink($appPath . '/' . $path);
            }
            foreach (array_reverse(array_unique(array_map(static fn(string $p): string => dirname($appPath . '/' . $p), array_keys($files)))) as $dir) {
                rmdir($dir);
            }
            if (is_dir($appPath)) {
                rmdir($appPath);
            }
        }
    }

    public function testEveryPhpRuleStillFires(): void
    {
        $findings = $this->runOnFiles([
            'a.php' => "<?php\n\$p = '/var/www/app';\n\$trusted_proxies = '172.18.0.1';\nini_set('session.save_path', '/mnt/s');\n",
            'b.php' => "<?php\nuse Illuminate\\Support\\Facades\\Config;\nConfig::set('session.save_path', '/mnt/s');\n",
            'config/session.php' => "<?php\nreturn [\n    'driver' => 'file',\n];\n",
            '.htaccess' => "php_flag display_errors on\n",
        ]);

        self::assertSame(
            [
                ['htaccess-php-setting', '.htaccess', 1],
                ['host-path', 'a.php', 2],
                ['proxy-address', 'a.php', 3],
                ['local-session', 'a.php', 4],
                ['local-session', 'b.php', 3],
                ['local-session', 'config/session.php', 3],
            ],
            $this->triples($findings),
        );
    }

    public function testBannedTextInCommentsAndStringsIsNotFlagged(): void
    {
        $findings = $this->runOnFiles([
            'quiet.php' => "<?php\n"
                . "// ini_set('session.save_path', '/var/lib/sessions');\n"
                . "/* Config::set('session.save_path', '/srv/s'); */\n"
                . "\$a = \"call ini_set('session.save_path', '/tmp/s') later\";\n"
                . "\$b = 'the driver is file';\n",
        ]);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testCallSplitAcrossLinesFires(): void
    {
        $findings = $this->runOnFiles([
            'split.php' => "<?php\nini_set(\n    'session.save_path',\n    '/mnt/sessions'\n);\n",
        ]);

        self::assertSame([['local-session', 'split.php', 2]], $this->triples($findings));
    }

    public function testUseFunctionAliasResolves(): void
    {
        $findings = $this->runOnFiles([
            'alias.php' => "<?php\nnamespace App;\n\nuse function ini_set as set_it;\n\nset_it('session.save_path', '/mnt/php');\n",
        ]);

        self::assertSame([['local-session', 'alias.php', 6]], $this->triples($findings));
    }

    public function testDynamicCallGivesAnInfoFindingAndStillPasses(): void
    {
        $findings = $this->runOnFiles([
            'dynamic.php' => "<?php\n\$f = 'ini_set';\n\$f('session.save_path', '/mnt/x');\n",
        ]);

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame(Finding::STATUS_INFO, $findings[1]->status);
        self::assertStringStartsWith('dynamic-call: cannot analyse 1 call(s)', $findings[1]->message);
        self::assertSame(3, $findings[1]->line);
    }

    public function testSyntaxErrorGivesAParseWarning(): void
    {
        $findings = $this->runOnFiles([
            'broken.php' => "<?php\n\$x = ;\n",
        ]);

        self::assertCount(1, $findings);
        self::assertSame('portability', $findings[0]->checkId);
        self::assertSame('proctor/portability/parse-error', $findings[0]->ruleId);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertStringStartsWith('could not parse broken.php: ', $findings[0]->message);
        self::assertSame(2, $findings[0]->line);
    }

    public function testPlainConfigLinesAreScannedWithoutRegex(): void
    {
        $findings = $this->runOnFiles([
            'config/proxies.ini' => "trusted_proxies = \"10.0.0.5/24\" 300.1.1.1\npath = 'C:\\data' \"unclosed\nsession.save_path = '/srv/s'\n",
        ]);

        self::assertSame(
            [
                ['proxy-address', 'config/proxies.ini', 1],
                ['host-path', 'config/proxies.ini', 2],
                ['host-path', 'config/proxies.ini', 3],
                ['local-session', 'config/proxies.ini', 3],
            ],
            $this->triples($findings),
        );
    }
}
