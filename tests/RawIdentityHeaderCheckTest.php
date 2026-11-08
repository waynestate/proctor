<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

/**
 * Runs bin/proctor check (text format) on the raw-identity-header fixture
 * apps and asserts the raw-identity-header findings (or their absence).
 */
final class RawIdentityHeaderCheckTest extends ProctorTestCase
{
    /**
     * The raw-identity-header lines of a text report, in output order.
     *
     * @return list<string>
     */
    private function headerLines(string $stdout): array
    {
        preg_match_all('/^(?:PASS|WARN|FAIL|INFO) raw-identity-header: .*$/m', $stdout, $matches);

        return $matches[0];
    }

    public function testDirectReadsWarnOncePerRead(): void
    {
        $stdout = $this->runProctor($this->app('raw-identity-header-app'))['stdout'];
        $controller = 'app/Http/Controllers/HomeController.php';
        $view = 'resources/views/welcome.blade.php';
        $tail = '; take the user from SESAME (gateway driver), which verifies the signed assertion. CREED auth-3.';

        // Not flagged: PHP_AUTH_USER, a comment, a log string, and files
        // under tests/ and vendor/.
        self::assertSame([
            "WARN raw-identity-header: Reads the identity header REMOTE_USER directly in {$controller}:9" . $tail,
            "WARN raw-identity-header: Reads the identity header HTTP_X_AUTHENTICATED_USER directly in {$controller}:10" . $tail,
            "WARN raw-identity-header: Reads the identity header AUTH_USER directly in {$controller}:11" . $tail,
            "WARN raw-identity-header: Reads the identity header OIDC_CLAIM_email directly in {$controller}:12" . $tail,
            "WARN raw-identity-header: Reads the identity header X-Drawbridge-Role directly in {$controller}:13" . $tail,
            "WARN raw-identity-header: Reads the identity header X-Authenticated-User directly in {$controller}:14" . $tail,
            "WARN raw-identity-header: Reads the identity header X-Drawbridge-Department directly in {$controller}:15" . $tail,
            "WARN raw-identity-header: Reads the identity header x-authenticated-user directly in {$controller}:16" . $tail,
            "WARN raw-identity-header: Reads the identity header X-Remote-User directly in {$controller}:17" . $tail,
            "WARN raw-identity-header: Reads the identity header AUTH_USER directly in {$controller}:19" . $tail,
            "WARN raw-identity-header: Reads the identity header OIDC_access_token directly in {$controller}:20" . $tail,
            "WARN raw-identity-header: Reads the identity header HTTP_REMOTE_USER directly in {$controller}:21" . $tail,
            "WARN raw-identity-header: Reads the identity header HTTP_X_SESAME_ASSERTION directly in app/Support/AuditLogger.php:10" . $tail,
            "WARN raw-identity-header: Reads the identity header REMOTE_USER directly in {$view}:1" . $tail,
            "WARN raw-identity-header: Reads the identity header X-Authenticated-User directly in {$view}:2" . $tail,
            "WARN raw-identity-header: Reads the identity header HTTP_OIDC_CLAIM_EMAIL directly in {$view}:3" . $tail,
        ], $this->headerLines($stdout));
    }

    public function testFindingsCarryPathLineAndRuleId(): void
    {
        $data = json_decode($this->runProctor($this->app('raw-identity-header-app'), 'json')['stdout'], true);
        self::assertIsArray($data);

        $seen = [];
        foreach ($data['findings'] as $finding) {
            if ($finding['check_id'] === 'raw-identity-header') {
                $seen[] = sprintf('%s %s %s:%d', $finding['rule_id'], $finding['status'], $finding['path'], $finding['line']);
            }
        }
        self::assertSame([
            'proctor/raw-identity-header warning app/Http/Controllers/HomeController.php:9',
            'proctor/raw-identity-header warning app/Http/Controllers/HomeController.php:10',
            'proctor/raw-identity-header warning app/Http/Controllers/HomeController.php:11',
            'proctor/raw-identity-header warning app/Http/Controllers/HomeController.php:12',
            'proctor/raw-identity-header warning app/Http/Controllers/HomeController.php:13',
            'proctor/raw-identity-header warning app/Http/Controllers/HomeController.php:14',
            'proctor/raw-identity-header warning app/Http/Controllers/HomeController.php:15',
            'proctor/raw-identity-header warning app/Http/Controllers/HomeController.php:16',
            'proctor/raw-identity-header warning app/Http/Controllers/HomeController.php:17',
            'proctor/raw-identity-header warning app/Http/Controllers/HomeController.php:19',
            'proctor/raw-identity-header warning app/Http/Controllers/HomeController.php:20',
            'proctor/raw-identity-header warning app/Http/Controllers/HomeController.php:21',
            'proctor/raw-identity-header warning app/Support/AuditLogger.php:10',
            'proctor/raw-identity-header warning resources/views/welcome.blade.php:1',
            'proctor/raw-identity-header warning resources/views/welcome.blade.php:2',
            'proctor/raw-identity-header warning resources/views/welcome.blade.php:3',
        ], $seen);
    }

