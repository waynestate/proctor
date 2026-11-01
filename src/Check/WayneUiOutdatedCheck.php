<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Composer\Semver\Semver;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Proctor\ToolError;

/**
 * Warns when the WayneUI an app carries is behind the latest release.
 *
 * WayneUI releases to Packagist (waynestate/wayne-ui) and npm
 * (@waynestate/wayne-ui-css, @waynestate/wayne-ui-vue), and it also ships a
 * self-contained stylesheet that many apps paste into their own tree. This
 * check reads the installed version from composer.lock, package-lock.json
 * and committed copies of wayne-ui.css, asks composer once per run for the
 * release list (composer show waynestate/wayne-ui --all --format=json, with
 * the app path as cwd), and warns, per source, when the installed version
 * is behind the highest stable release of the same major. Pre-releases
 * (-dev, alpha, beta, RC, rc) and other majors are ignored.
 *
 * A locked WayneUI 1.x (cit-web-infra/wayne-ui) gets its own warning with
 * no latest lookup: 1.x is Bootstrap 3 and predates this comparison.
 *
 * The latest lookup needs the network. When composer is missing, exits
 * non-zero, prints no parsable versions, or no stable release of the
 * installed major exists, the check reports one info finding with the same
 * skipped marker ComposerAuditCheck uses, and nothing else, so an offline
 * run never breaks. An app with nothing WayneUI-related runs no process at
 * all and gets no findings. By default the check warns, not fails: being
 * out of date must not turn a passing app into a failing one. An app that
 * wants a hard stop sets wayneui.copies to fail in its composer.json
 * (extra.proctor."wayneui.copies"); then a behind committed copy is a
 * failure, while composer.lock and package-lock.json still warn, and an
 * offline run never fails in either mode.
 *
 * The proctor marks the paper against the current syllabus, not the one the
 * student photocopied last semester.
 */
final class WayneUiOutdatedCheck implements CheckInterface
{
    /** The Packagist package of WayneUI 2.x. */
    private const PACKAGE = 'waynestate/wayne-ui';

    /** The Packagist package of WayneUI 1.x. */
    private const PACKAGE_V1 = 'cit-web-infra/wayne-ui';

    /**
     * The npm packages released together with the Packagist one, keyed by
     * their key under "packages" in package-lock.json.
     *
     * @var array<string, string> lock key => package name
     */
    private const NPM_PACKAGES = [
        'node_modules/@waynestate/wayne-ui-css' => '@waynestate/wayne-ui-css',
        'node_modules/@waynestate/wayne-ui-vue' => '@waynestate/wayne-ui-vue',
    ];

    /** The exact filename of a pasted stylesheet copy. */
    private const COPY_FILE = 'wayne-ui.css';

    /** How many leading bytes of a copy are read for the version banner. */
    private const BANNER_BYTES = 400;

    /** Directories skipped at any depth. */
    private const SKIP_DIRS = ['node_modules', '.git'];

    /** The default of the strict-copies setting: behind copies warn. */
    private const MODE_WARN = 'warn';

    /** The strict value of the strict-copies setting: behind copies fail. */
    private const MODE_FAIL = 'fail';

    /** The key of the strict-copies setting under extra.proctor in composer.json. */
    private const COPIES_SETTING = 'wayneui.copies';

    /** Appended to a failing copy's message when the setting is fail. */
    private const FAIL_NOTE = "This project fails on stale copies (wayneui.copies = fail); replace the copy from the release, for example with the stack's sync command.";

    public function __construct(
        private readonly ProcessRunnerInterface $process = new ProcessRunner(),
    ) {}

    public function id(): string
    {
        return 'wayneui-outdated';
    }

