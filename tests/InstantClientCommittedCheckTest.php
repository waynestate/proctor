<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use DateTimeImmutable;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\InstantClientCommittedCheck;
use Waynestate\Proctor\Check\ProcessRunner;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\ToolError;

/**
 * Builds a small git repository in a temp dir and calls the
 * instant-client-committed check directly, the way bin/proctor builds its
 * context: the fixture policy and a fixed --today.
 */
final class InstantClientCommittedCheckTest extends TestCase
{
    /** The fixed date every run uses. */
    private const TODAY = '2026-10-27';

    /** @var string|null the temp dir of this test, null before setUp */
    private ?string $tempDir = null;

    private ProcessRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new ProcessRunner();
        $this->tempDir = sys_get_temp_dir() . '/proctor-instant-client-' . bin2hex(random_bytes(6));
        if (! mkdir($this->tempDir)) {
            self::fail('could not create the temp dir');
        }
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            $this->removeTree($this->tempDir);
        }
        $this->tempDir = null;
    }

    public function testCommittedZipIsOneWarningWithItsSize(): void
    {
        $this->writeFile('instantclient-basic-linux.x64-21.7.0.0.zip', padTo: 10_485_760);
        $this->gitInit();
        $this->gitCommitAll();

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('instant-client-committed', $findings[0]->checkId);
        self::assertSame('proctor/instant-client-committed', $findings[0]->ruleId);
        self::assertSame('instantclient-basic-linux.x64-21.7.0.0.zip', $findings[0]->path);
        self::assertSame(
            'instantclient-basic-linux.x64-21.7.0.0.zip (10.0 MB) is Oracle Instant Client committed to the repo. Delete it: Citadel\'s images already include Instant Client, and the WSU Sail and Docker images will too. Then run git rm -r --cached instantclient-basic-linux.x64-21.7.0.0.zip and add it to .gitignore.',
            $findings[0]->message,
        );
    }

    public function testUnzippedFolderIsOneFindingWithTheTotalSize(): void
    {
        $this->writeFile('instantclient_21_7/ocilib/libclntsh.so.21.1', padTo: 1_048_576);
        $this->writeFile('instantclient_21_7/sqlplus/sqlplus', padTo: 2_097_152);
        $this->writeFile('instantclient_21_7/README.txt', padTo: 524_288);
        $this->gitInit();
        $this->gitCommitAll();

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('instantclient_21_7', $findings[0]->path);
        self::assertStringContainsString('(3.5 MB)', $findings[0]->message);
    }

    public function testLooseLibraryFileIsAFinding(): void
    {
        $this->writeFile('libclntsh.so.21.1', padTo: 2_097_152);
        $this->gitInit();
        $this->gitCommitAll();

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('libclntsh.so.21.1', $findings[0]->path);
        self::assertStringContainsString('(2.0 MB)', $findings[0]->message);
    }

    public function testUntrackedZipGivesNoFinding(): void
    {
        $this->writeFile('composer.json', '{"name": "example/app"}');
        $this->gitInit();
        $this->gitCommitAll();
        // Written after the commit, so git does not track it.
        $this->writeFile('instantclient-basic-linux.x64-21.7.0.0.zip', padTo: 10_485_760);

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testFilesUnderVendorAreSkipped(): void
    {
        $this->writeFile('vendor/oracle/instantclient/instantclient-basic-linux.x64-21.7.0.0.zip', padTo: 10_485_760);
        $this->gitInit();
        $this->gitCommitAll();

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testCleanRepoPasses(): void
    {
        $this->writeFile('composer.json', '{"name": "example/app"}');
        $this->writeFile('src/index.php', "<?php\n");
        $this->gitInit();
        $this->gitCommitAll();

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('no Oracle Instant Client committed to the repo', $findings[0]->message);
    }

    /**
     * Runs the check on the temp dir.
     *
     * @return list<Finding>
     */
    private function check(): array
    {
        return (new InstantClientCommittedCheck())->run(new CheckContext(
            (string) $this->tempDir,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY),
        ));
    }

    /**
     * Creates a file in the temp dir, making parent dirs as needed. When
     * $padTo is set the file holds exactly that many bytes (the size is what
     * the check reports, not the content); otherwise $content is written.
     */
    private function writeFile(string $relativePath, string $content = '', int $padTo = 0): void
    {
        $path = $this->tempDir . '/' . $relativePath;
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0o777, true) && ! is_dir($dir)) {
            self::fail("could not create {$dir}");
        }
        $data = $padTo > 0 ? str_repeat('x', $padTo) : $content;
        if (file_put_contents($path, $data) === false) {
            self::fail("could not write {$path}");
        }
    }

    private function gitInit(): void
    {
        $this->git('init');
        $this->git('config', 'user.name', 'Proctor Test');
        $this->git('config', 'user.email', 'proctor-test@example.com');
    }

    private function gitCommitAll(): void
    {
        $this->git('add', '-A');
        $this->git('commit', '-m', 'fixture');
    }

    /**
     * Runs git in the temp dir and fails the test when it exits nonzero.
     *
     * @param list<string> $args
     */
    private function git(string ...$args): void
    {
        try {
            $result = $this->runner->run(
                array_merge(['git', '-C', (string) $this->tempDir], $args),
                (string) $this->tempDir,
                30,
            );
        } catch (ToolError $e) {
            self::fail('git could not be run: ' . $e->getMessage());
        }
        if ($result->exitCode !== 0) {
            self::fail(sprintf('git %s failed: %s', implode(' ', $args), trim($result->stderr)));
        }
    }

    /** Best-effort recursive delete of the temp dir. */
    private function removeTree(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}
