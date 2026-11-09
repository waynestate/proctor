<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Advisor;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Waynestate\Advisor\Advisor;
use Waynestate\Advisor\Config;
use Waynestate\Advisor\Content\ContentBundle;
use Waynestate\Advisor\Exception\ToolInputError;
use Waynestate\Advisor\Module\ModuleContext;
use Waynestate\Advisor\Playbook\Settings;
use Waynestate\Advisor\Security\PathGuard;
use Waynestate\Advisor\Security\ProcessOutput;
use Waynestate\Advisor\Security\ProcessRunner;
use Waynestate\Advisor\Support\FixedClock;
use Waynestate\Proctor\Advisor\ProctorModule;
use Waynestate\Proctor\Advisor\TrackedEnvFiles;
use Waynestate\Proctor\Check\AppDebugCheck;
use Waynestate\Proctor\Check\CheckRunner;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\ProcessRunner as CheckProcessRunner;
use Waynestate\Proctor\Code\RepoFiles;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Policy\PolicyResolver;

/**
 * The ADVISOR module against fixture apps built at run time, with a process
 * runner that fails loudly if a tool ever asks ADVISOR to start one, and a
 * read of every in-process check's own source so none of them spawns behind
 * the module's back. No peeking at the answer key.
 */
final class ProctorModuleTest extends TestCase
{
    private const TODAY = '2026-11-07';

    /**
     * PROCTOR classes a check may import without their source being read,
     * each with the call the check must then not make. policy-age reads
     * PolicyResolver's ORIGIN_ constants; resolve() reads PROCTOR_POLICY, and
     * the module builds its ResolvedPolicy itself.
     */
    private const IMPORT_EXEMPT = [PolicyResolver::class => 'PolicyResolver::resolve'];

    private string $root;

