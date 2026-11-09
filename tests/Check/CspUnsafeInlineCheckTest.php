<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\CspUnsafeInlineCheck;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Policy\PolicyResolver;

/**
 * Tests the CspUnsafeInlineCheck on a temp app dir, so the warning
 * findings, their rule ids and their lines are verified without touching
 * the fixture apps.
 */
final class CspUnsafeInlineCheckTest extends TestCase
{
    private string $appPath = '';

    protected function tearDown(): void
    {
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
            $this->appPath = '';
        }
    }

    public function testAPhpArrayWithUnsafeInlineWarns(): void
    {
        $this->writeFile('config/helmet.php', "<?php\n\nreturn [\n    'csp' => [\n        'script-src' => [\"'self'\", \"'unsafe-inline'\"],\n    ],\n];\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/csp-unsafe-inline/script-src', $findings[0]->ruleId);
        self::assertSame('config/helmet.php', $findings[0]->path);
        self::assertSame(5, $findings[0]->line);
    }

    public function testTheMessageNamesTheFileAndLine(): void
    {
        $this->writeFile('config/helmet.php', "<?php\n\nreturn [\n    'csp' => [\n        'script-src' => [\"'self'\", \"'unsafe-inline'\"],\n    ],\n];\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(
            "config/helmet.php:5 lets scripts run inline ('unsafe-inline' in script-src); use HELMET's CSP nonce instead (CREED runtime-and-headers)",
            $findings[0]->message,
        );
    }

    public function testAMultiLinePhpArrayWarnsOnTheUnsafeInlineLine(): void
    {
        $this->writeFile('config/helmet.php', "<?php\n\nreturn [\n    \"script-src\" => [\n        \"'self'\",\n        \"'unsafe-inline'\",\n    ],\n];\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/csp-unsafe-inline/script-src', $findings[0]->ruleId);
        self::assertSame('config/helmet.php', $findings[0]->path);
        self::assertSame(6, $findings[0]->line);
    }

    public function testAHeaderStringInAConfFileWarns(): void
    {
        $this->writeFile('docker/apache.conf', "<VirtualHost *:80>\n    Header set Content-Security-Policy \"default-src 'self'; script-src 'self' 'unsafe-inline'\"\n</VirtualHost>\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/csp-unsafe-inline/script-src', $findings[0]->ruleId);
        self::assertSame('docker/apache.conf', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
    }

    public function testAnEnvLineWarns(): void
    {
        $this->writeFile('.env.example', "APP_NAME=Demo\nHELMET_CSP=\"default-src 'self'; script-src 'unsafe-inline'\"\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/csp-unsafe-inline/script-src', $findings[0]->ruleId);
        self::assertSame('.env.example', $findings[0]->path);
        self::assertSame(2, $findings[0]->line);
    }

    public function testAnEnvLineThatIsNotHelmetCspPasses(): void
    {
        $this->writeFile('.env.production', "OTHER_CSP=\"script-src 'unsafe-inline'\"\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/csp-unsafe-inline', $findings[0]->ruleId);
    }

    public function testANonceBesideItStillWarns(): void
    {
        $this->writeFile('public/.htaccess', "Header set Content-Security-Policy \"script-src 'nonce-abc123' 'strict-dynamic' 'unsafe-inline'\"\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/csp-unsafe-inline/script-src', $findings[0]->ruleId);
        self::assertSame('public/.htaccess', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    public function testAReportOnlyPolicyStillWarns(): void
    {
        $this->writeFile('deploy/nginx.conf', "add_header Content-Security-Policy-Report-Only \"script-src-elem 'UNSAFE-INLINE'\" always;\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/csp-unsafe-inline/script-src', $findings[0]->ruleId);
        self::assertSame('deploy/nginx.conf', $findings[0]->path);
    }

    public function testScriptSrcAttrInAPhpStringWarns(): void
    {
        $this->writeFile('app/Http/Middleware/Csp.php', "<?php\n\n\$policy = \"default-src 'self'; script-src-attr 'unsafe-inline'; img-src *\";\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/csp-unsafe-inline/script-src', $findings[0]->ruleId);
        self::assertSame('app/Http/Middleware/Csp.php', $findings[0]->path);
        self::assertSame(3, $findings[0]->line);
    }

    public function testStyleSrcAttrUnsafeInlinePasses(): void
    {
        $this->writeFile('config/helmet.php', "<?php\n\nreturn [\n    'style-src-attr' => [\"'unsafe-inline'\"],\n    'style-src' => [\"'self'\", \"'unsafe-inline'\"],\n    'script-src' => [\"'self'\"],\n];\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/csp-unsafe-inline', $findings[0]->ruleId);
    }

    public function testUnsafeInlineInAnotherDirectiveAfterScriptSrcPasses(): void
    {
        $this->writeFile('docker/apache.conf', "Header set Content-Security-Policy \"script-src 'self'; style-src 'unsafe-inline'\"\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/csp-unsafe-inline', $findings[0]->ruleId);
    }

    public function testACommentedLinePasses(): void
    {
        $this->writeFile('config/helmet.php', "<?php\n\n/**\n * script-src 'unsafe-inline' is never set here.\n */\nreturn [\n    // 'script-src' => [\"'unsafe-inline'\"],\n    # script-src 'unsafe-inline'\n    'script-src' => [\"'self'\"],\n];\n");
        $this->writeFile('docker/apache.conf', "# Header set Content-Security-Policy \"script-src 'unsafe-inline'\"\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/csp-unsafe-inline', $findings[0]->ruleId);
    }

    public function testAFileUnderVendorPasses(): void
    {
        $this->writeFile('app/vendor/pkg/Csp.php', "<?php\n\$p = \"script-src 'unsafe-inline'\";\n");
        $this->writeFile('vendor/pkg/config/helmet.php', "<?php\nreturn ['script-src' => [\"'unsafe-inline'\"]];\n");
        $this->writeFile('docker/node_modules/x/site.conf', "Header set Content-Security-Policy \"script-src 'unsafe-inline'\"\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/csp-unsafe-inline', $findings[0]->ruleId);
    }

    public function testAFileOutsideTheScannedPlacesPasses(): void
    {
        $this->writeFile('config/nested/helmet.php', "<?php\nreturn ['script-src' => [\"'unsafe-inline'\"]];\n");
        $this->writeFile('public/app.conf', "Header set Content-Security-Policy \"script-src 'unsafe-inline'\"\n");
        $this->writeFile('.env', "HELMET_CSP=\"script-src 'unsafe-inline'\"\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/csp-unsafe-inline', $findings[0]->ruleId);
    }

    public function testNoCspAnywherePasses(): void
    {
        $this->writeFile('public/index.php', "<?php\n\nrequire __DIR__ . '/../vendor/autoload.php';\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/csp-unsafe-inline', $findings[0]->ruleId);
        self::assertNull($findings[0]->path);
        self::assertSame("no committed config lets scripts run inline ('unsafe-inline' in script-src)", $findings[0]->message);
    }

    public function testOneWarningPerFileUsesTheFirstOffendingLine(): void
    {
        $this->writeFile('bootstrap/app.php', "<?php\n\$a = \"script-src 'self'\";\n\$b = \"script-src 'unsafe-inline'\";\n\$c = \"script-src 'unsafe-inline'\";\n");
        $this->writeFile('public/index.php', "<?php\nheader(\"Content-Security-Policy: script-src 'unsafe-inline'\");\n");

        $findings = $this->runCheck();

        self::assertCount(2, $findings);
        self::assertSame('bootstrap/app.php', $findings[0]->path);
        self::assertSame(3, $findings[0]->line);
        self::assertSame('public/index.php', $findings[1]->path);
        self::assertSame(2, $findings[1]->line);
    }

    public function testTheProctorRepoItselfPasses(): void
    {
        $context = new CheckContext(
            dirname(__DIR__, 2),
            Policy::fromFile(__DIR__ . '/../fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
        );

        $findings = (new CspUnsafeInlineCheck())->run($context);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/csp-unsafe-inline', $findings[0]->ruleId);
    }

    public function testRegisteredAfterDirectoryListingAndNotInTheDeployGate(): void
    {
        $ids = CheckSet::ids(CheckSet::all(PolicyResolver::resolve(null, dirname(__DIR__, 2))));

        $at = array_search('csp-unsafe-inline', $ids, true);
        self::assertIsInt($at);
        self::assertSame('directory-listing', $ids[$at - 1]);
        self::assertNotContains('csp-unsafe-inline', CheckSet::GATE);
    }

    /**
     * Writes a file into the temp app dir (created on first use), making any
     * missing parent directory along the way.
     */
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
            $this->appPath = sys_get_temp_dir() . '/proctor-cspunsafeinline-' . uniqid('', true);
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
            new DateTimeImmutable('2026-10-27'),
        );

        return (new CspUnsafeInlineCheck())->run($context);
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
