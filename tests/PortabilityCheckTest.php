<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\PortabilityCheck;
use Waynestate\Proctor\Policy\JsonFilePolicySource;

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
            new JsonFilePolicySource(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY)
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
            static fn (Finding $finding): array => [
                explode(':', $finding->message, 2)[0],
                (string) $finding->path,
                $finding->line,
            ],
            $findings
        );
    }

    public function testPortabilityAppGivesExactlyFourWarnings(): void
    {
        $findings = (new PortabilityCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/portability-app')
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
            $this->triples($findings)
        );
    }

    public function testPassAppGivesExactlyOnePassFinding(): void
    {
        $findings = (new PortabilityCheck())->run(
            $this->contextFor(__DIR__ . '/fixtures/apps/pass-app')
        );

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no hard-coded platform facts found', $findings[0]->message);
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
                $this->triples($findings)
            );
        } finally {
            unlink($appPath . '/win.php');
            rmdir($appPath);
        }
    }
}