    /** How many times a tool asked the process runner for a subprocess. */
    private int $spawns = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/proctor-advisor-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->spawns = 0;
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        self::assertSame(0, $this->spawns, 'A PROCTOR tool asked ADVISOR to start a subprocess.');
    }

    public function testTheModuleDeclaresTwoReadOnlyToolsInItsOwnGroup(): void
    {
        $module = $this->module();

        self::assertSame('proctor', $module->id());
        self::assertSame(['proctor_rules', 'proctor_check'], array_map(static fn($t) => $t->name, $module->tools()));
        foreach ($module->tools() as $tool) {
            self::assertTrue($tool->readOnly, $tool->name . ' must be declared read-only');
            self::assertTrue($tool->idempotent, $tool->name . ' must be idempotent');
            self::assertFalse($tool->openWorld, $tool->name . ' must not reach the network');
            self::assertSame('proctor', $tool->group);
            self::assertSame('object', $tool->inputSchema['type']);
            self::assertFalse($tool->inputSchema['additionalProperties']);
            self::assertStringEndsWith('PROCTOR decides.', $tool->description);
            self::assertStringContainsString('No subprocess, no network, nothing written', $tool->description);
            self::assertStringContainsString('never reads .env', $tool->description);
            self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $tool->description . $tool->title);
        }
        self::assertArrayNotHasKey('properties', $module->tools()[0]->inputSchema);
        self::assertSame(['path'], array_keys($module->tools()[1]->inputSchema['properties']));
        self::assertStringContainsString('inside the project', $module->tools()[1]->description);
        self::assertSame([], $module->resources());
        self::assertSame([], $module->prompts());
    }

    public function testRulesListsEveryRegisteredCheckInOrderWithItsGateFlag(): void
    {
        $result = $this->rules();
        $ids = CheckSet::ids(CheckSet::all(PolicyResolver::resolve(null, $this->root)));

        self::assertSame('proctor', $result['tool']);
        self::assertSame($ids, array_column($result['rules'], 'id'));
        foreach ($result['rules'] as $rule) {
            self::assertSame('proctor/' . $rule['id'], $rule['rule_id']);
            self::assertSame(in_array($rule['id'], CheckSet::GATE, true), $rule['in_gate'], $rule['id']);
            self::assertStringStartsWith('https://proctor.apps.wayne.edu/docs/guides/', $rule['docs']);
        }
        $gated = array_column(array_filter($result['rules'], static fn(array $r): bool => $r['in_gate']), 'id');
        self::assertSame(CheckSet::GATE, array_values($gated));

        self::assertIsString($result['proctor_version']);
        self::assertNotSame('', $result['proctor_version']);
        self::assertStringContainsString('src/Check/CheckSet.php', $result['source']['code']);
        self::assertStringEndsWith('PROCTOR decides.', $result['notice']);
    }

    public function testEveryDocsLinkLandsOnAHeadingThatExists(): void
    {
        $docs = dirname(__DIR__, 2) . '/docs/';
        foreach ($this->rules()['rules'] as $rule) {
            $page = substr($rule['docs'], strlen(ProctorModule::DOCS));
            [$file, $anchor] = array_pad(explode('#', $page, 2), 2, null);
            $markdown = $docs . substr((string) $file, 0, -strlen('.html')) . '.md';
            self::assertFileExists($markdown, $rule['id']);
            if ($anchor !== null) {
                self::assertMatchesRegularExpression(
                    '/^## ' . preg_quote($anchor, '/') . '$/m',
                    (string) file_get_contents($markdown),
                    $rule['id'] . ' has no heading on ' . $file,
                );
            }
        }
    }

    public function testCheckFailsADestructiveDeployScript(): void
    {
        $app = $this->app('bad-app', ['name' => 'acme/bad', 'require' => ['php' => '^8.3']]);
        file_put_contents($app . '/build.sh', "#!/bin/sh\nphp artisan migrate:fresh --force\n");
        $before = $this->snapshot();

        $result = $this->check(['path' => 'bad-app']);

        self::assertSame('proctor', $result['tool']);
        self::assertSame('bad-app', $result['app']);
        self::assertSame('fail', $result['verdict']);
        $fails = array_values(array_filter($result['findings'], static fn(array $f): bool => $f['status'] === 'fail'));
        self::assertNotEmpty($fails);
        self::assertContains('proctor/destructive-deploy', array_column($fails, 'rule_id'));
        $deploy = $fails[array_search('proctor/destructive-deploy', array_column($fails, 'rule_id'), true)];
        self::assertSame('build.sh', $deploy['file']);
        self::assertSame(2, $deploy['line']);
        self::assertStringContainsString('migrate:fresh', $deploy['message']);
        self::assertGreaterThanOrEqual(1, $result['counts']['fail']);
        self::assertSame(count($result['findings']), array_sum($result['counts']));
        self::assertSame(['pass', 'info', 'warning', 'fail'], array_keys($result['counts']));

        self::assertStringContainsString('in-process', $result['ran']);
        self::assertStringContainsString('no .env read', $result['ran']);
        self::assertStringContainsString(self::TODAY, $result['ran']);
        self::assertSame(self::TODAY, $result['source']['date']);
        self::assertStringEndsWith('PROCTOR decides.', $result['notice']);
        self::assertSame($before, $this->snapshot(), 'proctor_check must write nothing.');
    }

    public function testACleanAppHasNoFail(): void
    {
        $this->cleanApp();

        $result = $this->check(['path' => 'clean-app']);

        self::assertSame('pass', $result['verdict'], json_encode($result['findings'], JSON_PRETTY_PRINT) ?: '');
        self::assertSame(0, $result['counts']['fail']);
        self::assertNotContains('fail', array_column($result['findings'], 'status'));
    }

    public function testTheRootItselfIsTheDefaultPath(): void
    {
        file_put_contents($this->root . '/composer.json', '{"name": "acme/root", "require": {"php": "^8.3"}}');

        $result = $this->check([]);

        self::assertSame('.', $result['app']);
    }

    public function testSubprocessAndEnvChecksAreSkippedAndListed(): void
    {
        $this->cleanApp();
        $result = $this->check(['path' => 'clean-app']);

        $skipped = array_column($result['skipped'], 'reason', 'id');
        self::assertSame('starts a subprocess', $skipped['composer-audit'] ?? null);
        self::assertSame('reads .env', $skipped['app-key'] ?? null);
        self::assertSame(ProctorModule::SKIPPED, $skipped);

        $ran = array_unique(array_map(
            static fn(array $f): string => explode('/', $f['rule_id'])[1],
            $result['findings'],
        ));
        self::assertSame([], array_values(array_intersect($ran, array_keys(ProctorModule::SKIPPED))));
    }

    public function testEverySkippedIdIsARegisteredCheck(): void
    {
        $ids = CheckSet::ids(CheckSet::all(PolicyResolver::resolve(null, $this->root)));

        self::assertSame([], array_values(array_diff(array_keys(ProctorModule::SKIPPED), $ids)));
    }

    /**
     * Every check the module runs, and every PROCTOR class it imports, must
     * stay in-process: no ProcessRunner, no RepoFiles (it runs git), no
     * process or network function, no environment read and no .env file. A
     * new check that needs any of them goes in ProctorModule::SKIPPED.
     */
    public function testTheChecksTheModuleRunsNeverSpawnOrReadTheEnvironment(): void
    {
        $patterns = [
            '/\b(ProcessRunner|ProcessRunnerInterface|RepoFiles)\b/',
            '/(?<![\w>:$])(proc_open|shell_exec|passthru|popen|exec|system|pcntl_exec|getenv|putenv|curl_init|fsockopen|stream_socket_client)\s*\(/',
            '/\$_(ENV|SERVER)\b/',
            '/[\'"]\.env[\'"]/',
        ];
        $resolved = PolicyResolver::resolve(null, $this->root);
        $seen = [];
        foreach (CheckSet::all($resolved) as $check) {
            if (array_key_exists($check->id(), ProctorModule::SKIPPED)) {
                continue;
            }
            $file = (string) (new ReflectionClass($check))->getFileName();
            foreach (self::IMPORT_EXEMPT as $call) {
                self::assertStringNotContainsString($call, (string) file_get_contents($file), $check->id());
            }
            foreach ([$file, ...$this->proctorImports($file)] as $source) {
                if (isset($seen[$source])) {
                    continue;
                }
                $seen[$source] = true;
                $code = (string) file_get_contents($source);
                foreach ($patterns as $pattern) {
                    self::assertDoesNotMatchRegularExpression(
                        $pattern,
                        $code,
                        sprintf('%s (run by the module for %s) may spawn or read the environment; list it in ProctorModule::SKIPPED.', basename($source), $check->id()),
                    );
                }
            }
        }
        self::assertNotEmpty($seen);
    }

    public function testAnUntrackedEnvProductionIsNotReadByAppDebug(): void
    {
        $app = $this->gitApp('debug-app', ".env.production", "APP_NAME=Acme\nAPP_DEBUG=true\n", track: false);

        $result = $this->check(['path' => 'debug-app']);

        self::assertNotContains('proctor/app-debug/enabled', array_column($result['findings'], 'rule_id'));
        self::assertContains('proctor/app-debug', array_column($result['findings'], 'rule_id'));
        self::assertContains(
            ['id' => 'app-debug', 'file' => '.env.production', 'reason' => TrackedEnvFiles::REASON_UNTRACKED],
            $result['skipped_files'],
        );
        self::assertFileExists($app . '/.env.production');
    }

    public function testATrackedEnvProductionIsGradedByAppDebug(): void
    {
        $this->gitApp('debug-app', ".env.production", "APP_NAME=Acme\nAPP_DEBUG=true\n", track: true);

        $result = $this->check(['path' => 'debug-app']);

        $debug = array_values(array_filter($result['findings'], static fn(array $f): bool => $f['rule_id'] === 'proctor/app-debug/enabled'));
        self::assertCount(1, $debug);
        self::assertSame('warning', $debug[0]['status']);
        self::assertSame('.env.production', $debug[0]['file']);
        self::assertSame(2, $debug[0]['line']);
        self::assertSame([], array_values(array_filter($result['skipped_files'], static fn(array $s): bool => $s['file'] === '.env.production')));
    }

    public function testAnUntrackedEnvProductionIsNotReadByCspUnsafeInline(): void
    {
        $this->gitApp('csp-app', ".env.production", "HELMET_CSP=default-src 'self'; script-src 'self' 'unsafe-inline'\n", track: false);

        $result = $this->check(['path' => 'csp-app']);

        self::assertNotContains('proctor/csp-unsafe-inline/script-src', array_column($result['findings'], 'rule_id'));
        self::assertContains('proctor/csp-unsafe-inline', array_column($result['findings'], 'rule_id'));
        self::assertContains(
            ['id' => 'csp-unsafe-inline', 'file' => '.env.production', 'reason' => TrackedEnvFiles::REASON_UNTRACKED],
            $result['skipped_files'],
        );
    }

    public function testATrackedEnvProductionIsGradedByCspUnsafeInline(): void
    {
        $this->gitApp('csp-app', ".env.production", "HELMET_CSP=default-src 'self'; script-src 'self' 'unsafe-inline'\n", track: true);

        $result = $this->check(['path' => 'csp-app']);

        $csp = array_values(array_filter($result['findings'], static fn(array $f): bool => $f['rule_id'] === 'proctor/csp-unsafe-inline/script-src'));
        self::assertCount(1, $csp);
        self::assertSame('.env.production', $csp[0]['file']);
        self::assertSame(1, $csp[0]['line']);
        self::assertNotContains('csp-unsafe-inline', array_column($result['skipped_files'], 'id'));
    }

    public function testAnUntrackedEnvExampleIsNotReadByTheMysqlChecks(): void
    {
        $this->gitApp('db-app', '.env.example', "SQUIRE_MYSQL_IMAGE=mysql:5.6\n", track: false);

        $result = $this->check(['path' => 'db-app']);

        foreach ($result['findings'] as $finding) {
            self::assertNotSame('.env.example', $finding['file'], $finding['rule_id']);
        }
        $asked = array_column(array_filter($result['skipped_files'], static fn(array $s): bool => $s['file'] === '.env.example'), 'id');
        // mysql-minimum reads the same files through the same code, but only for a Laravel app.
        self::assertContains('mysql-eol', $asked);
        self::assertContains('csp-unsafe-inline', $asked);
    }

    public function testAnAppOutsideAnyRepositoryReadsNoEnvFile(): void
    {
        $app = $this->cleanApp();
        file_put_contents($app . '/.env.production', "APP_DEBUG=true\n");
        // A .git file whose gitdir is gone: there is no index to read.
        file_put_contents($app . '/.git', "gitdir: ./missing-git-dir\n");

        $result = $this->check(['path' => 'clean-app']);

        self::assertNotContains('proctor/app-debug/enabled', array_column($result['findings'], 'rule_id'));
        self::assertContains(
            ['id' => 'app-debug', 'file' => '.env.production', 'reason' => TrackedEnvFiles::REASON_NO_INDEX],
            $result['skipped_files'],
        );
    }

    public function testACleanAppSkipsNoFile(): void
    {
        $this->cleanApp();

        self::assertSame([], $this->check(['path' => 'clean-app'])['skipped_files']);
    }

    /**
     * The CLI passes no EnvFileFilter, so app-debug still reads an untracked
     * .env.production there, as it did before.
     */
    public function testTheCliPathStillReadsAnUntrackedEnvProduction(): void
    {
        $app = $this->gitApp('debug-app', ".env.production", "APP_DEBUG=true\n", track: false);

        $findings = (new CheckRunner($app, Policy::bundled(), new DateTimeImmutable(self::TODAY), [new AppDebugCheck()]))->run();

        self::assertSame(['proctor/app-debug/enabled'], array_map(static fn($f): string => $f->ruleId, $findings));
    }

    public function testAPathOutsideTheRootIsRefused(): void
    {
        $this->expectException(ToolInputError::class);
        $this->check(['path' => '../']);
    }

    public function testAnAbsolutePathOutsideTheRootIsRefused(): void
    {
        $outside = sys_get_temp_dir() . '/proctor-advisor-outside-' . bin2hex(random_bytes(6));
        mkdir($outside);
        try {
            $this->expectException(ToolInputError::class);
            $this->check(['path' => $outside]);
        } finally {
            rmdir($outside);
        }
    }

    public function testAFileIsNotAnAppFolder(): void
    {
        file_put_contents($this->root . '/notes.txt', 'not an app');

        $this->expectException(ToolInputError::class);
        $this->expectExceptionMessage('directory');
        $this->check(['path' => 'notes.txt']);
    }

    public function testAProctorToolErrorBecomesAToolInputErrorWithoutTheHostPath(): void
    {
        $app = $this->app('broken-app');
        file_put_contents($app . '/composer.json', '{not json');

        try {
            $this->check(['path' => 'broken-app']);
            self::fail('A malformed composer.json must be refused.');
        } catch (ToolInputError $e) {
            self::assertStringContainsString('not valid JSON', $e->getMessage());
            self::assertStringNotContainsString($this->root, $e->getMessage());
            self::assertStringNotContainsString((string) realpath($this->root), $e->getMessage());
        }
    }

    public function testOverridesApplyAndAnOverrideForASkippedCheckIsNotCalledUnused(): void
    {
        $app = $this->app('override-app', ['name' => 'acme/override', 'require' => ['php' => '^8.3']]);
        file_put_contents($app . '/build.sh', "php artisan db:wipe\n");
        file_put_contents($app . '/policy-overrides.json', json_encode([
            ['check' => 'destructive-deploy', 'reason' => 'test fixture', 'expires' => '2027-01-31', 'approved_by' => 'tester@example.wayne.edu'],
            ['check' => 'composer-audit', 'reason' => 'test fixture', 'expires' => '2027-01-31', 'approved_by' => 'tester@example.wayne.edu'],
        ], JSON_THROW_ON_ERROR));

        $result = $this->check(['path' => 'override-app']);

        $deploy = array_values(array_filter($result['findings'], static fn(array $f): bool => $f['rule_id'] === 'proctor/destructive-deploy'));
        self::assertSame('warning', $deploy[0]['status'] ?? null);
        foreach ($result['findings'] as $finding) {
            self::assertStringNotContainsString('"composer-audit"', $finding['message']);
        }
        self::assertSame('override-app/policy-overrides.json', $result['source']['overrides']);
    }

    private function module(): ProctorModule
    {
        $test = $this;
        $processes = new class ($test) implements ProcessRunner {
            public function __construct(private readonly ProctorModuleTest $test) {}

            public function run(array $argv, string $cwd, int $timeoutSeconds): ProcessOutput
            {
                $this->test->spawned();

                throw new RuntimeException('A PROCTOR tool started a subprocess; the tools must run in-process.');
            }
        };

        return new ProctorModule(new ModuleContext(
            Config::fromEnv(['ADVISOR_ENABLED' => '1', 'ADVISOR_PROJECT_ROOT' => $this->root]),
            new ContentBundle(dirname((string) (new ReflectionClass(Advisor::class))->getFileName()) . '/../data'),
            new FixedClock(self::TODAY),
            new PathGuard($this->root),
            $processes,
            new Settings(),
        ));
    }

    /** Called by the fake process runner. */
    public function spawned(): void
    {
        $this->spawns++;
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return ($this->module()->tools()[0]->handler)([]);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function check(array $args): array
    {
        return ($this->module()->tools()[1]->handler)($args);
    }

    /** @param array<string, mixed>|null $composer */
    private function app(string $name, ?array $composer = null): string
    {
        $dir = $this->root . '/' . $name;
        mkdir($dir, 0777, true);
        if ($composer !== null) {
            file_put_contents($dir . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return $dir;
    }

    /**
     * A valid composer.json and its lock: lock-committed fails an app with
     * no composer.lock, so a clean app commits one.
     */
    private function cleanApp(): string
    {
        $app = $this->app('clean-app', ['name' => 'acme/clean', 'require' => ['php' => '^8.3']]);
        file_put_contents($app . '/composer.lock', json_encode(['packages' => [], 'packages-dev' => []], JSON_THROW_ON_ERROR));

        return $app;
    }

    /**
     * A clean app in a real git repository of its own, with one env file
     * written to disk and, when $track is true, added to the index. Every
     * other file is added, so only the env file's state differs.
     */
    private function gitApp(string $name, string $envFile, string $contents, bool $track): string
    {
        $git = RepoFiles::findGit();
        if ($git === null) {
            self::markTestSkipped('git is not on PATH');
        }
        $app = $this->app($name, ['name' => 'acme/' . $name, 'require' => ['php' => '^8.3']]);
        file_put_contents($app . '/composer.lock', json_encode(['packages' => [], 'packages-dev' => []], JSON_THROW_ON_ERROR));
        $runner = new CheckProcessRunner();
        foreach ([['init', '-q'], ['add', 'composer.json', 'composer.lock']] as $args) {
            self::assertSame(0, $runner->run(array_merge([$git, '-C', $app], $args), $app)->exitCode);
        }
        file_put_contents($app . '/' . $envFile, $contents);
        if ($track) {
            self::assertSame(0, $runner->run([$git, '-C', $app, 'add', '-f', $envFile], $app)->exitCode);
        }

        return $app;
    }

    /**
     * The files of the PROCTOR classes a source file imports.
     *
     * @return list<string>
     */
    private function proctorImports(string $file): array
    {
        preg_match_all('/^use (Waynestate\\\\Proctor\\\\[\w\\\\]+);/m', (string) file_get_contents($file), $m);
        $files = [];
        foreach ($m[1] as $class) {
            if (!array_key_exists($class, self::IMPORT_EXEMPT) && (class_exists($class) || interface_exists($class))) {
                /** @var class-string $class */
                $files[] = (string) (new ReflectionClass($class))->getFileName();
            }
        }

        return $files;
    }

    /** @return array<string, string> path to hash, for every file under the root */
    private function snapshot(): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $out[$file->getPathname()] = (string) hash_file('sha256', $file->getPathname()) . '@' . $file->getMTime();
            }
        }
        ksort($out);

        return $out;
    }

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
