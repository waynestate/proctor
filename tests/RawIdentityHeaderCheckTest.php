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

        // Not flagged: X-Sesame-Assertion, PHP_AUTH_USER, a comment, a log
        // string, and files under tests/ and vendor/.
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
}