    public function run(CheckContext $context): array
    {
        // A typo in the setting is a tool error, whatever the app carries.
        $copiesMode = $this->copiesMode($context);

        $findings = [];

        // WayneUI 1.x: one warning, no latest lookup.
        $v1 = $this->lockedVersion($context, self::PACKAGE_V1);
        if ($v1 !== null) {
            $findings[] = new Finding(
                $this->id(),
                Finding::STATUS_WARNING,
                sprintf(
                    'composer.lock holds WayneUI 1.x (%s %s). 1.x is Bootstrap 3 and does not run past Laravel 5.8 without patches. Move to WayneUI 2.x: WayneUI\'s MIGRATING.md lists the steps and Booster\'s WayneUI option plans the move.',
                    self::PACKAGE_V1,
                    $v1,
                ),
                'composer.lock',
            );
        }

        // Every installed 2.x source, each reported separately.
        /** @var list<array{kind: string, name: string, version: string, path: string}> $sources */
        $sources = [];
        $composerVersion = $this->lockedVersion($context, self::PACKAGE);
        if ($composerVersion !== null) {
            $sources[] = ['kind' => 'composer', 'name' => self::PACKAGE, 'version' => $composerVersion, 'path' => 'composer.lock'];
        }
        foreach ($this->npmVersions($context) as $name => $version) {
            $sources[] = ['kind' => 'npm', 'name' => $name, 'version' => $version, 'path' => 'package-lock.json'];
        }
        foreach ($this->committedCopies($context) as $path => $version) {
            $sources[] = ['kind' => 'copy', 'name' => $path, 'version' => $version, 'path' => $path];
        }

        // Nothing WayneUI 2.x in the app: no process at all, no more findings.
        if ($sources === []) {
            return $findings;
        }

        $latestByMajor = $this->latestStableByMajor($context);
        if ($latestByMajor === null) {
            $findings[] = $this->skippedFinding();

            return $findings;
        }

        // Every source needs a stable release of its own major; without one
        // the lookup cannot answer, so it is skipped as a whole.
        foreach ($sources as $source) {
            $major = $this->majorOf($source['version']);
            if ($major === null || !isset($latestByMajor[$major])) {
                $findings[] = $this->skippedFinding();

                return $findings;
            }
        }

        foreach ($sources as $source) {
            $major = (string) $this->majorOf($source['version']);
            $latest = ltrim($latestByMajor[$major], 'v');
            if (!$this->isBehind($source['version'], $latest)) {
                continue;
            }
            // Only a committed copy can fail, and only when the app opted
            // in: lock files stay warnings in both modes.
            $status = $source['kind'] === 'copy' && $copiesMode === self::MODE_FAIL
                ? Finding::STATUS_FAIL
                : Finding::STATUS_WARNING;
            $message = $this->behindMessage($source, $major, $latest);
            if ($status === Finding::STATUS_FAIL) {
                $message .= ' ' . self::FAIL_NOTE;
            }
            $findings[] = new Finding(
                $this->id(),
                $status,
                $message,
                $source['path'],
            );
        }

        return $findings;
    }

    /**
     * The value of the strict-copies setting, read from the app's
     * composer.json the way CiPipelinesCheck reads extra.proctor.github-mirror:
     * under extra.proctor, the key wayneui.copies. Absent means warn. Any
     * present value other than warn or fail is a tool error naming the
     * setting and the allowed values.
     *
     * @throws ToolError when the setting holds a value other than warn or fail
     */
    private function copiesMode(CheckContext $context): string
    {
        $file = $context->appPath . '/composer.json';
        if (!is_file($file)) {
            return self::MODE_WARN;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return self::MODE_WARN;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return self::MODE_WARN;
        }
        $extra = $data['extra'] ?? null;
        if (!is_array($extra)) {
            return self::MODE_WARN;
        }
        $proctor = $extra['proctor'] ?? null;
        if (!is_array($proctor)) {
            return self::MODE_WARN;
        }
        if (!array_key_exists(self::COPIES_SETTING, $proctor)) {
            return self::MODE_WARN;
        }
        $value = $proctor[self::COPIES_SETTING];
        if ($value === self::MODE_WARN || $value === self::MODE_FAIL) {
            return $value;
        }

        throw new ToolError(sprintf(
            'Invalid value %s for the setting wayneui.copies (extra.proctor."wayneui.copies" in composer.json). Allowed values: warn, fail.',
            (string) json_encode($value),
        ));
    }

    /**
     * True when the installed version is behind the latest, compared with
     * composer/semver. An unparseable version is not behind: fail safe, no
     * false warning.
     */
    private function isBehind(string $installed, string $latest): bool
    {
        try {
            return Semver::satisfies($installed, '<' . $latest);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return false;
        }
    }

    /**
     * The warning text for one source that is behind.
     *
     * @param array{kind: string, name: string, version: string, path: string} $source
     */
    private function behindMessage(array $source, string $major, string $latest): string
    {
        return match ($source['kind']) {
            'composer' => sprintf(
                'composer.lock locks WayneUI %s; the latest %s.x is %s. Update with: composer update %s',
                $source['version'],
                $major,
                $latest,
                self::PACKAGE,
            ),
            'npm' => sprintf(
                'package-lock.json locks %s %s; the latest %s.x is %s. Update with: npm update %s',
                $source['name'],
                $source['version'],
                $major,
                $latest,
                $source['name'],
            ),
            default => sprintf(
                '%s is a committed copy of WayneUI %s; the latest %s.x is %s. A pasted copy cannot tell you it is stale: replace it from the release, or link the hosted stylesheet instead of copying it.',
                $source['path'],
                $source['version'],
                $major,
                $latest,
            ),
        };
    }

