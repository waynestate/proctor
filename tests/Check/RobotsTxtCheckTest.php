<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\ProcessRunner;
use Waynestate\Proctor\Check\RobotsTxtCheck;
use Waynestate\Proctor\Code\RepoFiles;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Policy\PolicyResolver;

/**
 * Tests the RobotsTxtCheck on a temp app dir (no git, so the files on disk
 * are the committed files), so the warnings, their rule ids and their
 * messages are verified without touching the fixture apps.
 */
final class RobotsTxtCheckTest extends TestCase
{
    private const MISSING = 'public/robots.txt is missing; keep crawlers out with Disallow: /, or write Allow: / when crawling is wanted (CREED production)';

    private const ALLOWS_ALL = 'public/robots.txt lets every crawler in by default; keep crawlers out with Disallow: /, or write Allow: / when crawling is wanted (CREED production)';

    private string $appPath = '';

    protected function tearDown(): void
    {
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
            $this->appPath = '';
        }
    }

    public function testAMissingRobotsTxtWarns(): void
    {
        $this->writeFile('public/index.php', "<?php\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/robots-txt/missing', $findings[0]->ruleId);
        self::assertSame('public/robots.txt', $findings[0]->path);
    }

    public function testTheMissingMessageIsExact(): void
    {
        $this->writeFile('public/index.php', "<?php\n");

        $findings = $this->runCheck();

        self::assertSame(self::MISSING, $findings[0]->message);
    }

    public function testLaravelsDefaultRobotsTxtWarns(): void
    {
        $this->writeApp("User-agent: *\nDisallow:\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/robots-txt/allows-all', $findings[0]->ruleId);
        self::assertSame('public/robots.txt', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
    }

    public function testTheAllowsAllMessageIsExact(): void
    {
        $this->writeApp("User-agent: *\nDisallow:\n");

        $findings = $this->runCheck();

        self::assertSame(self::ALLOWS_ALL, $findings[0]->message);
    }

    public function testDisallowAllForEveryAgentPasses(): void
    {
        $this->writeApp("User-agent: *\nDisallow: /\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/robots-txt', $findings[0]->ruleId);
        self::assertSame('public/robots.txt', $findings[0]->path);
        self::assertSame('public/robots.txt keeps every crawler out (Disallow: / for User-agent: *)', $findings[0]->message);
    }

    public function testDisallowAllOnlyForGooglebotWarns(): void
    {
        $this->writeApp("User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow:\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/robots-txt/allows-all', $findings[0]->ruleId);
    }

    public function testDisallowAllInAGroupThatEndedBeforeTheStarAgentWarns(): void
    {
        $this->writeApp("User-agent: Googlebot\nDisallow: /\nUser-agent: *\nCrawl-delay: 10\n");

        $findings = $this->runCheck();

        self::assertSame('proctor/robots-txt/allows-all', $findings[0]->ruleId);
    }

    public function testAllowAllForEveryAgentPassesAsAChoiceToBeCrawled(): void
    {
        $this->writeApp("User-agent: *\nAllow: /\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/robots-txt', $findings[0]->ruleId);
        self::assertSame('public/robots.txt lets every crawler in by choice (Allow: / for User-agent: *)', $findings[0]->message);
    }

    public function testAllowAllOnlyForGooglebotWarns(): void
    {
        $this->writeApp("User-agent: Googlebot\nAllow: /\n\nUser-agent: *\nDisallow:\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/robots-txt/allows-all', $findings[0]->ruleId);
    }

    public function testAllowOfOneFolderDoesNotCount(): void
    {
        $this->writeApp("User-agent: *\nAllow: /public\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/robots-txt/allows-all', $findings[0]->ruleId);
    }

    public function testAGroupWithBothDisallowAndAllowAllPasses(): void
    {
        $this->writeApp("User-agent: *\nDisallow: /\nAllow: /\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/robots-txt', $findings[0]->ruleId);
    }

    public function testDisallowOfOneFolderWarns(): void
    {
        $this->writeApp("User-agent: *\nDisallow: /admin\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/robots-txt/allows-all', $findings[0]->ruleId);
    }

    public function testACommentedOutDisallowWarns(): void
    {
        $this->writeApp("User-agent: *\n# Disallow: /\nDisallow:\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/robots-txt/allows-all', $findings[0]->ruleId);
    }

    public function testCrlfLineEndingsPass(): void
    {
        $this->writeApp("User-agent: *\r\nDisallow: /\r\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testFieldNamesAreCaseInsensitiveAndTrailingSpacesCount(): void
    {
        $this->writeApp("user-AGENT: *\nDISALLOW: /   \n");

        $findings = $this->runCheck();

        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testAStarAgentSharingAGroupWithAnotherPasses(): void
    {
        $this->writeApp("# keep out\nUser-agent: Googlebot\nUser-agent: *\n\nDisallow: / # all of it\nSitemap: https://example.edu/sitemap.xml\n");

        $findings = $this->runCheck();

        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testAByteOrderMarkIsIgnored(): void
    {
        $this->writeApp("\u{FEFF}User-agent: *\nDisallow: /\n");

        $findings = $this->runCheck();

        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testAnEmptyRobotsTxtWarns(): void
    {
        $this->writeApp('');

        $findings = $this->runCheck();

        self::assertSame('proctor/robots-txt/allows-all', $findings[0]->ruleId);
    }

    public function testNoFindingWithoutAPublicIndexPhp(): void
    {
        $this->writeFile('public/robots.txt', "User-agent: *\nDisallow:\n");
        $this->writeFile('index.php', "<?php\n");

        self::assertSame([], $this->runCheck());
    }

    public function testAnUntrackedRobotsTxtCountsAsMissing(): void
    {
        $git = RepoFiles::findGit();
        if ($git === null) {
            self::markTestSkipped('git is not on PATH');
        }
        $this->writeApp("User-agent: *\nDisallow: /\n");
        $runner = new ProcessRunner();
        $dir = $this->appDir();
        foreach ([['init', '-q'], ['add', 'public/index.php']] as $args) {
            self::assertSame(0, $runner->run(array_merge([$git, '-C', $dir], $args), $dir)->exitCode);
        }

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/robots-txt/missing', $findings[0]->ruleId);
    }

    public function testTheProctorRepoItselfIsSilent(): void
    {
        $context = new CheckContext(
            dirname(__DIR__, 2),
            Policy::fromFile(__DIR__ . '/../fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
        );

        self::assertSame([], (new RobotsTxtCheck())->run($context));
    }

    public function testRegisteredAfterCspUnsafeInlineAndNotInTheDeployGate(): void
    {
        $ids = CheckSet::ids(CheckSet::all(PolicyResolver::resolve(null, dirname(__DIR__, 2))));

        self::assertContains('robots-txt', $ids);
        self::assertSame(array_search('csp-unsafe-inline', $ids, true) + 1, array_search('robots-txt', $ids, true));
        self::assertNotContains('robots-txt', CheckSet::GATE);
    }

    /**
     * Writes a served app: public/index.php and the given public/robots.txt.
     */
    private function writeApp(string $robots): void
    {
        $this->writeFile('public/index.php', "<?php\n");
        $this->writeFile('public/robots.txt', $robots);
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
            $this->appPath = sys_get_temp_dir() . '/proctor-robotstxt-' . uniqid('', true);
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

        return (new RobotsTxtCheck())->run($context);
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
