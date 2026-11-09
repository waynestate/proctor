<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Code;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\ProcessRunner;
use Waynestate\Proctor\Code\GitIndex;
use Waynestate\Proctor\Code\RepoFiles;

/**
 * GitIndex against real repositories built in a temp folder: whatever git
 * itself lists, GitIndex must list too, in every index version git writes.
 * The proctor's register has to match the school's.
 */
final class GitIndexTest extends TestCase
{
    private string $root;

    private string $git;

    protected function setUp(): void
    {
        $git = RepoFiles::findGit();
        if ($git === null) {
            self::markTestSkipped('git is not on PATH');
        }
        $this->git = $git;
        $this->root = sys_get_temp_dir() . '/proctor-gitindex-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->remove($this->root);
        }
    }

    public function testItListsWhatGitListsAndNothingUntracked(): void
    {
        $repo = $this->repo('repo');
        $this->write($repo, 'composer.json', '{}');
        $this->write($repo, '.env.production', "APP_DEBUG=true\n");
        $this->write($repo, 'deep/er/still/file.txt', "x\n");
        $this->write($repo, '.env', "APP_KEY=local\n");
        $this->git($repo, 'add', 'composer.json', '.env.production', 'deep/er/still/file.txt');

        $tracked = GitIndex::trackedUnder($repo);

        self::assertSame(['.env.production', 'composer.json', 'deep/er/still/file.txt'], $this->sorted($tracked));
        self::assertSame($this->lsFiles($repo), $this->sorted($tracked));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function versions(): iterable
    {
        yield 'version 2' => ['2'];
        yield 'version 3' => ['3'];
        yield 'version 4' => ['4'];
    }

    #[DataProvider('versions')]
    public function testEveryIndexVersionReadsTheSame(string $version): void
    {
        $repo = $this->repo('repo');
        foreach (['a.txt', 'app/Http/Kernel.php', 'app/Http/Middleware/Trust.php', 'app/Models/User.php', '.env.production', 'z/' . str_repeat('long-name-', 12) . '.txt'] as $path) {
            $this->write($repo, $path, $path . "\n");
            $this->git($repo, 'add', '-f', $path);
        }
        $this->git($repo, 'update-index', '--index-version', $version);
        // An extended flags word on one entry (version 3 and up).
        if ($version !== '2') {
            $this->git($repo, 'update-index', '--skip-worktree', 'app/Http/Kernel.php');
        }

        self::assertSame($this->lsFiles($repo), $this->sorted(GitIndex::trackedUnder($repo)));
    }

    public function testAnAppInASubfolderGetsPathsRelativeToItself(): void
    {
        $repo = $this->repo('mono');
        $this->write($repo, 'apps/web/.env.production', "APP_DEBUG=true\n");
        $this->write($repo, 'apps/web/composer.json', '{}');
        $this->write($repo, 'apps/web-other/.env.production', "APP_DEBUG=true\n");
        $this->write($repo, 'README.md', "hi\n");
        $this->git($repo, 'add', '-A');

        self::assertSame(['.env.production', 'composer.json'], $this->sorted(GitIndex::trackedUnder($repo . '/apps/web')));
    }

    public function testAFreshRepositoryListsNothing(): void
    {
        $repo = $this->repo('fresh');
        $this->write($repo, '.env.production', "APP_DEBUG=true\n");

        self::assertSame([], GitIndex::trackedUnder($repo));
    }

    public function testAWorktreeReadsItsOwnIndex(): void
    {
        $repo = $this->repo('main');
        $this->write($repo, 'composer.json', '{}');
        $this->git($repo, 'add', 'composer.json');
        $this->git($repo, '-c', 'user.name=Proctor', '-c', 'user.email=proctor@example.com', 'commit', '-q', '-m', 'first');
        $tree = $this->root . '/tree';
        $this->git($repo, 'worktree', 'add', '-q', $tree);
        $this->write($tree, '.env.production', "APP_DEBUG=true\n");
        $this->write($tree, 'added.txt', "x\n");
        $this->git($tree, 'add', 'added.txt');

        self::assertTrue(is_file($tree . '/.git'));
        self::assertSame(['added.txt', 'composer.json'], $this->sorted(GitIndex::trackedUnder($tree)));
    }

    public function testAGitFileThatPointsNowhereCannotTell(): void
    {
        $dir = $this->root . '/broken';
        mkdir($dir);
        file_put_contents($dir . '/.git', "gitdir: ./gone\n");

        self::assertNull(GitIndex::trackedUnder($dir));
    }

    public function testAMissingFolderCannotTell(): void
    {
        self::assertNull(GitIndex::trackedUnder($this->root . '/nope'));
    }

    public function testAnIndexThatIsNotAnIndexCannotTell(): void
    {
        $repo = $this->repo('junk');
        file_put_contents($repo . '/.git/index', 'not an index at all');

        self::assertNull(GitIndex::trackedUnder($repo));
    }

    private function repo(string $name): string
    {
        $dir = $this->root . '/' . $name;
        mkdir($dir, 0777, true);
        $this->git($dir, 'init', '-q');

        return $dir;
    }

    private function git(string $dir, string ...$args): string
    {
        $result = (new ProcessRunner())->run(array_merge([$this->git, '-C', $dir], $args), $dir);
        self::assertSame(0, $result->exitCode, implode(' ', $args) . ': ' . $result->stderr);

        return $result->stdout;
    }

    /** @return list<string> */
    private function lsFiles(string $dir): array
    {
        $files = array_values(array_filter(explode("\0", $this->git($dir, 'ls-files', '-z')), static fn(string $p): bool => $p !== ''));
        sort($files);

        return $files;
    }

    /**
     * @param array<string, true>|null $tracked
     *
     * @return list<string>
     */
    private function sorted(?array $tracked): array
    {
        self::assertNotNull($tracked);
        $files = array_map('strval', array_keys($tracked));
        sort($files);

        return $files;
    }

    private function write(string $dir, string $path, string $contents): void
    {
        $full = $dir . '/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, $contents);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            @chmod($path, 0777);
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @chmod($path, 0666);
            unlink($path);
        }
    }
}