    /**
     * The single info finding for a failed latest lookup, with the same
     * skipped marker ComposerAuditCheck uses.
     */
    private function skippedFinding(): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_INFO,
            'could not look up the latest WayneUI release (offline or composer unavailable); wayneui-outdated skipped',
            null,
            1,
            null,
            'skipped',
        );
    }

    /**
     * The highest stable release of waynestate/wayne-ui per major, from one
     * "composer show" call with the app path as cwd, or null when the
     * lookup cannot run or yields no usable versions.
     *
     * @return array<string, string>|null major => highest stable version (leading v stripped)
     */
    private function latestStableByMajor(CheckContext $context): ?array
    {
        $composer = ComposerAuditCheck::findComposer();
        if ($composer === null) {
            return null;
        }

        try {
            $result = $this->process->run(
                [$composer, 'show', self::PACKAGE, '--all', '--format=json', '--no-interaction'],
                $context->appPath,
                $context->timeout,
            );
        } catch (ToolError) {
            return null;
        }

        if ($result->exitCode !== 0) {
            return null;
        }

        $decoded = json_decode($result->stdout, true);
        if (!is_array($decoded)) {
            return null;
        }

        $versions = $decoded['versions'] ?? null;
        if (!is_array($versions) || $versions === []) {
            return null;
        }

        $byMajor = [];
        foreach ($versions as $version) {
            if (!is_string($version) || $version === '') {
                continue;
            }
            $plain = ltrim($version, 'v');
            if ($this->isUnstable($plain)) {
                continue;
            }
            $major = $this->majorOf($plain);
            if ($major === null) {
                continue;
            }
            try {
                $higher = !isset($byMajor[$major]) || Semver::satisfies($byMajor[$major], '<' . $plain);
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                continue;
            }
            if ($higher) {
                $byMajor[$major] = $plain;
            }
        }

        return $byMajor === [] ? null : $byMajor;
    }

    /**
     * True for a dev or pre-release version: anything carrying -dev,
     * alpha, beta, RC or rc.
     */
    private function isUnstable(string $version): bool
    {
        foreach (['-dev', 'alpha', 'beta', 'RC', 'rc'] as $marker) {
            if (str_contains($version, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The major of a version (its leading digits), or null when the
     * version has none.
     */
    private function majorOf(string $version): ?string
    {
        if (!preg_match('/^\d+/', $version, $matches)) {
            return null;
        }

        return $matches[0];
    }

    /**
     * The version of one package in composer.lock (packages before
     * packages-dev), with a leading v stripped, or null when the lock file
     * is absent, unreadable, malformed or does not hold the package.
     */
    private function lockedVersion(CheckContext $context, string $package): ?string
    {
        $file = $context->appPath . '/composer.lock';
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $lock = json_decode($raw, true);
        if (!is_array($lock)) {
            return null;
        }

        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $lock[$section] ?? [];
            if (!is_array($packages)) {
                continue;
            }
            foreach ($packages as $entry) {
                if (!is_array($entry) || ($entry['name'] ?? null) !== $package) {
                    continue;
                }
                $version = $entry['version'] ?? null;
                if (is_string($version) && $version !== '') {
                    return ltrim($version, 'v');
                }
            }
        }

        return null;
    }

    /**
     * The versions of the WayneUI npm packages in package-lock.json, keyed
     * by package name, or [] when the lock file is absent, malformed or
     * holds none of them.
     *
     * @return array<string, string>
     */
    private function npmVersions(CheckContext $context): array
    {
        $file = $context->appPath . '/package-lock.json';
        if (!is_file($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return [];
        }

        $lock = json_decode($raw, true);
        if (!is_array($lock)) {
            return [];
        }

        $packages = $lock['packages'] ?? null;
        if (!is_array($packages)) {
            return [];
        }

        $versions = [];
        foreach (self::NPM_PACKAGES as $lockKey => $name) {
            $entry = $packages[$lockKey] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            $version = $entry['version'] ?? null;
            if (is_string($version) && $version !== '') {
                $versions[$name] = $version;
            }
        }

        return $versions;
    }

    /**
     * The committed copies of the stylesheet, keyed by relative path, with
     * the version from their banner, or [] when none carries one.
     *
     * Only committed files count: the git-tracked files when the app path
     * sits in a git repository and git can be run, otherwise the files on
     * disk. node_modules/ and .git/ are skipped at any depth, and so is the
     * app's top-level vendor/ folder.
     *
     * @return array<string, string>
     */
    private function committedCopies(CheckContext $context): array
    {
        $copies = [];
        foreach ($this->committedFiles($context) as $path) {
            if (basename($path) !== self::COPY_FILE) {
                continue;
            }
            $version = $this->bannerVersion($context->appPath . '/' . $path);
            if ($version !== null) {
                $copies[$path] = $version;
            }
        }

        return $copies;
    }

    /**
     * The version in the banner of a stylesheet copy, read from its first
     * 400 bytes, or null when the file is unreadable or carries no banner.
     */
    private function bannerVersion(string $absolutePath): ?string
    {
        if (!is_file($absolutePath)) {
            return null;
        }
        $head = file_get_contents($absolutePath, false, null, 0, self::BANNER_BYTES);
        if ($head === false) {
            return null;
        }
        if (!preg_match('/WayneUI (\d+\.\d+\.\d+)/', $head, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The relative paths (forward slashes) of the committed files: the
     * git-tracked files when the app path is inside a git repository and
     * git can be run, otherwise the files on disk. Skips node_modules/ and
     * .git/ at any depth, and the app's top-level vendor/ folder only.
     *
     * The same walk as WayneUiForkedViewsCheck, which matches folders
     * instead of one filename.
     *
     * @return list<string>
     */
    private function committedFiles(CheckContext $context): array
    {
        $tracked = $this->gitTrackedFiles($context);
        if ($tracked !== null) {
            return $tracked;
        }

        $inner = new RecursiveDirectoryIterator($context->appPath, FilesystemIterator::SKIP_DOTS);
        $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current) use ($context): bool {
            if (! $current->isDir()) {
                return true;
            }
            $relative = str_replace('\\', '/', substr($current->getPathname(), strlen($context->appPath) + 1));
            $segments = explode('/', $relative);
            if (in_array($segments[count($segments) - 1], self::SKIP_DIRS, true)) {
                return false;
            }

            // Skip only the app's top-level vendor/ (Composer's); a deeper
            // vendor segment still counts.
            return $relative !== 'vendor';
        });
        $iterator = new RecursiveIteratorIterator($outer, RecursiveIteratorIterator::LEAVES_ONLY);

        $files = [];
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($context->appPath) + 1));
        }
        sort($files);

        return $files;
    }

    /**
     * The git-tracked file paths (relative to the app path, forward
     * slashes), or null when the app path is not inside a git repository
     * or git cannot be run.
     *
     * @return list<string>|null
     */
    private function gitTrackedFiles(CheckContext $context): ?array
    {
        $git = $this->findGit();
        if ($git === null) {
            return null;
        }

        try {
            $result = $this->process->run(
                [$git, '-C', $context->appPath, 'ls-files', '-z'],
                $context->appPath,
                $context->timeout,
            );
        } catch (ToolError) {
            return null;
        }
        if ($result->exitCode !== 0) {
            return null;
        }

        $files = [];
        foreach (explode("\0", $result->stdout) as $path) {
            if ($path === '' || $this->skipped($path)) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);

        return $files;
    }

    /**
     * Finds the git executable on PATH, or returns null. The same walk as
     * ComposerAuditCheck::findComposer: resolving the path ourselves keeps
     * proc_open from printing a spawn warning into the report when git is
     * absent (the CLI tests run with a PATH that finds no tools).
     */
    private function findGit(): ?string
    {
        $pathEnv = getenv('PATH');
        if ($pathEnv === false || $pathEnv === '') {
            $pathEnv = getenv('Path');
        }
        if ($pathEnv === false || $pathEnv === '') {
            return null;
        }

        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $candidates = $isWindows ? ['git.exe', 'git.cmd', 'git'] : ['git'];

        foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
            if ($dir === '') {
                continue;
            }
            foreach ($candidates as $name) {
                $full = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($full) && ($isWindows || is_executable($full))) {
                    return $full;
                }
            }
        }

        return null;
    }

    /**
     * True when the path sits in a skipped directory: node_modules/ or
     * .git/ at any depth, or the app's top-level vendor/ folder.
     */
    private function skipped(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);
        if ($segments[0] === 'vendor') {
            return true;
        }
        foreach ($segments as $segment) {
            if (in_array($segment, self::SKIP_DIRS, true)) {
                return true;
            }
        }

        return false;
    }
}
