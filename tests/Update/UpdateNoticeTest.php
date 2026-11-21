<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Update;

use Waynestate\Proctor\Tests\ProctorTestCase;
use Waynestate\Proctor\Update\InstallKind;
use Waynestate\Proctor\Update\LatestReleaseFetcher;
use Waynestate\Proctor\Update\PackagistFetcher;
use Waynestate\Proctor\Update\UpdateCache;
use Waynestate\Proctor\Update\UpdateNotice;

/**
 * The update notice: one stderr line when a newer stable release exists,
 * with the command that updates this install. Every test uses an injected
 * fetcher and clock and a temp cache dir, so nothing here touches the
 * network or the real cache.
 */
final class UpdateNoticeTest extends ProctorTestCase
{
    private const NOW = 1_763_000_000;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    public function testLineForPhar(): void
    {
        // PROCTOR ships a PHAR (box.json.dist), so a PHAR copy downloads
        // the newest release asset: the single-file-download one-liner the
        // README prints, where <release> is the base URL of the tag's
        // asset links.
        $env = $this->env();

        [$notice, $fetcher] = $this->notice(InstallKind::Phar, [['version' => '0.6.0']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame(
            "proctor 0.5.0 is out of date: 0.6.0 is the newest release. Update: curl -fsSL -o proctor.phar \"<release>/proctor.phar\" -o proctor.phar.sha256 \"<release>/proctor.phar.sha256\" && sha256sum -c proctor.phar.sha256 && php proctor.phar --version\n",
            $sink['text'],
        );
        self::assertSame(1, $fetcher->calls);
    }

    public function testLineForSourceCheckout(): void
    {
        // A checkout with a .git of its own (folder or file) outside any
        // vendor/ folder is a source checkout.
        $root = $this->makeTempDir() . '/checkout';
        mkdir($root . '/bin', 0777, true);
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/.git', "# worktree gitdir marker\n");
        $env = $this->env();

        self::assertSame(InstallKind::SourceCheckout, InstallKind::detect($root, $env));

        [$notice] = $this->notice(InstallKind::SourceCheckout, [['version' => '0.6.0']], self::NOW, $root);
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame("proctor 0.5.0 is out of date: 0.6.0 is the newest release. Update: git pull\n", $sink['text']);
    }

    public function testLineForComposerGlobal(): void
    {
        // <global home>/vendor/waynestate/proctor, with the global home
        // named by COMPOSER_HOME.
        $home = $this->makeTempDir() . '/composer-home';
        $root = $home . '/vendor/waynestate/proctor';
        mkdir($root . '/bin', 0777, true);
        $env = $this->env(['COMPOSER_HOME' => $home]);

        self::assertSame(InstallKind::ComposerGlobal, InstallKind::detect($root, $env));

        [$notice] = $this->notice(InstallKind::ComposerGlobal, [['version' => '0.6.0']], self::NOW, $root);
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame(
            "proctor 0.5.0 is out of date: 0.6.0 is the newest release. Update: composer global update waynestate/proctor\n",
            $sink['text'],
        );
    }

    public function testLineForProjectDependency(): void
    {
        // <project>/vendor/waynestate/proctor, a project that is not
        // Composer's global home, pinning a constraint that still allows the
        // newest release.
        $project = $this->makeTempDir() . '/app';
        $root = $project . '/vendor/waynestate/proctor';
        mkdir($root . '/bin', 0777, true);
        file_put_contents($project . '/composer.json', (string) json_encode([
            'require' => ['waynestate/proctor' => '^0.5'],
        ], JSON_PRETTY_PRINT));
        $env = $this->env();

        self::assertSame(InstallKind::ProjectDependency, InstallKind::detect($root, $env));

        [$notice] = $this->notice(InstallKind::ProjectDependency, [['version' => '0.5.3']], self::NOW, $root);
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame(
            "proctor 0.5.0 is out of date: 0.5.3 is the newest release. Update: composer update waynestate/proctor\n",
            $sink['text'],
        );
    }

    public function testLineForPinnedProjectDependency(): void
    {
        // A ^ or ~ pin that excludes the newest release switches the line to
        // a require of the newest major.minor; --dev only when the pin sits
        // in require-dev.
        $project = $this->makeTempDir() . '/app';
        $root = $project . '/vendor/waynestate/proctor';
        mkdir($root . '/bin', 0777, true);
        file_put_contents($project . '/composer.json', (string) json_encode([
            'require' => ['waynestate/proctor' => '^0.5'],
        ], JSON_PRETTY_PRINT));
        $env = $this->env();

        [$notice] = $this->notice(InstallKind::ProjectDependency, [['version' => '0.6.0']], self::NOW, $root);
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame(
            "proctor 0.5.0 is out of date: 0.6.0 is the newest release. Update: composer require waynestate/proctor:^0.6\n",
            $sink['text'],
        );

        // The same pin in require-dev adds --dev.
        file_put_contents($project . '/composer.json', (string) json_encode([
            'require-dev' => ['waynestate/proctor' => '~0.5'],
        ], JSON_PRETTY_PRINT));
        $env = $this->env();

        [$notice] = $this->notice(InstallKind::ProjectDependency, [['version' => '0.6.0']], self::NOW, $root);
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame(
            "proctor 0.5.0 is out of date: 0.6.0 is the newest release. Update: composer require waynestate/proctor:^0.6 --dev\n",
            $sink['text'],
        );
    }

    public function testLineForUnknownInstall(): void
    {
        // No .git, not under any vendor/: unknown, which falls back to the
        // Composer global command.
        $root = $this->makeTempDir() . '/loose';
        mkdir($root . '/bin', 0777, true);
        $env = $this->env();

        self::assertSame(InstallKind::Unknown, InstallKind::detect($root, $env));

        [$notice] = $this->notice(InstallKind::Unknown, [['version' => '0.6.0']], self::NOW, $root);
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame(
            "proctor 0.5.0 is out of date: 0.6.0 is the newest release. Update: composer global update waynestate/proctor\n",
            $sink['text'],
        );
    }

    public function testCachedResultMakesNoFetch(): void
    {
        // A cache checked within the last day is printed from, without a
        // fetch, even when the fetcher would answer differently.
        $env = $this->env();
        $this->seedCache($env, self::NOW, '0.6.0');

        [$notice, $fetcher] = $this->notice(InstallKind::SourceCheckout, [['version' => '9.9.9']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame("proctor 0.5.0 is out of date: 0.6.0 is the newest release. Update: git pull\n", $sink['text']);
        self::assertSame(0, $fetcher->calls);
    }

    public function testStaleCacheFetchesOnce(): void
    {
        // A cache older than a day fetches once and rewrites the file.
        $env = $this->env();
        $path = $this->seedCache($env, self::NOW - 86401, '0.5.0');

        [$notice, $fetcher] = $this->notice(InstallKind::SourceCheckout, [['version' => '0.6.0']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame("proctor 0.5.0 is out of date: 0.6.0 is the newest release. Update: git pull\n", $sink['text']);
        self::assertSame(1, $fetcher->calls);
        self::assertSame(['checked_at' => self::NOW, 'latest' => '0.6.0'], (new UpdateCache('proctor'))->read($path));
    }

    public function testOfflineIsSilent(): void
    {
        // No cache and a failed fetch: nothing is printed, and the empty
        // result is stamped so the next command does not try again for a
        // day.
        $env = $this->env();
        $path = (new UpdateCache('proctor'))->path($env);

        [$notice, $fetcher] = $this->notice(InstallKind::SourceCheckout, null, self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame('', $sink['text']);
        self::assertSame(1, $fetcher->calls);
        self::assertSame(['checked_at' => self::NOW, 'latest' => null], (new UpdateCache('proctor'))->read($path));
    }

    public function testMalformedJsonIsSilent(): void
    {
        // Malformed p2 bodies parse to null, and a null fetch stays silent
        // with the stamp refreshed.
        self::assertNull(PackagistFetcher::parse('not json'));
        self::assertNull(PackagistFetcher::parse('{"packages": {}}'));
        self::assertNull(PackagistFetcher::parse('{"packages": {"waynestate/proctor": "nope"}}'));
        self::assertSame(
            [['version' => '0.6.0']],
            PackagistFetcher::parse('{"packages": {"waynestate/proctor": [{"version": "0.6.0"}]}}'),
        );

        $env = $this->env();
        $path = (new UpdateCache('proctor'))->path($env);

        [$notice, $fetcher] = $this->notice(InstallKind::SourceCheckout, null, self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame('', $sink['text']);
        self::assertSame(1, $fetcher->calls);
        self::assertSame(['checked_at' => self::NOW, 'latest' => null], (new UpdateCache('proctor'))->read($path));
    }

    public function testCiEnvIsSilent(): void
    {
        // CI set to anything other than empty, 0 or false stays silent
        // before the cache is even read.
        $env = $this->env(['CI' => '1']);
        $this->seedCache($env, self::NOW, '0.6.0');

        [$notice, $fetcher] = $this->notice(InstallKind::SourceCheckout, [['version' => '9.9.9']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame('', $sink['text']);
        self::assertSame(0, $fetcher->calls);
    }

    public function testOptOutEnvIsSilent(): void
    {
        $env = $this->env(['WAYNE_NO_UPDATE_CHECK' => '1']);
        $this->seedCache($env, self::NOW, '0.6.0');

        [$notice, $fetcher] = $this->notice(InstallKind::SourceCheckout, [['version' => '9.9.9']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame('', $sink['text']);
        self::assertSame(0, $fetcher->calls);
    }

    public function testQuietIsSilent(): void
    {
        $env = $this->env();
        $this->seedCache($env, self::NOW, '0.6.0');

        [$notice, $fetcher] = $this->notice(InstallKind::SourceCheckout, [['version' => '9.9.9']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check', '--quiet'], '0.5.0', $write);

        self::assertSame('', $sink['text']);
        self::assertSame(0, $fetcher->calls);

        // The short form is quiet too.
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check', '-q'], '0.5.0', $write);

        self::assertSame('', $sink['text']);
    }

    public function testStdoutUntouchedByJsonFormat(): void
    {
        // PROCTOR has --format=json, so the contract's requirement is that
        // the JSON report on stdout is byte-identical with the check on and
        // off. Run the real binary twice, copied into a project-dependency
        // layout: once opted out, once with a fresh seeded cache, so the
        // notice prints from the cache and never touches the network. CI
        // and the opt-out are pinned to empty so an ambient environment
        // cannot silence the check.
        $home = $this->makeTempDir();
        $bin = $home . '/vendor/waynestate/proctor/bin';
        mkdir($bin, 0777, true);
        copy(dirname(__DIR__, 2) . '/bin/proctor', $bin . '/proctor');
        file_put_contents($home . '/vendor/autoload.php', "<?php require '" . dirname(__DIR__, 2) . "/vendor/autoload.php';\n");

        // 0.18.0 stays on the same major as the running 0.17.0, so the line
        // carries no upgrading link.
        $cacheDir = $home . '/cache';
        $file = $cacheDir . '/waynestate/proctor/update-check.json';
        mkdir(dirname($file), 0777, true);
        file_put_contents($file, json_encode(['checked_at' => time(), 'latest' => '0.18.0']) . "\n");

        $args = [
            'check',
            '--path=' . $this->app('pass-app'),
            '--format=json',
            '--policy=' . __DIR__ . '/../fixtures/policy.json',
            '--today=' . self::TODAY,
        ];
        $off = $this->runCopied($bin . '/proctor', $args, ['CI' => '', 'WAYNE_NO_UPDATE_CHECK' => '1', 'XDG_CACHE_HOME' => $cacheDir]);
        $on = $this->runCopied($bin . '/proctor', $args, ['CI' => '', 'WAYNE_NO_UPDATE_CHECK' => '', 'XDG_CACHE_HOME' => $cacheDir]);

        self::assertSame($off['exit'], $on['exit'], "stderr: {$on['stderr']}");
        self::assertSame($off['stdout'], $on['stdout'], "stderr: {$on['stderr']}");

        // The notice went to stderr, on one line, after the report. The
        // copied layout is a project dependency without a composer.json, so
        // the line ends with the plain update command.
        $lines = array_values(array_filter(explode("\n", $on['stderr']), static fn(string $line): bool => $line !== ''));
        self::assertNotEmpty($lines, "stderr: {$on['stderr']}");
        self::assertSame(
            'proctor 0.17.0 is out of date: 0.18.0 is the newest release. Update: composer update waynestate/proctor',
            $lines[count($lines) - 1],
        );
    }

    public function testNormalisesVPrefix(): void
    {
        // Tags may start with v or not: both normalise, and the line prints
        // the version without the v.
        $env = $this->env();
        $entries = [
            ['version' => 'v0.6.0'],
            ['version' => '0.5.9'],
            ['version' => 'v0.5.0'],
        ];

        [$notice] = $this->notice(InstallKind::SourceCheckout, $entries, self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame("proctor 0.5.0 is out of date: 0.6.0 is the newest release. Update: git pull\n", $sink['text']);
    }

    public function testIgnoresPrereleases(): void
    {
        // dev, alpha, beta and rc versions (any case) are dropped, so the
        // line names the highest stable release.
        $env = $this->env();
        $entries = [
            ['version' => '1.0.0-beta1'],
            ['version' => '1.0.0-RC1'],
            ['version' => 'dev-main'],
            ['version' => '0.6.0-alpha'],
            ['version' => '0.5.6-BETA'],
            ['version' => '0.5.5'],
        ];

        [$notice] = $this->notice(InstallKind::SourceCheckout, $entries, self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame("proctor 0.5.0 is out of date: 0.5.5 is the newest release. Update: git pull\n", $sink['text']);
    }

    public function testMajorBumpAddsUpgradingLink(): void
    {
        $env = $this->env();

        [$notice] = $this->notice(InstallKind::SourceCheckout, [['version' => '1.0.0']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame(
            "proctor 0.5.0 is out of date: 1.0.0 is the newest release. Update: git pull Upgrading: https://proctor.apps.wayne.edu/docs/upgrading/\n",
            $sink['text'],
        );

        // A minor bump stays on the same major and adds no link.
        $env = $this->env();

        [$notice] = $this->notice(InstallKind::SourceCheckout, [['version' => '0.9.0']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame("proctor 0.5.0 is out of date: 0.9.0 is the newest release. Update: git pull\n", $sink['text']);
    }

    public function testNotNewerIsSilent(): void
    {
        // A running version equal to, or newer than, the cached latest,
        // prints nothing.
        $env = $this->env();
        $this->seedCache($env, self::NOW, '0.5.0');

        [$notice] = $this->notice(InstallKind::SourceCheckout, null, self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame('', $sink['text']);

        $env = $this->env();
        $this->seedCache($env, self::NOW, '0.4.9');

        [$notice] = $this->notice(InstallKind::SourceCheckout, null, self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['proctor', 'check'], '0.5.0', $write);

        self::assertSame('', $sink['text']);
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function env(array $extra = []): array
    {
        return array_merge(['XDG_CACHE_HOME' => $this->cacheDir()], $extra);
    }

    private function cacheDir(): string
    {
        return $this->makeTempDir() . '/cache';
    }

    /**
     * @param array<string, string> $env
     */
    private function seedCache(array $env, int $checkedAt, ?string $latest): string
    {
        $cache = new UpdateCache('proctor');
        $path = $cache->path($env);
        self::assertTrue($cache->write($path, $checkedAt, $latest));

        return $path;
    }

    /**
     * A stderr sink the notice writes into and the test reads from. It is
     * an object, so the injected closure and the test see the same buffer
     * (an array returned alongside would be a copy), and it answers to
     * $sink['text'], so the assertions read it like the old array did.
     *
     * @return array{0: callable(string): void, 1: StderrSink}
     */
    private function stderr(): array
    {
        $sink = new StderrSink();
        $write = static function (string $message) use ($sink): void {
            $sink->append($message);
        };

        return [$write, $sink];
    }

    /**
     * @param list<array<string, mixed>>|null $entries
     * @return array{0: UpdateNotice, 1: FixedFetcher}
     */
    private function notice(InstallKind $kind, ?array $entries, int $now, string $packageRoot): array
    {
        $fetcher = new FixedFetcher($entries);
        $notice = new UpdateNotice(
            'proctor',
            $packageRoot,
            $kind,
            new UpdateCache('proctor'),
            $fetcher,
            static fn(): int => $now,
        );

        return [$notice, $fetcher];
    }

    /**
     * Runs the copied bin/proctor with the given arguments and captures
     * stdout and stderr separately. Like ProctorTestCase, it scrubs the CI
     * markers, PROCTOR_POLICY and friends from the host and gives the child
     * a PATH that finds no tools, so the run is deterministic wherever the
     * suite runs.
     *
     * @param list<string>          $args     the arguments after the binary
     * @param array<string, string> $extraEnv environment variables, merged last
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCopied(string $binary, array $args, array $extraEnv): array
    {
        $scrubbed = ['PATH', 'PROCTOR_POLICY', 'CI', 'GITLAB_CI', 'GITHUB_ACTIONS', 'PROCTOR_ALLOW_OVERRIDES'];
        $env = getenv();
        foreach (array_keys($env) as $key) {
            if (in_array(strtoupper((string) $key), $scrubbed, true)) {
                unset($env[$key]);
            }
        }
        $env['PATH'] = __DIR__ . '/../fixtures/no-tools';
        foreach ($extraEnv as $key => $value) {
            $env[(string) $key] = (string) $value;
        }

        $process = proc_open([PHP_BINARY, $binary, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            self::fail('could not start the copied bin/proctor');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/proctor-update-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

/**
 * The shared text buffer behind the update notice's stderr seam. An
 * object, so the injected closure and the test hold the same instance;
 * ArrayAccess, so the tests keep reading it as $sink['text'].
 *
 * @template-implements \ArrayAccess<string, string>
 */
final class StderrSink implements \ArrayAccess
{
    /** @var array<string, string> */
    private array $data = ['text' => ''];

    public function append(string $message): void
    {
        $this->data['text'] .= $message;
    }

    public function offsetGet(mixed $offset): string
    {
        return $this->data[$offset] ?? '';
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->data[$offset] = (string) $value;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }
}

/**
 * A LatestReleaseFetcher that counts its calls and answers with fixed
 * entries, so the tests need no network.
 */
final class FixedFetcher implements LatestReleaseFetcher
{
    public int $calls = 0;

    /**
     * @param list<array<string, mixed>>|null $entries
     */
    public function __construct(private readonly ?array $entries) {}

    public function fetch(): ?array
    {
        $this->calls++;

        return $this->entries;
    }
}