    public function testSesameOnlyAppPasses(): void
    {
        $stdout = $this->runProctor($this->app('raw-identity-header-clean-app'))['stdout'];

        self::assertSame(
            ['PASS raw-identity-header: no app code reads an identity header directly'],
            $this->headerLines($stdout),
        );
    }

    public function testSesameAssertionServerReadWarns(): void
    {
        $stdout = $this->runProctor($this->app('raw-identity-header-app'))['stdout'];
        $logger = 'app/Support/AuditLogger.php';
        $tail = '; take the user from SESAME (gateway driver), which verifies the signed assertion. CREED auth-3.';

        // The signed assertion itself is flagged now, not just the claim
        // headers it carries.
        self::assertContains(
            "WARN raw-identity-header: Reads the identity header HTTP_X_SESAME_ASSERTION directly in {$logger}:10" . $tail,
            $this->headerLines($stdout),
        );
    }

    public function testSesameAssertionRequestHeaderReads(): void
    {
        $app = $this->sesameAssertionApp();
        $stdout = $this->runProctor($app)['stdout'];
        $this->removeDir($app);
        $tail = '; take the user from SESAME (gateway driver), which verifies the signed assertion. CREED auth-3.';

        // App code reading request()->header('X-Sesame-Assertion') warns;
        // the same read under vendor/ (SESAME's gateway driver) does not.
        self::assertSame([
            "WARN raw-identity-header: Reads the identity header X-Sesame-Assertion directly in app/ClaimReader.php:9" . $tail,
        ], $this->headerLines($stdout));
    }

    /**
     * A throwaway app in the system temp dir: app code reads
     * request()->header('X-Sesame-Assertion'), and the same read sits in
     * vendor/, where SESAME's gateway driver may open it.
     */
    private function sesameAssertionApp(): string
    {
        $app = sys_get_temp_dir() . '/proctor-sesame-assertion-' . getmypid();
        $this->removeDir($app);
        mkdir($app . '/app', 0755, true);
        mkdir($app . '/vendor/waynestate/sesame', 0755, true);
        file_put_contents($app . '/app/ClaimReader.php', implode("\n", [
            '<?php',
            '',
            'namespace Fixture\App;',
            '',
            'class ClaimReader',
            '{',
            '    public function assertion(): ?string',
            '    {',
            "        return request()->header('X-Sesame-Assertion');",
            '    }',
            '}',
            '',
        ]));
        file_put_contents($app . '/vendor/waynestate/sesame/GatewayDriver.php', implode("\n", [
            '<?php',
            '',
            'namespace Waynestate\Sesame;',
            '',
            'class GatewayDriver',
            '{',
            '    public function assertion(): ?string',
            '    {',
            "        return request()->header('X-Sesame-Assertion');",
            '    }',
            '}',
            '',
        ]));

        return $app;
    }

    /**
     * Removes the directory tree sesameAssertionApp() builds.
     */
    private function removeDir(string $dir): void
    {
        @unlink($dir . '/app/ClaimReader.php');
        @rmdir($dir . '/app');
        @unlink($dir . '/vendor/waynestate/sesame/GatewayDriver.php');
        @rmdir($dir . '/vendor/waynestate/sesame');
        @rmdir($dir . '/vendor/waynestate');
        @rmdir($dir . '/vendor');
        @rmdir($dir);
    }
}
