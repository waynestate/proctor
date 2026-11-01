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
use Waynestate\Proctor\Check\ProcessRunner;
use Waynestate\Proctor\Check\WayneUiForkedViewsCheck;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\ToolError;

/**
 * Builds a small git repository in a temp dir and calls the
 * wayneui-forked-views check directly, the way bin/proctor builds its
 * context: the fixture policy and a fixed --today.
 */
final class WayneUiForkedViewsCheckTest extends TestCase
{
    /** The fixed date every run uses. */
    private const TODAY = '2026-10-27';

    /** @var string|null the temp dir of this test, null before setUp */
    private ?string $tempDir = null;

    private ProcessRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new ProcessRunner();
        $this->tempDir = sys_get_temp_dir() . '/proctor-wayneui-forked-views-' . bin2hex(random_bytes(6));
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

    public function testCommittedViewsFolderIsOneWarningWithTheFileCount(): void
    {
        $this->writeFile('resources/views/vendor/wayne-ui/layout.blade.php', '<html></html>');
        $this->gitInit();
        $this->gitCommitAll();

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('wayneui-forked-views', $findings[0]->checkId);
        self::assertSame('proctor/wayneui-forked-views', $findings[0]->ruleId);
        self::assertSame('resources/views/vendor/wayne-ui', $findings[0]->path);
        self::assertSame(
            'resources/views/vendor/wayne-ui/ (1 files) holds forked WayneUI views. They override the package, so layout fixes in new WayneUI releases do not reach this app. If you did not change them on purpose, delete the folder and run git rm -r --cached resources/views/vendor/wayne-ui. If you did, compare them with the package views before upgrading.',
            $findings[0]->message,
        );
    }

    public function testCommittedAssetsFolderIsOneWarning(): void
    {
        $this->writeFile('public/vendor/wayne-ui/css/app.css', 'body {}');
        $this->gitInit();
        $this->gitCommitAll();

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('public/vendor/wayne-ui', $findings[0]->path);
        self::assertSame(
            'public/vendor/wayne-ui/ (1 files) is a committed copy of WayneUI assets from the 1.x install. WayneUI 2.x serves its assets itself. Delete it and run git rm -r --cached public/vendor/wayne-ui.',
            $findings[0]->message,
        );
    }

    public function testBothFoldersGiveTwoFindings(): void
    {
        $this->writeFile('resources/views/vendor/wayne-ui/layout.blade.php', '<html></html>');
        $this->writeFile('resources/views/vendor/wayne-ui/partials/nav.blade.php', '<nav></nav>');
        $this->writeFile('public/vendor/wayne-ui/css/app.css', 'body {}');
        $this->gitInit();
        $this->gitCommitAll();

        $findings = $this->check();

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('resources/views/vendor/wayne-ui', $findings[0]->path);
        self::assertStringContainsString('(2 files)', $findings[0]->message);
        self::assertSame(Finding::STATUS_WARNING, $findings[1]->status);
        self::assertSame('public/vendor/wayne-ui', $findings[1]->path);
        self::assertStringContainsString('(1 files)', $findings[1]->message);
    }

    public function testUntrackedViewsFolderGivesNoFinding(): void
    {
        $this->writeFile('composer.json', '{"name": "example/app"}');
        $this->gitInit();
        $this->gitCommitAll();
        // Written after the commit, so git does not track it.
        $this->writeFile('resources/views/vendor/wayne-ui/layout.blade.php', '<html></html>');

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testTopLevelVendorIsSkipped(): void
    {
        $this->writeFile('vendor/wayne-ui/css/app.css', 'body {}');
        $this->gitInit();
        $this->gitCommitAll();

        $findings = $this->check();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testOtherPackageViewsAreNotMatched(): void
    {
        $this->writeFile('resources/views/vendor/other-package/x.blade.php', '<x></x>');
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
        self::assertSame('no forked WayneUI views or assets committed to the repo', $findings[0]->message);
    }

    /**
     * Runs the check on the temp dir.
     *
     * @return list<Finding>
     */
    private function check(): array
    {
        return (new WayneUiForkedViewsCheck())->run(new CheckContext(
            (string) $this->tempDir,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY),
        ));
    }

    /**
     * Creates a file in the temp dir, making parent dirs as needed.
     */
    private function writeFile(string $relativePath, string $content = ''): void
    {
        $path = $this->tempDir . '/' . $relativePath;
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0o777, true) && ! is_dir($dir)) {
            self::fail("could not create {$dir}");
        }
        if (file_put_contents($path, $content) === false) {
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
