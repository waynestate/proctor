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
use Waynestate\Proctor\Check\ProcessResult;
use Waynestate\Proctor\Check\ProcessRunnerInterface;
use Waynestate\Proctor\Check\WayneUiOutdatedCheck;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\ToolError;

/**
 * Builds a small app in a temp dir and calls the wayneui-outdated check
 * directly with a stub process runner that answers "composer show" with
 * canned output, the way bin/proctor builds its context: the fixture policy
 * and a fixed --today.
 */
final class WayneUiOutdatedCheckTest extends TestCase
{
    /** The fixed date every run uses. */
    private const TODAY = '2026-10-27';

    /**
     * The canned "composer show" versions list for the happy paths: the
     * highest stable 2.x is v2.0.8, with a pre-release, another major and a
     * dev branch that all have to be ignored.
     */
    private const LATEST_JSON = '{"versions": ["v2.0.8", "v2.0.7", "v2.0.5", "v2.1.0-rc1", "v3.0.0", "dev-main"]}';

    /** @var string|null the temp dir of this test, null before setUp */
    private ?string $tempDir = null;

    /** @var object|null the stub runner of the last run, null before check() */
    private ?object $runner = null;

    private string $originalPath = '';

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
        $this->tempDir = sys_get_temp_dir() . '/proctor-wayneui-outdated-' . bin2hex(random_bytes(6));
        if (!mkdir($this->tempDir)) {
            self::fail('could not create the temp dir');
        }
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            $this->removeTree($this->tempDir);
        }
        $this->tempDir = null;
        $this->runner = null;
    }

    public function testComposerLockBehindGivesOneWarningNamingBothVersions(): void
    {
        $this->writeComposerLock(['waynestate/wayne-ui' => 'v2.0.5']);

        $findings = $this->check(self::LATEST_JSON);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('wayneui-outdated', $findings[0]->checkId);
        self::assertSame('proctor/wayneui-outdated', $findings[0]->ruleId);
        self::assertSame('composer.lock', $findings[0]->path);
        self::assertSame(
            'composer.lock locks WayneUI 2.0.5; the latest 2.x is 2.0.8. Update with: composer update waynestate/wayne-ui',
            $findings[0]->message,
        );
    }

    public function testComposerLockCurrentGivesNoFinding(): void
    {
        $this->writeComposerLock(['waynestate/wayne-ui' => 'v2.0.8']);

        $findings = $this->check(self::LATEST_JSON);

        self::assertSame([], $findings);
    }

    public function testNpmLockBehindNamesThePackageAndSkipsTheCurrentOne(): void
    {
        $this->writeFile('package-lock.json', (string) json_encode([
            'name' => 'example-app',
            'lockfileVersion' => 3,
            'packages' => [
                'node_modules/@waynestate/wayne-ui-css' => ['version' => '2.0.5'],
                'node_modules/@waynestate/wayne-ui-vue' => ['version' => '2.0.8'],
            ],
        ]));

        $findings = $this->check(self::LATEST_JSON);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('package-lock.json', $findings[0]->path);
        self::assertSame(
            'package-lock.json locks @waynestate/wayne-ui-css 2.0.5; the latest 2.x is 2.0.8. Update with: npm update @waynestate/wayne-ui-css',
            $findings[0]->message,
        );
    }

    public function testCommittedCssCopyBehindWarnsWithThePath(): void
    {
        $this->writeFile('site/assets/wayne-ui/wayne-ui.css', $this->cssWithBanner('2.0.5'));

        $findings = $this->check(self::LATEST_JSON);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('site/assets/wayne-ui/wayne-ui.css', $findings[0]->path);
        self::assertSame(
            'site/assets/wayne-ui/wayne-ui.css is a committed copy of WayneUI 2.0.5; the latest 2.x is 2.0.8. A pasted copy cannot tell you it is stale: replace it from the release, or link the hosted stylesheet instead of copying it.',
            $findings[0]->message,
        );
    }

    public function testCssCopyWithoutABannerGivesNoFindingAndNoLookup(): void
    {
        $this->writeFile('assets/wayne-ui.css', "body { margin: 0; }\n");

        $findings = $this->check(self::LATEST_JSON);

        self::assertSame([], $findings);
        self::assertSame(0, $this->composerCalls());
    }

    public function testCopiesFailSettingMakesABehindCopyAFailure(): void
    {
        $this->writeComposerJsonWithCopies('fail');
        $this->writeFile('site/assets/wayne-ui/wayne-ui.css', $this->cssWithBanner('2.0.5'));

        $findings = $this->check(self::LATEST_JSON);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_FAIL, $findings[0]->status);
        self::assertSame('site/assets/wayne-ui/wayne-ui.css', $findings[0]->path);
        self::assertSame(
            'site/assets/wayne-ui/wayne-ui.css is a committed copy of WayneUI 2.0.5; the latest 2.x is 2.0.8. A pasted copy cannot tell you it is stale: replace it from the release, or link the hosted stylesheet instead of copying it. This project fails on stale copies (wayneui.copies = fail); replace the copy from the release, for example with the stack\'s sync command.',
            $findings[0]->message,
        );
    }

    public function testCopiesFailSettingLeavesABehindComposerLockAWarning(): void
    {
        $this->writeComposerJsonWithCopies('fail');
        $this->writeComposerLock(['waynestate/wayne-ui' => 'v2.0.5']);

        $findings = $this->check(self::LATEST_JSON);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('composer.lock', $findings[0]->path);
        self::assertSame(
            'composer.lock locks WayneUI 2.0.5; the latest 2.x is 2.0.8. Update with: composer update waynestate/wayne-ui',
            $findings[0]->message,
        );
    }

    public function testCopiesFailSettingWithACurrentCopyGivesNoFinding(): void
    {
        $this->writeComposerJsonWithCopies('fail');
        $this->writeFile('site/assets/wayne-ui/wayne-ui.css', $this->cssWithBanner('2.0.8'));

        $findings = $this->check(self::LATEST_JSON);

        self::assertSame([], $findings);
    }

    public function testCopiesFailSettingOfflineGivesTheInfoSkippedFinding(): void
    {
        $this->writeComposerJsonWithCopies('fail');
        $this->writeFile('site/assets/wayne-ui/wayne-ui.css', $this->cssWithBanner('2.0.5'));

        $findings = $this->check('', 1);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_INFO, $findings[0]->status);
        self::assertSame('proctor/wayneui-outdated/skipped', $findings[0]->ruleId);
        self::assertSame(
            'could not look up the latest WayneUI release (offline or composer unavailable); wayneui-outdated skipped',
            $findings[0]->message,
        );
    }

    public function testCopiesUnknownSettingValueIsAToolErrorNamingTheSetting(): void
    {
        $this->writeComposerJsonWithCopies('shout');
        $this->writeFile('site/assets/wayne-ui/wayne-ui.css', $this->cssWithBanner('2.0.5'));

        try {
            $this->check(self::LATEST_JSON);
            self::fail('expected a ToolError for an unknown wayneui.copies value');
        } catch (ToolError $e) {
            self::assertStringContainsString('wayneui.copies', $e->getMessage());
            self::assertStringContainsString('warn, fail', $e->getMessage());
        }
    }

    public function testV1LockGivesTheOneXWarningAndNoProcessCall(): void
    {
        $this->writeComposerLock(['cit-web-infra/wayne-ui' => 'v1.9.0']);

        $findings = $this->check(self::LATEST_JSON);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('composer.lock', $findings[0]->path);
        self::assertSame(
            'composer.lock holds WayneUI 1.x (cit-web-infra/wayne-ui 1.9.0). 1.x is Bootstrap 3 and does not run past Laravel 5.8 without patches. Move to WayneUI 2.x: WayneUI\'s MIGRATING.md lists the steps and Booster\'s WayneUI option plans the move.',
            $findings[0]->message,
        );
        self::assertSame(0, $this->composerCalls());
    }

    public function testComposerFailingGivesOneInfoSkippedFinding(): void
    {
        $this->writeComposerLock(['waynestate/wayne-ui' => 'v2.0.5']);

        $findings = $this->check('', 1);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_INFO, $findings[0]->status);
        self::assertSame('proctor/wayneui-outdated/skipped', $findings[0]->ruleId);
        self::assertSame(
            'could not look up the latest WayneUI release (offline or composer unavailable); wayneui-outdated skipped',
            $findings[0]->message,
        );
    }

    public function testUnparsableComposerOutputGivesOneInfoSkippedFinding(): void
    {
        $this->writeComposerLock(['waynestate/wayne-ui' => 'v2.0.5']);

        $findings = $this->check('not json at all');

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_INFO, $findings[0]->status);
        self::assertSame('proctor/wayneui-outdated/skipped', $findings[0]->ruleId);
    }

    public function testNoStableReleaseOfTheInstalledMajorGivesOneInfoSkippedFinding(): void
    {
        $this->writeComposerLock(['waynestate/wayne-ui' => 'v2.0.5']);

        $findings = $this->check('{"versions": ["v3.0.0", "v3.1.0-rc1"]}');

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_INFO, $findings[0]->status);
        self::assertSame('proctor/wayneui-outdated/skipped', $findings[0]->ruleId);
    }

    public function testPreReleaseVersionsAreIgnored(): void
    {
        $this->writeComposerLock(['waynestate/wayne-ui' => 'v2.0.5']);

        $findings = $this->check('{"versions": ["v2.1.0-rc1", "v2.0.8"]}');

        self::assertCount(1, $findings);
        self::assertStringContainsString('the latest 2.x is 2.0.8', $findings[0]->message);
    }

    public function testOtherMajorsAreIgnoredForAnInstalledMajor(): void
    {
        $this->writeComposerLock(['waynestate/wayne-ui' => 'v2.0.5']);

        $findings = $this->check('{"versions": ["v3.0.0", "v2.0.8"]}');

        self::assertCount(1, $findings);
        self::assertStringContainsString('the latest 2.x is 2.0.8', $findings[0]->message);
    }

    public function testNothingWayneUiGivesNoFindingsAndNeverRunsAProcess(): void
    {
        $this->writeFile('composer.json', '{"name": "example/app"}');

        $findings = $this->check(self::LATEST_JSON);

        self::assertSame([], $findings);
        self::assertSame(0, $this->composerCalls());
        foreach ($this->commands() as $command) {
            self::assertNotContains('show', $command, 'the lookup must not run when the app has nothing WayneUI');
        }
    }

    public function testVendorCopyIsSkipped(): void
    {
        $this->writeFile('vendor/wayne-ui.css', $this->cssWithBanner('2.0.5'));

        $findings = $this->check(self::LATEST_JSON);

        self::assertSame([], $findings);
        self::assertSame(0, $this->composerCalls());
    }

    public function testComposerMissingFromPathGivesOneInfoSkippedFinding(): void
    {
        $this->writeComposerLock(['waynestate/wayne-ui' => 'v2.0.5']);
        $empty = $this->tempDir . '/empty-path';
        if (!mkdir($empty)) {
            self::fail('could not create the empty PATH dir');
        }
        putenv('PATH=' . $empty);

        $findings = $this->check(self::LATEST_JSON);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_INFO, $findings[0]->status);
        self::assertSame('proctor/wayneui-outdated/skipped', $findings[0]->ruleId);
        self::assertSame(0, $this->composerCalls());
    }

    /**
     * Runs the check on the temp dir with the given canned "composer show"
     * output and returns its findings.
     *
     * @return list<Finding>
     */
    private function check(string $json, int $exitCode = 0): array
    {
        $this->runner = new class ($exitCode, $json) implements ProcessRunnerInterface {
            /** @var list<list<string>> every command handed to the stub */
            public array $commands = [];

            /** The commands that were not git ls-files, i.e. the lookups. */
            public int $composerCalls = 0;

            public function __construct(
                private readonly int $exitCode,
                private readonly string $stdout,
            ) {}

            public function run(array $command, string $cwd, int $timeout = 300): ProcessResult
            {
                $this->commands[] = $command;
                if (in_array('ls-files', $command, true)) {
                    // The temp dir is not a git repository: the check falls
                    // back to the files on disk.
                    return new ProcessResult(128, '', 'fatal: not a git repository');
                }
                $this->composerCalls++;

                return new ProcessResult($this->exitCode, $this->stdout, '');
            }
        };

        return (new WayneUiOutdatedCheck($this->runner))->run(new CheckContext(
            (string) $this->tempDir,
            Policy::fromFile(__DIR__ . '/fixtures/policy.json'),
            new DateTimeImmutable(self::TODAY),
        ));
    }

    /** The number of non-git commands the last run asked the stub for. */
    private function composerCalls(): int
    {
        self::assertNotNull($this->runner);

        return $this->runner->composerCalls;
    }

    /** Every command the last run asked the stub for. */
    private function commands(): array
    {
        self::assertNotNull($this->runner);

        return $this->runner->commands;
    }

    /**
     * Writes a composer.lock holding the given package versions.
     *
     * @param array<string, string> $versions
     */
    private function writeComposerLock(array $versions): void
    {
        $packages = [];
        foreach ($versions as $name => $version) {
            $packages[] = ['name' => $name, 'version' => $version];
        }

        $this->writeFile('composer.lock', (string) json_encode([
            'packages' => $packages,
            'packages-dev' => [],
        ]));
    }

    /**
     * Writes a composer.json whose extra.proctor section carries the given
     * wayneui.copies value, so the strict-copies setting is read from the
     * same place an app would set it.
     */
    private function writeComposerJsonWithCopies(string $value): void
    {
        $this->writeFile('composer.json', (string) json_encode([
            'name' => 'example/app',
            'extra' => [
                'proctor' => [
                    'wayneui.copies' => $value,
                ],
            ],
        ]));
    }

    /**
     * A stylesheet copy carrying the WayneUI version banner.
     */
    private function cssWithBanner(string $version): string
    {
        return "/*!\n * WayneUI {$version}: Wayne State University design system\n * MIT License\n */\nbody { margin: 0; }\n";
    }

    /**
     * Creates a file in the temp dir, making parent dirs as needed.
     */
    private function writeFile(string $relativePath, string $content = ''): void
    {
        $path = $this->tempDir . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            self::fail("could not create {$dir}");
        }
        if (file_put_contents($path, $content) === false) {
            self::fail("could not write {$path}");
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
